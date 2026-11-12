<?php

namespace App\Controller\Admin;

use App\Controller\EmargementController as LiensEmargement;
use App\Entity\Formateur;
use App\Entity\Inscription;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\EtatEmargement;
use App\Repository\EmargementRepository;
use App\Service\Emargements;
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
 * Suivi de l'émargement d'une session : qui a signé quelle séance, envoi
 * des liens personnels, saisie d'une présence ou d'une absence, et feuille
 * d'émargement à imprimer.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sessions/{id}/emargement', name: 'app_admin_emargement', requirements: ['id' => '\d+'])]
class EmargementController extends AbstractController
{
    public function __construct(
        private readonly EmargementRepository $depot,
        private readonly Emargements          $emargements,
        private readonly Jetons               $jetons,
    )
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(SessionFormation $session): Response
    {
        return $this->render('admin/emargement/index.html.twig', $this->donnees($session));
    }

    /** La feuille d'émargement, avec les signatures : à imprimer ou à montrer telle quelle. */
    #[Route('/feuille', name: '_feuille', methods: ['GET'])]
    public function feuille(SessionFormation $session): Response
    {
        return $this->render('admin/emargement/feuille.html.twig', $this->donnees($session));
    }

    #[Route('/saisir', name: '_saisir', methods: ['POST'])]
    public function saisir(SessionFormation $session, Request $request): Response
    {
        $this->verifierJeton($request);

        $etat = EtatEmargement::tryFrom($request->request->getString('etat'));
        $cle = $request->request->getString('personne');
        $seance = null;

        foreach ($session->getSeances() as $candidate) {
            if ($candidate->getId() === $request->request->getInt('seance')) {
                $seance = $candidate;
            }
        }

        $personne = array_find($this->personnes($session), static fn(array $p) => $p['cle'] === $cle);

        if ($etat === null || $seance === null || $personne === null) {
            throw $this->createNotFoundException('Saisie invalide.');
        }

        try {
            $this->emargements->saisir($seance, $personne['inscription'], $personne['formateur'], $etat, $this->auteur());
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emargement', ['id' => $session->getId()]);
    }

    /** Envoie à chacun son lien personnel d'émargement. */
    #[Route('/envoyer', name: '_envoyer', methods: ['POST'])]
    public function envoyer(SessionFormation $session, Request $request, MailService $mails): Response
    {
        $this->verifierJeton($request);

        $envoyes = 0;
        $echecs = [];
        $sansEmail = [];

        foreach ($this->personnes($session) as $personne) {
            if (!$personne['active']) {
                continue;
            }

            if ($personne['email'] === '') {
                $sansEmail[] = $personne['nom'];
                continue;
            }

            try {
                $mails->sendLienEmargement($personne['email'], $session, $personne['lien']);
                $envoyes++;
            } catch (Throwable) {
                $echecs[] = $personne['nom'];
            }
        }

        if ($envoyes > 0) {
            $this->addFlash('succes', sprintf('%d lien(s) envoyé(s) par email.', $envoyes));
        }

        if ($sansEmail !== []) {
            $this->addFlash('erreur', 'Sans adresse email, à prévenir autrement : ' . implode(', ', $sansEmail) . '.');
        }

        if ($echecs !== []) {
            $this->addFlash('erreur', "L'envoi a échoué pour : " . implode(', ', $echecs) . '.');
        }

        return $this->redirectToRoute('app_admin_emargement', ['id' => $session->getId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(SessionFormation $session): array
    {
        $seances = [];

        foreach ($session->seancesTriees() as $seance) {
            $seances[] = ['seance' => $seance, 'ouverte' => $this->emargements->estOuverte($seance)];
        }

        return [
            'session' => $session,
            'seances' => $seances,
            'personnes' => $this->personnes($session),
            'tableau' => $this->depot->deLaSession($session),
        ];
    }

    /**
     * Les personnes qui émargent : les formateurs, puis les participants.
     * Un participant désisté reste listé s'il a déjà émargé une séance.
     *
     * @return list<array{cle: string, nom: string, role: string, email: string, lien: string, active: bool, inscription: ?Inscription, formateur: ?Formateur}>
     */
    private function personnes(SessionFormation $session): array
    {
        $tableau = $this->depot->deLaSession($session);
        $personnes = [];

        foreach ($session->getFormateurs() as $formateur) {
            $personnes[] = [
                'cle' => 'f' . $formateur->getId(),
                'nom' => $formateur->libelle(),
                'role' => 'Formateur',
                'email' => $formateur->getEmail(),
                'lien' => $this->generateUrl('app_emargement_formateur', [
                    'session' => $session->getId(),
                    'formateur' => $formateur->getId(),
                    'jeton' => $this->jetons->pour(LiensEmargement::sujetFormateur($session, $formateur)),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'active' => true,
                'inscription' => null,
                'formateur' => $formateur,
            ];
        }

        foreach ($session->inscriptionsTriees() as $inscription) {
            $cle = 'p' . $inscription->getId();
            $aEmarge = array_any($tableau, static fn(array $parSeance) => isset($parSeance[$cle]));

            if (!$inscription->estActive() && !$aEmarge) {
                continue;
            }

            $personnes[] = [
                'cle' => $cle,
                'nom' => $inscription->getParticipant()->libelle(),
                'role' => $inscription->estActive() ? 'Participant' : 'Participant (inscription annulée)',
                'email' => $inscription->getParticipant()->getEmail(),
                'lien' => $this->generateUrl('app_emargement_participant', [
                    'id' => $inscription->getId(),
                    'jeton' => $this->jetons->pour(LiensEmargement::sujetParticipant($inscription)),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'active' => $inscription->estActive(),
                'inscription' => $inscription,
                'formateur' => null,
            ];
        }

        return $personnes;
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
