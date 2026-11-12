<?php

namespace App\Controller;

use App\Entity\Formation;
use App\Enum\StatutFormation;
use App\Repository\FormationRepository;
use App\Service\CodesFormation;
use App\Service\HistoriqueFormations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Petite API de rédaction des fiches de formation, pour le skill
 * « creer-formation » : lister, lire, créer un brouillon, réécrire.
 *
 * Elle remplace l'API de SmartOF. Elle ne publie rien : le statut, l'adresse
 * de la page, la mise en page d'accueil et les indicateurs se règlent dans
 * l'admin. Chaque écriture passe par HistoriqueFormations, donc crée une
 * version, comme une modification faite dans l'admin.
 *
 * Accès par jeton (APP_API_JETON, en-tête Authorization: Bearer). Sans
 * jeton configuré, l'API n'existe pas (404).
 */
#[Route('/api/formations', name: 'app_api_formation')]
class ApiFormationController extends AbstractController
{
    /** Les champs de texte que l'API peut écrire tels quels. */
    private const array TEXTES = [
        'domaine', 'accroche', 'lieu', 'objectifs', 'publicVise', 'prerequis', 'programme', 'ceQuiChange',
        'methodes', 'moyens', 'evaluation', 'acces', 'accessibilite', 'profilFormateurs',
    ];

    /** Ce que l'API accepte en plus des textes. Tout autre champ est refusé. */
    private const array AUTRES = ['code', 'nom', 'dureeHeures', 'tarifInterEuros', 'tarifIntraEuros', 'motif', 'simuler'];

    public function __construct(
        private readonly FormationRepository  $formations,
        private readonly HistoriqueFormations $historique,
        private readonly CodesFormation       $codes,
        private readonly ValidatorInterface   $validateur,
        private readonly SluggerInterface     $slugger,
        #[Autowire('%app_api_jeton%')]
        private readonly string               $jeton,
    )
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        if (($refus = $this->refus($request)) !== null) {
            return $refus;
        }

