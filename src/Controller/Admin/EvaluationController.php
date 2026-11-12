<?php

namespace App\Controller\Admin;

use App\Controller\EmargementController as LiensEmargement;
use App\Controller\EvaluationController as LiensEvaluation;
use App\Entity\Client;
use App\Entity\Formateur;
use App\Entity\Evaluation;
use App\Entity\Inscription;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\TypeEvaluation;
use App\Repository\EvaluationRepository;
use App\Service\Evaluations;
use App\Service\Jetons;
use App\Service\MailService;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Suivi des évaluations d'une session : qui a répondu à quoi, synthèse
 * des réponses, relance par email, saisie de l'évaluation des acquis à la
 * place du formateur, avis des clients et bilans des formateurs.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sessions/{id}/evaluations', name: 'app_admin_evaluation', requirements: ['id' => '\d+'])]
class EvaluationController extends AbstractController
{
    public function __construct(
        private readonly EvaluationRepository $depot,
        private readonly Evaluations          $evaluations,
        private readonly Jetons               $jetons,
    )
    {
    }

    /**
     * La carte « Évaluations » de la fiche d'une session. Appelée par
     * `render(controller(...))` : elle n'a pas de route.
     */
    public function carte(SessionFormation $session): Response
    {
        $remplis = $this->depot->deLaSession($session);
        $comptes = [];

        $inscrits = count($session->inscrits());

        foreach (TypeEvaluation::participants() as $type) {
            $comptes[] = [
                'type' => $type,
                'nombre' => count(array_filter($remplis, static fn(array $parType) => isset($parType[$type->value]))),
                'attendus' => $inscrits,
            ];
        }

        $tiers = $this->depot->tiersDeLaSession($session);

        $comptes[] = [
            'type' => TypeEvaluation::Client,
            'nombre' => count($tiers['client']),
            'attendus' => count($this->evaluations->clients($session)),
        ];
        $comptes[] = [
            'type' => TypeEvaluation::Formateur,
            'nombre' => count($tiers['formateur']),
            'attendus' => $session->getFormateurs()->count(),
        ];

        return $this->render('admin/evaluation/_carte.html.twig', [
            'session' => $session,
            'comptes' => array_filter($comptes, static fn(array $compte) => $compte['attendus'] > 0),
        ]);
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(SessionFormation $session): Response
    {
        $remplis = $this->depot->deLaSession($session);
        $lignes = [];

        foreach ($session->inscriptionsTriees() as $inscription) {
            // Un participant désisté reste listé s'il a répondu à quelque chose.
            if (!$inscription->estActive() && !isset($remplis[$inscription->getId()])) {
                continue;
            }

            $lignes[] = [
                'inscription' => $inscription,
                'lien' => $this->lienParticipant($inscription),
                'evaluations' => $remplis[$inscription->getId()] ?? [],
            ];
        }

        $types = [];

        $inscrits = count($session->inscrits());

        foreach (TypeEvaluation::participants() as $type) {
            $duType = array_values(array_filter(array_map(
                static fn(array $parType) => $parType[$type->value] ?? null,
                $remplis,
            )));

            $types[$type->value] = [
                'type' => $type,
                'etat' => $this->evaluations->etat($type, $session),
                'ouvreLe' => $this->evaluations->ouvreLe($type, $session),
                'synthese' => $this->evaluations->synthese($duType),
                'attendus' => $inscrits,
                'qui' => 'inscrit',
            ];
        }

        // Les autres parties prenantes : les clients, puis les formateurs.
        $avis = $this->depot->tiersDeLaSession($session);
        $tiers = [];

        foreach ($this->evaluations->clients($session) as $client) {
            $tiers[] = [
                'nom' => $client->getNom(),
                'role' => 'Client' . ($client->getContactNom() !== '' ? ' · ' . $client->getContactNom() : ''),
                'type' => TypeEvaluation::Client,
                'lien' => $this->lienClient($session, $client),
                'evaluation' => $avis['client'][$client->getId()] ?? null,
            ];
        }

        foreach ($session->getFormateurs() as $formateur) {
            $tiers[] = [
                'nom' => $formateur->libelle(),
                'role' => 'Formateur',
                'type' => TypeEvaluation::Formateur,
                'lien' => $this->lienFormateur($session, $formateur, 'app_emargement_formateur'),
                'evaluation' => $avis['formateur'][$formateur->getId()] ?? null,
            ];
        }

        foreach ([TypeEvaluation::Client, TypeEvaluation::Formateur] as $type) {
            $attendus = count(array_filter($tiers, static fn(array $ligne) => $ligne['type'] === $type));

            if ($attendus === 0) {
                continue;
            }

            $types[$type->value] = [
                'type' => $type,
                'etat' => $this->evaluations->etat($type, $session),
                'ouvreLe' => $this->evaluations->ouvreLe($type, $session),
                'synthese' => $this->evaluations->synthese(array_values($avis[$type->value])),
                'attendus' => $attendus,
                'qui' => $type->value,
            ];
        }

        return $this->render('admin/evaluation/index.html.twig', [
            'session' => $session,
            'lignes' => $lignes,
            'tiers' => $tiers,
            'types' => $types,
            'participants' => array_map(static fn(TypeEvaluation $type) => $type->value, TypeEvaluation::participants()),
            'inscrits' => $inscrits,
            'progression' => $this->evaluations->progression($session),
        ]);
    }

    /** Les réponses d'une personne à un questionnaire, avec leur preuve. */
    #[Route('/reponse/{evaluation}', name: '_reponse', requirements: ['evaluation' => '\d+'], methods: ['GET'])]
    public function reponse(SessionFormation $session, int $evaluation): Response
    {
        $evaluation = $this->depot->find($evaluation);

        if (!$evaluation instanceof Evaluation || $evaluation->getSession() !== $session) {
            throw $this->createNotFoundException('Réponse introuvable.');
        }

        return $this->render('admin/evaluation/reponse.html.twig', [
            'session' => $session,
            'evaluation' => $evaluation,
        ]);
    }

    /** L'administrateur évalue les acquis à la place du formateur (grille papier, appel). */
    #[Route('/acquis', name: '_acquis', methods: ['GET', 'POST'])]
    public function acquis(SessionFormation $session, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);

            $user = $this->getUser();

            try {
                $nombre = $this->evaluations->evaluer(
                    $session,
                    $request->request->all('a'),
                    $request->request->all('c'),
                    null,
                    $user instanceof User ? $user : null,
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );

                $this->addFlash('succes', $nombre > 0
                    ? sprintf('%d évaluation(s) enregistrée(s).', $nombre)
                    : 'Aucun changement à enregistrer.');

                return $this->redirectToRoute('app_admin_evaluation', ['id' => $session->getId()]);
            } catch (DomainException $e) {
                $this->addFlash('erreur', $e->getMessage());
            }
        }

