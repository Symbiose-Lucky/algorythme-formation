<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Journalisable;
use App\Entity\Participant;
use App\Entity\SessionFormation;
use App\Form\ClientType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/clients', name: 'app_admin_client')]
class ClientController extends AbstractFicheController
{
    protected function classe(): string
    {
        return Client::class;
    }

    protected function formulaire(): string
    {
        return ClientType::class;
    }

    protected function route(): string
    {
        return 'app_admin_client';
    }

    protected function libelles(): array
    {
        return [
            'pluriel' => 'Clients',
            'sousTitre' => 'Entreprises, organismes et particuliers qui commandent des formations.',
            'nouveau' => 'Nouveau client',
            'modifier' => 'Modifier le client',
            'cree' => 'Client créé.',
            'vide' => 'Aucun client ici pour l\'instant.',
            'recherche' => 'Nom, ville ou interlocuteur',
        ];
    }

    protected function champsRecherche(): array
    {
        return ['f.nom', 'f.ville', 'f.contactNom', 'f.contactEmail', 'f.siret'];
    }

    protected function tri(): array
    {
        return ['f.nom' => 'ASC'];
    }

    protected function colonnes(): array
    {
        return [
            'Type' => static fn(Client $c) => $c->getType()->libelle(),
            'Ville' => static fn(Client $c) => $c->getVille(),
            'Interlocuteur' => static fn(Client $c) => $c->getContactNom(),
            'Email' => static fn(Client $c) => $c->getContactEmail(),
        ];
    }

    protected function listes(Journalisable $fiche): array
    {
        $participants = $this->em->getRepository(Participant::class)
            ->findBy(['client' => $fiche], ['nom' => 'ASC', 'prenom' => 'ASC']);

        $sessions = $this->em->getRepository(SessionFormation::class)
            ->findBy(['client' => $fiche], ['dateDebut' => 'DESC', 'id' => 'DESC']);

        return [
            [
                'titre' => 'Sessions commandées',
                'vide' => 'Aucune session pour ce client.',
                'elements' => array_map(fn(SessionFormation $s) => [
                    'texte' => $s->getReference() . ' · ' . $s->getFormation()->getNom(),
                    'url' => $this->generateUrl('app_admin_session_voir', ['id' => $s->getId()]),
                    'detail' => SessionController::resume($s),
                ], $sessions),
            ],
            [
                'titre' => 'Participants',
                'vide' => 'Aucun participant rattaché à ce client.',
                'elements' => array_map(fn(Participant $p) => [
                    'texte' => $p->libelle(),
                    'url' => $this->generateUrl('app_admin_participant_voir', ['id' => $p->getId()]),
                    'detail' => $p->getFonction() . ($p->isArchive() ? ' (archivé)' : ''),
                ], $participants),
            ],
        ];
    }
}
