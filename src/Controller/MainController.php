<?php

namespace App\Controller;

use AllowDynamicProperties;
use App\Service\CatalogueFormations;
use App\Service\MailService;
use App\Service\QuotaOrientation;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[AllowDynamicProperties]
class MainController extends AbstractController
{
    #[Route('/health', name: 'health')]
    public function health(Connection $db): Response
    {
        $db->executeQuery('SELECT 1');

        return new Response('HEALTH_OK', 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Quiz de cadrage de la page d'accueil.
     *
     * Défini ici plutôt que dans le template : le contrôleur en a besoin
     * pour libeller et valider les réponses reçues côté serveur.
     */
    private const QUESTIONS_CADRAGE = [
        [
            'id' => 'domaine',
            'libelle' => 'Domaine prioritaire',
            'question' => 'Quel domaine vous intéresse ?',
            'options' => [
                'Intelligence artificielle',
                'Cybersécurité',
                'Data',
                'Développement web, logiciel ou mobile',
                'Systèmes et réseaux',
                'Je ne sais pas encore',
            ],
        ],
        [
            'id' => 'niveau',
            'libelle' => 'Point de départ des équipes',
            'question' => 'Où en sont vos équipes ?',
            'options' => [
                'Elles utilisent déjà ces outils, sans cadre',
                'Elles débutent',
                'Les niveaux sont très hétérogènes',
                'Je ne sais pas précisément',
            ],
        ],
        [
            'id' => 'effectif',
            'libelle' => 'Personnes à former',
            'question' => 'Combien de personnes seraient à former ?',
            'options' => [
                '1 ou 2',
                '3 à 10',
                '11 à 30',
                'Plus de 30',
                'Je ne sais pas encore',
            ],
        ],
        [
            'id' => 'taille',
            'libelle' => "Taille de l'entreprise",
            'question' => 'Quelle est la taille de votre entreprise ?',
            'options' => [
                'Moins de 20 salariés',
                '20 à 50 salariés',
                '50 à 250 salariés',
                'Plus de 250 salariés',
            ],
        ],
    ];

    #[Route('/main', name: 'app_main')]
    public function index(Request $request, QuotaOrientation $quota): Response
    {
        return $this->render('front/accueil.html.twig', [
            'questions' => self::QUESTIONS_CADRAGE,
            // Quand le quota horaire d'analyses est épuisé, le champ de
            // besoin n'est pas affiché : mieux vaut ne rien proposer que
            // de faire saisir un texte pour le refuser ensuite.
            'orientationDisponible' => $quota->disponible(),
            // Le besoin n'est remis dans le champ que si le visiteur arrive
            // par « Reformuler mon besoin » : ailleurs, le formulaire doit
            // être vierge. Le texte lui-même reste en session, jamais dans
            // l'URL.
            'besoinPrecedent' => $request->query->has('reformuler')
                ? (string)$request->getSession()->get(OrientationController::CLE_BESOIN, '')
                : '',
        ]);
    }

    /**
     * Demande de rappel envoyée par le quiz de cadrage (fetch, réponse JSON).
     */
    #[Route('/demande-de-rappel', name: 'app_demande_rappel', methods: ['POST'])]
    public function demandeRappel(Request $request, MailService $mailService): JsonResponse
    {
        if (!$this->isCsrfTokenValid('rappel', (string)$request->request->get('_csrf_token'))) {
            return new JsonResponse(
                ['message' => 'Votre session a expiré. Rechargez la page et réessayez.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $contact = [
            'nom' => trim((string)$request->request->get('nom')),
            'poste' => trim((string)$request->request->get('poste')),
            'email' => trim((string)$request->request->get('email')),
            'tel' => trim((string)$request->request->get('tel')),
        ];

        $erreurs = $this->validerDemandeRappel($contact);

        if ($erreurs) {
            return new JsonResponse(['errors' => $erreurs], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $mailService->sendDemandeRappel($contact, $this->libellerReponses($request));
        } catch (Throwable $e) {
            return new JsonResponse(
                ['message' => "L'envoi a échoué. Appelez-nous au 05 54 54 24 84, nous prenons le relais."],
                Response::HTTP_BAD_GATEWAY
            );
        }

        return new JsonResponse([
            'message' => 'Merci. Vos réponses et vos coordonnées nous sont transmises. '
                . "Nous vous rappelons pour fixer l'appel de cadrage de 30 minutes.",
        ]);
    }

    /**
     * @param array<string, string> $contact
     *
     * @return array<string, string> champ en erreur => message affiché sous le champ
     */
    private function validerDemandeRappel(array $contact): array
    {
        $erreurs = [];

        if ($contact['nom'] === '') {
            $erreurs['nom'] = "Indiquez votre nom pour qu'on sache qui rappeler.";
        }

        if ($contact['poste'] === '') {
            $erreurs['poste'] = 'Indiquez votre poste.';
        }

        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs['email'] = 'Indiquez un email valide pour recevoir la confirmation.';
        }

        if (strlen(preg_replace('/\D/', '', $contact['tel'])) < 10) {
            $erreurs['tel'] = 'Indiquez un numéro à 10 chiffres pour être rappelé.';
        }

        return $erreurs;
    }

    /**
     * Ne retient que les réponses correspondant à une option connue :
     * le contenu du formulaire n'est pas repris tel quel dans l'email.
     *
     * @return array<string, string> libellé de la question => réponse choisie
     */
    private function libellerReponses(Request $request): array
    {
        $reponses = [];

        foreach (self::QUESTIONS_CADRAGE as $question) {
            $reponse = (string)$request->request->get($question['id'], '');

            if (in_array($reponse, $question['options'], true)) {
                $reponses[$question['libelle']] = $reponse;
            }
        }

        return $reponses;
    }

    function recupererFormations(): Response
    {
        // Récupération des formations à mettre en page d'accueil
//        $response = $smartOfApiService->callSmartofApi('/api/produit/list');
//        $formations = $response['produits'] ?? [];
//
//        // Uniquement les formations à mettre en page d'accueil
//        $produitsFormation = array_values(array_map(function (array $formation) {
//            $formation['slug'] = $this->slugger
//                ->slug($formation['meta']['nom'])
//                ->lower();
//
//            return $formation;
//        }, array_filter($formations, static function (array $formation): bool {
//            return !empty(trim((string)($formation['custom_fields']['custom_field_1'] ?? '')));
//        })));
//
//        return $this->render('front/index.html.twig', [
//            'produitsFormation' => $produitsFormation
//        ]);
    }

    /**
     * Page éditoriale des domaines. Le texte est écrit dans le template ;
     * seul le lien « Voir les formations de ce domaine » dépend de SmartOF :
     * il n'est affiché que si le catalogue contient ce domaine.
     */
    #[Route('/nos-domaines', name: 'app_domaines')]
    public function domaines(CatalogueFormations $catalogue): Response
    {
        try {
            $domainesCatalogue = array_keys($catalogue->domaines());
        } catch (Throwable) {
            // SmartOF injoignable : la page reste lisible, sans les liens.
            $domainesCatalogue = [];
        }

        return $this->render('front/domaines.html.twig', [
            'domainesCatalogue' => $domainesCatalogue,
        ]);
    }

    /**
     * Page éditoriale : méthode et formateurs. Tout le texte est dans le
     * template, rien ne vient de SmartOF.
     */
    #[Route('/qui-sommes-nous', name: 'app_qui_sommes_nous')]
    public function quiSommesNous(): Response
    {
        return $this->render('front/qui_sommes_nous.html.twig');
    }

    #[Route('/mentions-legales', name: 'app_mentions_legales')]
    public function mentions(): Response
    {
        return $this->render('front/mentions_legales.html.twig');
    }

    #[Route('/donnees-personnelles', name: 'app_donnees_personnelles')]
    public function donnees(): Response
    {
        return $this->render('front/donnees_personnelles.html.twig');
    }

}