        return $this->reponse(['formations' => array_map(
            fn(Formation $formation) => [
                'code' => $formation->getCode(),
                'nom' => $formation->getNom(),
                'statut' => $formation->getStatut()->value,
                'domaine' => $formation->getDomaine(),
                'dureeHeures' => $formation->getDureeHeures(),
                'version' => $formation->getVersion(),
            ],
            $this->formations->findBy([], ['nom' => 'ASC']),
        )]);
    }

    #[Route('/{code}', name: '_voir', methods: ['GET'])]
    public function voir(string $code, Request $request): JsonResponse
    {
        if (($refus = $this->refus($request)) !== null) {
            return $refus;
        }

        $formation = $this->formations->findOneBy(['code' => mb_strtoupper($code)]);

        return $formation === null
            ? $this->reponse(['erreur' => 'Aucune formation ne porte ce code.'], Response::HTTP_NOT_FOUND)
            : $this->reponse($this->fiche($formation));
    }

    /**
     * Crée la formation si son code est inconnu ou absent (en brouillon ;
     * sans code, il est tiré de l'intitulé), sinon la modifie. Seuls les champs présents dans la requête sont touchés.
     * Avec « simuler », rien n'est écrit : la réponse dit ce qui changerait.
     */
    #[Route('', name: '_enregistrer', methods: ['POST'])]
    public function enregistrer(Request $request): JsonResponse
    {
        if (($refus = $this->refus($request)) !== null) {
            return $refus;
        }

        $donnees = json_decode($request->getContent(), true);

        if (!is_array($donnees)
            || (trim((string)($donnees['code'] ?? '')) === '' && trim((string)($donnees['nom'] ?? '')) === '')) {
            return $this->reponse(['erreur' => 'Corps JSON attendu, avec « code » (modification) ou « nom » (création).'], Response::HTTP_BAD_REQUEST);
        }

        $code = mb_strtoupper(trim((string)($donnees['code'] ?? '')));

        $inconnus = array_values(array_diff(array_keys($donnees), self::TEXTES, self::AUTRES));

        if ($inconnus !== []) {
            return $this->reponse([
                'erreur' => "Champs refusés : le statut, l'adresse de la page, la page d'accueil, la note et le nombre de personnes formées se règlent dans l'admin.",
                'champs' => $inconnus,
            ], Response::HTTP_BAD_REQUEST);
        }

        $formation = $code === '' ? null : $this->formations->findOneBy(['code' => $code]);
        $creation = $formation === null;

        if ($creation) {
            $formation = (new Formation())->setCode($code);
        }

        $avant = $formation->instantane();

        if (array_key_exists('nom', $donnees) && trim((string)$donnees['nom']) !== $formation->getNom()) {
            // L'intitulé d'une formation publiée est celui de sa page : on ne le change pas d'ici.
            if ($formation->getStatut() === StatutFormation::Publiee) {
                return $this->reponse(
                    ['erreur' => "Cette formation est publiée : son intitulé ne se change que dans l'admin."],
                    Response::HTTP_CONFLICT,
                );
            }

            $formation->setNom((string)$donnees['nom']);
        }

        if ($creation) {
            $formation->setSlug($this->slugger->slug($formation->getNom())->lower()->toString());

            if ($formation->getCode() === '') {
                $formation->setCode($this->codes->generer($formation->getNom()));
            }
        }

        foreach (self::TEXTES as $champ) {
            if (array_key_exists($champ, $donnees)) {
                $formation->{'set' . ucfirst($champ)}(is_scalar($donnees[$champ]) ? (string)$donnees[$champ] : '');
            }
        }

        if (array_key_exists('dureeHeures', $donnees)) {
            $formation->setDureeHeures(is_numeric($donnees['dureeHeures']) ? (float)$donnees['dureeHeures'] : null);
        }

        // Les tarifs sont conservés en centimes ; l'API parle en euros.
        foreach (['tarifInterEuros' => 'setTarifInterHT', 'tarifIntraEuros' => 'setTarifIntraHT'] as $champ => $mutateur) {
            if (array_key_exists($champ, $donnees)) {
                $formation->$mutateur(is_numeric($donnees[$champ]) ? (int)round((float)$donnees[$champ] * 100) : null);
            }
        }

        $violations = $this->validateur->validate($formation);

        if (count($violations) > 0) {
            $erreurs = [];

            foreach ($violations as $violation) {
                $erreurs[] = ['champ' => $violation->getPropertyPath(), 'message' => (string)$violation->getMessage()];
            }

            return $this->reponse(['erreur' => 'Fiche refusée.', 'erreurs' => $erreurs], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $apres = $formation->instantane();
        $modifies = array_values(array_filter(
            array_keys($apres),
            static fn(string $champ) => $apres[$champ] != ($avant[$champ] ?? null),
        ));

        $simulation = (bool)($donnees['simuler'] ?? false);
        $version = null;

        if (!$simulation && ($creation || $modifies !== [])) {
            $motif = trim((string)($donnees['motif'] ?? ''));
            $version = $this->historique->enregistrer(
                $formation,
                null,
                'Skill creer-formation' . ($motif === '' ? '' : ' : ' . $motif),
            );
        }

        return $this->reponse([
            'action' => $simulation ? 'simulation' : ($creation ? 'creation' : ($modifies === [] ? 'inchangee' : 'modification')),
            'champsModifies' => $modifies,
            'version' => $version?->getNumero(),
            'avertissements' => $this->avertissements($formation),
            'fiche' => $this->fiche($formation),
        ], $creation && !$simulation ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * La fiche telle que l'API l'échange : les champs rédactionnels, les
     * tarifs en euros, et de quoi la retrouver dans l'admin.
     *
     * @return array<string, mixed>
     */
    private function fiche(Formation $formation): array
    {
        $fiche = [
            'code' => $formation->getCode(),
            'nom' => $formation->getNom(),
            'statut' => $formation->getStatut()->value,
            'slug' => $formation->getSlug(),
            'version' => $formation->getVersion(),
            'dureeHeures' => $formation->getDureeHeures(),
            'tarifInterEuros' => $formation->getTarifInterHT() === null ? null : $formation->getTarifInterHT() / 100,
            'tarifIntraEuros' => $formation->getTarifIntraHT() === null ? null : $formation->getTarifIntraHT() / 100,
        ];

        $instantane = $formation->instantane();

        foreach (self::TEXTES as $champ) {
            $fiche[$champ] = $instantane[$champ] ?? '';
        }

        $fiche['admin'] = $formation->getId() === null ? null : $this->generateUrl(
            'app_admin_formation_voir',
            ['id' => $formation->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $fiche;
    }

    /**
     * Ce qui n'empêche pas d'enregistrer un brouillon, mais qu'il faudra
     * corriger avant de publier.
     *
     * @return list<string>
     */
    private function avertissements(Formation $formation): array
    {
        $avertissements = [];

        foreach (['objectifs' => 'les objectifs', 'publicVise' => 'le public visé', 'prerequis' => 'les prérequis', 'programme' => 'le programme'] as $champ => $libelle) {
            if (trim((string)$formation->instantane()[$champ]) === '') {
                $avertissements[] = 'Il manque ' . $libelle . ' : la formation ne pourra pas être publiée.';
            }
        }

        // La somme des durées de séquences, « ◦ Séquence (1h30) », doit faire la durée annoncée.
        if ($formation->getDureeHeures() !== null
            && preg_match_all('/^\s*◦.*\((\d+)\s*h\s*(\d+)?\)\s*$/mu', $formation->instantane()['programme'], $durees, PREG_SET_ORDER) > 0) {
            $minutes = 0;

            foreach ($durees as $duree) {
                $minutes += (int)$duree[1] * 60 + (int)($duree[2] ?? 0);
            }

            if (abs($minutes / 60 - $formation->getDureeHeures()) > 0.01) {
                $avertissements[] = sprintf(
                    'Les séquences du programme totalisent %s h, la durée annoncée est de %s h.',
                    rtrim(rtrim(number_format($minutes / 60, 2, ',', ''), '0'), ','),
                    rtrim(rtrim(number_format($formation->getDureeHeures(), 2, ',', ''), '0'), ','),
                );
            }
        }

        if ($formation->getTarifInterHT() === null && $formation->getTarifIntraHT() === null) {
            $avertissements[] = "Aucun tarif : à renseigner (tarifInterEuros, tarifIntraEuros) ou dans l'admin.";
        }

        return $avertissements;
    }

    /**
     * Une réponse JSON lisible : les accents et les barres obliques tels quels.
     *
     * @param array<string, mixed> $donnees
     */
    private function reponse(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        return (new JsonResponse(status: $statut))
            ->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->setData($donnees);
    }

    /** Null si la requête porte le bon jeton, sinon la réponse de refus. */
    private function refus(Request $request): ?JsonResponse
    {
        if ($this->jeton === '') {
            throw $this->createNotFoundException();
        }

        $recu = (string)$request->headers->get('Authorization');

        return str_starts_with($recu, 'Bearer ') && hash_equals($this->jeton, substr($recu, 7))
            ? null
            : $this->reponse(['erreur' => 'Jeton absent ou invalide.'], Response::HTTP_UNAUTHORIZED);
    }
}