        return $this->render('admin/evaluation/acquis.html.twig', [
            'session' => $session,
            'objectifs' => $this->evaluations->objectifs($session),
            'niveaux' => Evaluations::ACQUIS,
            'existantes' => $this->depot->deLaSession($session),
            'ouvert' => $this->evaluations->etat(TypeEvaluation::Acquis, $session) === 'ouvert',
            'saisie' => ['a' => $request->request->all('a'), 'c' => $request->request->all('c')],
        ]);
    }

    /**
     * Envoie le lien d'un questionnaire ouvert à ceux qui n'y ont pas
     * encore répondu ; pour les acquis, aux formateurs de la session.
     */
    #[Route('/relancer', name: '_relancer', methods: ['POST'])]
    public function relancer(SessionFormation $session, Request $request, MailService $mails): Response
    {
        $this->verifierJeton($request);

        $type = TypeEvaluation::tryFrom($request->request->getString('type'));

        if ($type === null) {
            throw $this->createNotFoundException('Questionnaire inconnu.');
        }

        $retour = $this->redirectToRoute('app_admin_evaluation', ['id' => $session->getId()]);

        if ($this->evaluations->etat($type, $session) !== 'ouvert') {
            $this->addFlash('erreur', "Ce questionnaire n'est pas ouvert : rien n'a été envoyé.");

            return $retour;
        }

        $remplis = $this->depot->deLaSession($session);
        $destinataires = [];

        $avis = $this->depot->tiersDeLaSession($session);

        if ($type === TypeEvaluation::Acquis) {
            foreach ($session->getFormateurs() as $formateur) {
                $destinataires[] = [
                    'nom' => $formateur->libelle(),
                    'email' => $formateur->getEmail(),
                    'lien' => $this->lienFormateur($session, $formateur, 'app_acquis_formateur'),
                ];
            }
        } elseif ($type === TypeEvaluation::Formateur) {
            foreach ($session->getFormateurs() as $formateur) {
                if (!isset($avis['formateur'][$formateur->getId()])) {
                    $destinataires[] = [
                        'nom' => $formateur->libelle(),
                        'email' => $formateur->getEmail(),
                        'lien' => $this->lienFormateur($session, $formateur, 'app_bilan_formateur'),
                    ];
                }
            }
        } elseif ($type === TypeEvaluation::Client) {
            foreach ($this->evaluations->clients($session) as $client) {
                if (!isset($avis['client'][$client->getId()])) {
                    $destinataires[] = [
                        'nom' => $client->getNom(),
                        'email' => $client->getContactEmail(),
                        'lien' => $this->lienClient($session, $client),
                    ];
                }
            }
        } else {
            foreach ($session->inscrits() as $inscription) {
                if (isset($remplis[$inscription->getId()][$type->value])) {
                    continue;
                }

                $destinataires[] = [
                    'nom' => $inscription->getParticipant()->libelle(),
                    'email' => $inscription->getParticipant()->getEmail(),
                    'lien' => $this->generateUrl('app_questionnaire', [
                        'id' => $inscription->getId(),
                        'jeton' => $this->jetons->pour(LiensEmargement::sujetParticipant($inscription)),
                        'type' => $type->value,
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                ];
            }
        }

        $envoyes = 0;
        $echecs = [];
        $sansEmail = [];

        foreach ($destinataires as $destinataire) {
            if ((string)$destinataire['email'] === '') {
                $sansEmail[] = $destinataire['nom'];
                continue;
            }

            try {
                $mails->sendLienQuestionnaire($destinataire['email'], $session, $type, $destinataire['lien']);
                $envoyes++;
            } catch (Throwable) {
                $echecs[] = $destinataire['nom'];
            }
        }

        if ($destinataires === []) {
            $this->addFlash('succes', 'Tout le monde a déjà répondu : rien à envoyer.');
        }

        if ($envoyes > 0) {
            $this->addFlash('succes', sprintf('%d email(s) envoyé(s).', $envoyes));
        }

        if ($sansEmail !== []) {
            $this->addFlash('erreur', 'Sans adresse email, à prévenir autrement : ' . implode(', ', $sansEmail) . '.');
        }

        if ($echecs !== []) {
            $this->addFlash('erreur', "L'envoi a échoué pour : " . implode(', ', $echecs) . '.');
        }

        return $retour;
    }

    private function lienParticipant(Inscription $inscription): string
    {
        return $this->generateUrl('app_emargement_participant', [
            'id' => $inscription->getId(),
            'jeton' => $this->jetons->pour(LiensEmargement::sujetParticipant($inscription)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function lienClient(SessionFormation $session, Client $client): string
    {
        return $this->generateUrl('app_avis_client', [
            'session' => $session->getId(),
            'client' => $client->getId(),
            'jeton' => $this->jetons->pour(LiensEvaluation::sujetClient($session, $client)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Un des liens du formateur : sa page personnelle, l'évaluation des acquis, ou son bilan. */
    private function lienFormateur(SessionFormation $session, Formateur $formateur, string $route): string
    {
        return $this->generateUrl($route, [
            'session' => $session->getId(),
            'formateur' => $formateur->getId(),
            'jeton' => $this->jetons->pour(LiensEmargement::sujetFormateur($session, $formateur)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
