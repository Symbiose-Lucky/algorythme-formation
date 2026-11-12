<?php

namespace App\Controller;

use App\Entity\Formateur;
use App\Entity\Inscription;
use App\Entity\Seance;
use App\Entity\SessionFormation;
use App\Enum\TypeEvaluation;
use App\Repository\EmargementRepository;
use App\Repository\EvaluationRepository;
use App\Service\Emargements;
use App\Service\Evaluations;
use App\Service\Jetons;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Émargement en ligne : chaque participant et chaque formateur d'une
 * session a un lien personnel, sans compte ni mot de passe. Il y signe sa
 * présence séance par séance, à partir de l'heure de début.
 *
 * Le jeton du lien tient lieu d'identification (voir Jetons) : il n'y a
 * donc pas de jeton CSRF, qui ne protégerait rien de plus.
 */
class EmargementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Jetons                 $jetons,
        private readonly Emargements            $emargements,
        private readonly EmargementRepository   $depot,
        private readonly Evaluations            $evaluations,
        private readonly EvaluationRepository   $depotEvaluations,
    )
    {
    }

    /** Le sujet du jeton d'un participant. */
    public static function sujetParticipant(Inscription $inscription): string
    {
        return 'emargement|p|' . $inscription->getId();
    }

    /** Le sujet du jeton d'un formateur, pour une session donnée. */
    public static function sujetFormateur(SessionFormation $session, Formateur $formateur): string
    {
        return 'emargement|f|' . $session->getId() . '|' . $formateur->getId();
    }

    #[Route('/emargement/p/{id}/{jeton}', name: 'app_emargement_participant', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function participant(int $id, string $jeton, Request $request): Response
    {
        $inscription = $this->em->find(Inscription::class, $id);

        if ($inscription === null || !$this->jetons->valide(self::sujetParticipant($inscription), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        return $this->page($request, $inscription->getSession(), $inscription, null);
    }

    #[Route('/emargement/f/{session}/{formateur}/{jeton}', name: 'app_emargement_formateur', requirements: ['session' => '\d+', 'formateur' => '\d+'], methods: ['GET', 'POST'])]
    public function formateur(int $session, int $formateur, string $jeton, Request $request): Response
    {
        $session = $this->em->find(SessionFormation::class, $session);
        $formateur = $this->em->find(Formateur::class, $formateur);

        if ($session === null || $formateur === null
            || !$session->getFormateurs()->contains($formateur)
            || !$this->jetons->valide(self::sujetFormateur($session, $formateur), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        return $this->page($request, $session, null, $formateur);
    }

    private function page(Request $request, SessionFormation $session, ?Inscription $inscription, ?Formateur $formateur): Response
    {
        $erreur = null;

        if ($request->isMethod('POST')) {
            $seance = $this->seance($session, $request->request->getInt('seance'));

            try {
                if ($seance === null) {
                    throw new DomainException('Choisissez la séance à signer.');
                }

                $this->emargements->signer(
                    $seance,
                    $inscription,
                    $formateur,
                    $request->request->getString('signature'),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );

                $this->addFlash('succes', 'Votre présence est enregistrée. Merci.');

                // Redirection : recharger la page ne renvoie pas la signature.
                return $this->redirect($request->getUri());
            } catch (DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        $cle = $inscription !== null ? 'p' . $inscription->getId() : 'f' . $formateur->getId();
        $tableau = $this->depot->deLaSession($session);
        $seances = [];
        $aSigner = null;

        foreach ($session->seancesTriees() as $seance) {
            $emargement = $tableau[$seance->getId()][$cle] ?? null;
            $ouverte = $this->emargements->estOuverte($seance);

            $seances[] = ['seance' => $seance, 'emargement' => $emargement, 'ouverte' => $ouverte];

            // La première séance commencée et pas encore émargée est proposée d'office.
            if ($aSigner === null && $ouverte && $emargement === null) {
                $aSigner = $seance;
            }
        }

        $peutSigner = $inscription === null || $inscription->estActive();

        return $this->render('signature/emargement.html.twig', [
            'session' => $session,
            'personne' => $inscription === null
                ? ''
                : $inscription->getParticipant()->getPrenom() . ' ' . $inscription->getParticipant()->getNom(),
            'formateur' => $formateur,
            'seances' => $seances,
            'aSigner' => $peutSigner ? $aSigner : null,
            'inscriptionAnnulee' => !$peutSigner,
            'questionnaires' => $this->questionnaires($request, $session, $inscription),
            // Le formateur peut revenir à son espace, où sont toutes ses sessions.
            'espace' => $formateur === null || $formateur->isArchive() ? null : $this->generateUrl('app_espace_formateur', [
                'id' => $formateur->getId(),
                'jeton' => $this->jetons->pour(EspaceFormateurController::sujet($formateur)),
            ]),
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }

    /**
     * Ce que la personne a à remplir en plus de l'émargement : les
     * questionnaires du participant, ou l'évaluation des acquis du formateur.
     *
     * @return list<array{titre: string, etat: string, ouvreLe: ?\DateTimeImmutable, lien: string, detail: string, bouton?: string}>
     */
    private function questionnaires(Request $request, SessionFormation $session, ?Inscription $inscription): array
    {
        $jeton = (string)$request->attributes->get('jeton');
        $remplis = $this->depotEvaluations->deLaSession($session);
        $lignes = [];

        if ($inscription === null) {
            $evalues = count(array_filter($remplis, static fn(array $parType) => isset($parType[TypeEvaluation::Acquis->value])));
            $inscrits = count($session->inscrits());

            $formateur = $request->attributes->getInt('formateur');
            $bilan = [
                'titre' => TypeEvaluation::Formateur->titre(),
                'etat' => isset($this->depotEvaluations->tiersDeLaSession($session)['formateur'][$formateur])
                    ? 'rempli'
                    : $this->evaluations->etat(TypeEvaluation::Formateur, $session),
                'ouvreLe' => $this->evaluations->ouvreLe(TypeEvaluation::Formateur, $session),
                'lien' => $this->generateUrl('app_bilan_formateur', [
                    'session' => $session->getId(),
                    'formateur' => $formateur,
                    'jeton' => $jeton,
                ]),
                'detail' => '',
                'bouton' => 'Répondre',
            ];

            if ($inscrits === 0) {
                return [$bilan];
            }

            return [[
                'titre' => 'Évaluer les acquis des participants',
                'etat' => $evalues >= $inscrits ? 'rempli' : $this->evaluations->etat(TypeEvaluation::Acquis, $session),
                'ouvreLe' => $this->evaluations->ouvreLe(TypeEvaluation::Acquis, $session),
                'lien' => $this->generateUrl('app_acquis_formateur', [
                    'session' => $session->getId(),
                    'formateur' => $request->attributes->getInt('formateur'),
                    'jeton' => $jeton,
                ]),
                'detail' => sprintf('%d sur %d évalué(s)', $evalues, $inscrits),
            ], $bilan];
        }

        if (!$inscription->estActive()) {
            return [];
        }

        foreach (TypeEvaluation::questionnaires() as $type) {
            $rempli = isset($remplis[$inscription->getId()][$type->value]);
            $etat = $rempli ? 'rempli' : $this->evaluations->etat($type, $session);

            // Un questionnaire fermé sans réponse n'a plus rien à montrer.
            if ($etat === 'clos') {
                continue;
            }

            $lignes[] = [
                'titre' => $type->titre(),
                'etat' => $etat,
                'ouvreLe' => $this->evaluations->ouvreLe($type, $session),
                'lien' => $this->generateUrl('app_questionnaire', [
                    'id' => $inscription->getId(),
                    'jeton' => $jeton,
                    'type' => $type->value,
                ]),
                'detail' => '',
            ];
        }

        return $lignes;
    }

    private function seance(SessionFormation $session, int $id): ?Seance
    {
        foreach ($session->getSeances() as $seance) {
            if ($seance->getId() === $id) {
                return $seance;
            }
        }

        return null;
    }
}
