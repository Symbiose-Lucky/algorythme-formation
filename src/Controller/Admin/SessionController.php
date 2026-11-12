<?php

namespace App\Controller\Admin;

use App\Entity\Inscription;
use App\Entity\Participant;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\FormuleSession;
use App\Enum\StatutFormation;
use App\Form\SessionFormationType;
use App\Repository\EntreeJournalRepository;
use App\Repository\FormationRepository;
use App\Repository\SessionFormationRepository;
use App\Service\Emargements;
use App\Service\SuiviSessions;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des sessions. Il n'y a pas de suppression : une session qui
 * n'a pas lieu passe au statut « Annulée » et garde son journal.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sessions', name: 'app_admin_session')]
class SessionController extends AbstractController
{
    public function __construct(
        private readonly SessionFormationRepository $sessions,
        private readonly FormationRepository        $formations,
        private readonly SuiviSessions              $suivi,
    )
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Une période ou une formation inconnue dans l'URL est ignorée.
        $periode = $request->query->getString('periode');
        $periode = in_array($periode, SessionFormationRepository::PERIODES, true)
            ? $periode
            : SessionFormationRepository::A_VENIR;

        $formation = $request->query->getInt('formation') > 0
            ? $this->formations->find($request->query->getInt('formation'))
            : null;

        return $this->render('admin/session/index.html.twig', [
            'sessions' => $this->sessions->rechercher($periode, $formation),
            'nombres' => $this->sessions->compterParPeriode($formation),
            'periode' => $periode,
            'formation' => $formation,
            'formations' => $this->formations->findBy([], ['nom' => 'ASC']),
        ]);
    }

    /** `?formation=` présélectionne la formation (bouton de sa fiche). */
    #[Route('/nouvelle', name: '_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request): Response
    {
        $session = new SessionFormation();

        $formation = $request->query->getInt('formation') > 0
            ? $this->formations->find($request->query->getInt('formation'))
            : null;

        if ($formation !== null && $formation->getStatut() !== StatutFormation::Archivee) {
            $session->setFormation($formation);

            // Une formation sans tarif intra ne se vend qu'en inter.
            if ($formation->getTarifIntraHT() === null && $formation->getTarifInterHT() !== null) {
                $session->setFormule(FormuleSession::Inter);
            }
        }

        $form = $this->createForm(SessionFormationType::class, $session, ['creation' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->suivi->creer($session, $this->auteur());
            $this->addFlash('succes', sprintf('Session %s créée.', $session->getReference()));

            return $this->redirectToRoute('app_admin_session_voir', ['id' => $session->getId()]);
        }

        return $this->render('admin/session/form.html.twig', [
            'session' => $session,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: '_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(SessionFormation $session, EntreeJournalRepository $journal, EntityManagerInterface $em): Response
    {
        // Les participants que l'on peut encore inscrire : ni archivés, ni déjà inscrits.
        $inscrits = array_map(static fn(Inscription $i) => $i->getParticipant(), $session->inscrits());
        $disponibles = array_filter(
            $em->getRepository(Participant::class)->findBy(['archive' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            static fn(Participant $p) => !in_array($p, $inscrits, true),
        );

        return $this->render('admin/session/voir.html.twig', [
            'session' => $session,
            'disponibles' => $disponibles,
            'journal' => $journal->pour(SessionFormation::TYPE_JOURNAL, $session->getId()),
            'champs' => SessionFormation::CHAMPS,
        ]);
    }

    #[Route('/{id}/modifier', name: '_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(SessionFormation $session, Request $request, Emargements $emargements): Response
    {
        $avant = $session->instantane();
        $emargees = $emargements->seancesEmargees($session);

        $form = $this->createForm(SessionFormationType::class, $session);
        $form->handleRequest($request);

        // Une séance déjà émargée est une preuve : on ne la retire pas, on
        // ne la déplace pas.
        if ($form->isSubmitted()) {
            $restantes = [];

            foreach ($session->getSeances() as $seance) {
                $restantes[$seance->getId()] = $seance->libelle();
            }

            foreach ($emargees as $id => $libelle) {
                if (($restantes[$id] ?? null) !== $libelle) {
                    $form->get('seances')->addError(new FormError(sprintf(
                        'La séance du %s a déjà été émargée : elle ne peut être ni retirée ni déplacée.',
                        $libelle,
                    )));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $entree = $this->suivi->modifier($session, $avant, $this->auteur(), $form->get('motif')->getData());

            $this->addFlash('succes', $entree === null
                ? 'Aucune modification à enregistrer.'
                : 'Modifications enregistrées.');

            return $this->redirectToRoute('app_admin_session_voir', ['id' => $session->getId()]);
        }

        return $this->render('admin/session/form.html.twig', [
            'session' => $session,
            'form' => $form,
        ]);
    }

    /**
     * Fait passer la session sur la dernière version du programme. Refusé
     * pour une session terminée ou annulée : ce qui a été dispensé ne se
     * réécrit pas.
     */
    #[Route('/{id}/programme', name: '_programme', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function programme(SessionFormation $session, Request $request): Response
    {
        $this->verifierJeton($request);

        if ($session->estOuverte() && $session->programmeDepasse()) {
            $this->suivi->actualiserProgramme($session, $this->auteur());
            $this->addFlash('succes', sprintf(
                'La session suit maintenant la version %d du programme.',
                $session->getProgramme()->getNumero(),
            ));
        }

        return $this->redirectToRoute('app_admin_session_voir', ['id' => $session->getId()]);
    }

    #[Route('/{id}/inscriptions', name: '_inscrire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function inscrire(SessionFormation $session, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request);

        $participant = $em->find(Participant::class, $request->request->getInt('participant'));

        if ($participant === null) {
            $this->addFlash('erreur', 'Choisissez un participant à inscrire.');
        } else {
            try {
                $this->suivi->inscrire($session, $participant, $this->auteur());
                $this->addFlash('succes', sprintf('%s est inscrit à la session.', $participant->libelle()));
            } catch (DomainException $e) {
                $this->addFlash('erreur', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_session_voir', ['id' => $session->getId(), '_fragment' => 'participants']);
    }

    /** Désistement : l'inscription passe à « Annulée », elle n'est pas effacée. */
    #[Route('/{id}/inscriptions/{inscription}/annuler', name: '_desinscrire', requirements: ['id' => '\d+', 'inscription' => '\d+'], methods: ['POST'])]
    public function desinscrire(SessionFormation $session, int $inscription, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request);

        $inscription = $em->find(Inscription::class, $inscription);

        if ($inscription === null || $inscription->getSession() !== $session) {
            throw $this->createNotFoundException('Inscription introuvable.');
        }

        if ($session->estOuverte() && $inscription->estActive()) {
            $this->suivi->desinscrire($inscription, $this->auteur());
            $this->addFlash('succes', sprintf("L'inscription de %s est annulée.", $inscription->getParticipant()->libelle()));
        }

        return $this->redirectToRoute('app_admin_session_voir', ['id' => $session->getId(), '_fragment' => 'participants']);
    }

    /** « du 03/12/2026 au 04/12/2026 · Confirmée » : une session en une ligne, pour les listes liées. */
    public static function resume(SessionFormation $session): string
    {
        $debut = $session->getDateDebut();
        $fin = $session->getDateFin();

        $dates = match (true) {
            $debut === null => 'à planifier',
            $debut == $fin => $debut->format('d/m/Y'),
            default => sprintf('du %s au %s', $debut->format('d/m/Y'), $fin->format('d/m/Y')),
        };

        return $dates . ' · ' . $session->getStatut()->libelle();
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function auteur(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
