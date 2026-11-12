<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Inscription;
use App\Entity\Journalisable;
use App\Entity\Participant;
use App\Entity\SessionFormation;
use App\Form\ParticipantType;
use App\Service\SuiviSessions;
use Doctrine\ORM\QueryBuilder;
use DomainException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/participants', name: 'app_admin_participant')]
class ParticipantController extends AbstractFicheController
{
    private SuiviSessions $suivi;

    #[Required]
    public function setSuivi(SuiviSessions $suivi): void
    {
        $this->suivi = $suivi;
    }

    protected function classe(): string
    {
        return Participant::class;
    }

    protected function formulaire(): string
    {
        return ParticipantType::class;
    }

    protected function route(): string
    {
        return 'app_admin_participant';
    }

    protected function libelles(): array
    {
        return [
            'pluriel' => 'Participants',
            'sousTitre' => 'Les personnes formées, rattachées à leur employeur.',
            'nouveau' => 'Nouveau participant',
            'modifier' => 'Modifier le participant',
            'cree' => 'Participant créé.',
            'vide' => 'Aucun participant ici pour l\'instant.',
            'recherche' => 'Nom, email ou client',
        ];
    }

    protected function completerRequete(QueryBuilder $requete): void
    {
        $requete->leftJoin('f.client', 'c')->addSelect('c');
    }

    protected function champsRecherche(): array
    {
        return ['f.nom', 'f.prenom', 'f.email', 'c.nom'];
    }

    protected function tri(): array
    {
        return ['f.nom' => 'ASC', 'f.prenom' => 'ASC'];
    }

    protected function colonnes(): array
    {
        return [
            'Client' => static fn(Participant $p) => $p->getClient()?->getNom() ?? '',
            'Fonction' => static fn(Participant $p) => $p->getFonction(),
            'Email' => static fn(Participant $p) => $p->getEmail(),
        ];
    }

    /** Sur la fiche, les aménagements s'affichent en clair ; le journal, lui, n'en garde pas le texte. */
    protected function details(Journalisable $fiche): array
    {
        return ['amenagements' => $fiche->getAmenagements()] + $fiche->instantane();
    }

    protected function listes(Journalisable $fiche): array
    {
        $inscriptions = $this->em->getRepository(Inscription::class)->findBy(['participant' => $fiche], ['id' => 'DESC']);

        return [[
            'titre' => 'Sessions suivies',
            'vide' => 'Aucune inscription pour ce participant.',
            'elements' => array_map(fn(Inscription $i) => [
                'texte' => $i->getSession()->getReference() . ' · ' . $i->getSession()->getFormation()->getNom(),
                'url' => $this->generateUrl('app_admin_session_voir', ['id' => $i->getSession()->getId()]),
                'detail' => SessionController::resume($i->getSession())
                    . ($i->estActive() ? '' : ' · inscription annulée'),
            ], $inscriptions),
        ]];
    }

    /** `?client=` préremplit l'employeur (bouton de la fiche d'une session ou d'un client). */
    protected function nouvelleFiche(Request $request): Journalisable
    {
        $participant = new Participant();

        $client = $request->query->getInt('client') > 0
            ? $this->em->find(Client::class, $request->query->getInt('client'))
            : null;

        if ($client !== null && !$client->isArchive()) {
            $participant->setClient($client);
        }

        return $participant;
    }

    /**
     * `?session=` : le participant vient d'être créé depuis une session,
     * il y est inscrit dans la foulée et l'on y retourne.
     */
    protected function apresCreation(Journalisable $fiche, Request $request): Response
    {
        $session = $request->query->getInt('session') > 0
            ? $this->em->find(SessionFormation::class, $request->query->getInt('session'))
            : null;

        if ($session === null) {
            return parent::apresCreation($fiche, $request);
        }

        try {
            $this->suivi->inscrire($session, $fiche, $this->auteur());
            $this->addFlash('succes', sprintf('%s est inscrit à la session.', $fiche->libelle()));
        } catch (DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_session_voir', ['id' => $session->getId(), '_fragment' => 'participants']);
    }
}
