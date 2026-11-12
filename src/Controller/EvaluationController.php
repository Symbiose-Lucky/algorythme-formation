<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Formateur;
use App\Entity\Inscription;
use App\Entity\SessionFormation;
use App\Enum\TypeEvaluation;
use App\Repository\EvaluationRepository;
use App\Service\Evaluations;
use App\Service\Jetons;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Questionnaires en ligne, depuis le même lien personnel que l'émargement :
 * le participant répond au positionnement et aux questionnaires de
 * satisfaction ; le formateur évalue les acquis de ses participants et
 * donne son bilan de la session. Le client, lui, a un lien propre à son
 * avis sur la session.
 *
 * Comme pour l'émargement, le jeton du lien tient lieu d'identification.
 */
class EvaluationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Jetons                 $jetons,
        private readonly Evaluations            $evaluations,
        private readonly EvaluationRepository   $depot,
    )
    {
    }

    #[Route(
        '/emargement/p/{id}/{jeton}/questionnaire/{type}',
        name: 'app_questionnaire',
        requirements: ['id' => '\d+', 'type' => 'positionnement|chaud|froid'],
        methods: ['GET', 'POST'],
    )]
    public function questionnaire(int $id, string $jeton, string $type, Request $request): Response
    {
        $inscription = $this->em->find(Inscription::class, $id);

        if ($inscription === null || !$this->jetons->valide(EmargementController::sujetParticipant($inscription), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        $type = TypeEvaluation::from($type);
        $session = $inscription->getSession();
        $retour = $this->generateUrl('app_emargement_participant', ['id' => $id, 'jeton' => $jeton]);
        $erreur = null;

        if ($request->isMethod('POST')) {
            try {
                $this->evaluations->repondre(
                    $inscription,
                    $type,
                    $request->request->all('r'),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );

                $this->addFlash('succes', 'Vos réponses sont enregistrées. Merci.');

                return $this->redirect($retour);
            } catch (DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('signature/questionnaire.html.twig', [
            'session' => $session,
            'personne' => $inscription->getParticipant()->getPrenom() . ' ' . $inscription->getParticipant()->getNom(),
            'type' => $type,
            'questions' => $this->evaluations->questions($type, $session),
            'evaluation' => $this->depot->findOneBy(['inscription' => $inscription, 'type' => $type]),
            'ouvert' => $inscription->estActive() && $this->evaluations->etat($type, $session) === 'ouvert',
            'saisie' => $request->request->all('r'),
            'retour' => $retour,
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }

    /** Le sujet du jeton d'un client, pour son avis sur une session. */
    public static function sujetClient(SessionFormation $session, Client $client): string
    {
        return 'avis|c|' . $session->getId() . '|' . $client->getId();
    }

    #[Route(
        '/avis/c/{session}/{client}/{jeton}',
        name: 'app_avis_client',
        requirements: ['session' => '\d+', 'client' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function avisClient(int $session, int $client, string $jeton, Request $request): Response
    {
        $session = $this->em->find(SessionFormation::class, $session);
        $client = $this->em->find(Client::class, $client);

        if ($session === null || $client === null || !$this->jetons->valide(self::sujetClient($session, $client), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        // Le même lien sert au client et au financeur : c'est leur place dans la session qui dit lequel répond.
        $type = match (true) {
            in_array($client, $this->evaluations->clients($session), true) => TypeEvaluation::Client,
            $session->getFinanceur() === $client => TypeEvaluation::Financeur,
            default => throw $this->createNotFoundException('Lien invalide.'),
        };

        $personne = $client->getContactNom() !== ''
            ? $client->getContactNom() . ', ' . $client->getNom()
            : $client->getNom();

        return $this->avis($request, $session, $type, $client, null, $personne, null);
    }

    #[Route(
        '/emargement/f/{session}/{formateur}/{jeton}/bilan',
        name: 'app_bilan_formateur',
        requirements: ['session' => '\d+', 'formateur' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function bilanFormateur(int $session, int $formateur, string $jeton, Request $request): Response
    {
        $session = $this->em->find(SessionFormation::class, $session);
        $formateur = $this->em->find(Formateur::class, $formateur);

        if ($session === null || $formateur === null
            || !$session->getFormateurs()->contains($formateur)
            || !$this->jetons->valide(EmargementController::sujetFormateur($session, $formateur), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        return $this->avis($request, $session, TypeEvaluation::Formateur, null, $formateur, $formateur->libelle() . ', formateur', $this->generateUrl('app_emargement_formateur', [
            'session' => $session->getId(),
            'formateur' => $formateur->getId(),
            'jeton' => $jeton,
        ]));
    }

    /**
     * L'avis d'un client ou du financeur, ou le bilan d'un formateur, sur la session.
     *
     * @param ?string $retour La page personnelle où revenir ; un client n'en a pas.
     */
    private function avis(
        Request          $request,
        SessionFormation $session,
        TypeEvaluation   $type,
        ?Client          $client,
        ?Formateur       $formateur,
        string           $personne,
        ?string          $retour,
    ): Response
    {
        $erreur = null;

        if ($request->isMethod('POST')) {
            try {
                $this->evaluations->repondreTiers(
                    $session,
                    $type,
                    $client,
                    $formateur,
                    $request->request->all('r'),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );

                if ($retour !== null) {
                    $this->addFlash('succes', 'Vos réponses sont enregistrées. Merci.');
                }

                // Sans page personnelle, on revient ici : la page montre alors les réponses données.
                return $this->redirect($retour ?? $request->getUri());
            } catch (DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('signature/questionnaire.html.twig', [
            'session' => $session,
            'personne' => $personne,
            'type' => $type,
            'questions' => $this->evaluations->questions($type, $session),
            'evaluation' => $this->evaluations->duTiers($session, $type, $client, $formateur),
            'ouvert' => $this->evaluations->etat($type, $session) === 'ouvert',
            'saisie' => $request->request->all('r'),
            'retour' => $retour,
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }

    #[Route(
        '/emargement/f/{session}/{formateur}/{jeton}/acquis',
        name: 'app_acquis_formateur',
        requirements: ['session' => '\d+', 'formateur' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function acquis(int $session, int $formateur, string $jeton, Request $request): Response
    {
        $session = $this->em->find(SessionFormation::class, $session);
        $formateur = $this->em->find(Formateur::class, $formateur);

        if ($session === null || $formateur === null
            || !$session->getFormateurs()->contains($formateur)
            || !$this->jetons->valide(EmargementController::sujetFormateur($session, $formateur), $jeton)) {
            throw $this->createNotFoundException('Lien invalide.');
        }

        $retour = $this->generateUrl('app_emargement_formateur', [
            'session' => $session->getId(),
            'formateur' => $formateur->getId(),
            'jeton' => $jeton,
        ]);
        $erreur = null;

        if ($request->isMethod('POST')) {
            try {
                $nombre = $this->evaluations->evaluer(
                    $session,
                    $request->request->all('a'),
                    $request->request->all('c'),
                    $formateur,
                    null,
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );

                $this->addFlash('succes', $nombre > 0
                    ? sprintf('%d évaluation(s) enregistrée(s). Merci.', $nombre)
                    : 'Aucun changement à enregistrer.');

                return $this->redirect($retour);
            } catch (DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('signature/acquis.html.twig', [
            'session' => $session,
            'formateur' => $formateur,
            'objectifs' => $this->evaluations->objectifs($session),
            'niveaux' => Evaluations::ACQUIS,
            'existantes' => $this->depot->deLaSession($session),
            'ouvert' => $this->evaluations->etat(TypeEvaluation::Acquis, $session) === 'ouvert',
            'ouvreLe' => $this->evaluations->ouvreLe(TypeEvaluation::Acquis, $session),
            'saisie' => ['a' => $request->request->all('a'), 'c' => $request->request->all('c')],
            'retour' => $retour,
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }
}
