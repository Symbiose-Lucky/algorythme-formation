<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Formateur;
use App\Entity\Formation;
use App\Entity\Participant;
use App\Entity\SessionFormation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les objets auxquels un document peut être rattaché. Le type est celui
 * du journal : un dépôt ou une signature y laisse une entrée, sur la fiche
 * de l'objet.
 */
class Rattachements
{
    /** type => [classe, route de la fiche dans l'admin] */
    private const array TYPES = [
        'session' => [SessionFormation::class, 'app_admin_session_voir'],
        'formation' => [Formation::class, 'app_admin_formation_voir'],
        'formateur' => [Formateur::class, 'app_admin_formateur_voir'],
        'client' => [Client::class, 'app_admin_client_voir'],
        'participant' => [Participant::class, 'app_admin_participant_voir'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    )
    {
    }

    public function existe(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public function objet(string $type, int $id): ?object
    {
        return $this->existe($type) ? $this->em->find(self::TYPES[$type][0], $id) : null;
    }

    /** La route de la fiche de l'objet, dans l'admin. */
    public function route(string $type): string
    {
        return self::TYPES[$type][1];
    }

    /** « Session S-0003 », « Formateur Paul Durand » : pour les liens de retour. */
    public function libelle(object $objet): string
    {
        return match (true) {
            $objet instanceof SessionFormation => 'Session ' . $objet->getReference(),
            $objet instanceof Formation => $objet->getNom(),
            $objet instanceof Formateur, $objet instanceof Client, $objet instanceof Participant => $objet->libelle(),
            default => '',
        };
    }

    /**
     * Les personnes que l'on fait le plus souvent signer pour cet objet :
     * proposées d'un clic au moment de désigner les signataires.
     *
     * @return list<array{nom: string, email: string, role: string}>
     */
    public function contacts(object $objet): array
    {
        $contacts = [];

        $client = static function (?Client $client) use (&$contacts): void {
            if ($client !== null && $client->getContactEmail() !== '') {
                $contacts[] = [
                    'nom' => $client->getContactNom() ?: $client->getNom(),
                    'email' => $client->getContactEmail(),
                    'role' => $client->getNom(),
                ];
            }
        };

        if ($objet instanceof SessionFormation) {
            $client($objet->getClient());

            foreach ($objet->getFormateurs() as $formateur) {
                if ($formateur->getEmail() !== '') {
                    $contacts[] = ['nom' => $formateur->libelle(), 'email' => $formateur->getEmail(), 'role' => 'Formateur'];
                }
            }

            foreach ($objet->inscrits() as $inscription) {
                $participant = $inscription->getParticipant();

                if ($participant->getEmail() !== '') {
                    $contacts[] = [
                        'nom' => $participant->getPrenom() . ' ' . $participant->getNom(),
                        'email' => $participant->getEmail(),
                        'role' => 'Participant',
                    ];
                }
            }
        } elseif ($objet instanceof Client) {
            $client($objet);
        } elseif ($objet instanceof Formateur && $objet->getEmail() !== '') {
            $contacts[] = ['nom' => $objet->libelle(), 'email' => $objet->getEmail(), 'role' => 'Formateur'];
        } elseif ($objet instanceof Participant) {
            if ($objet->getEmail() !== '') {
                $contacts[] = [
                    'nom' => $objet->getPrenom() . ' ' . $objet->getNom(),
                    'email' => $objet->getEmail(),
                    'role' => 'Participant',
                ];
            }

            $client($objet->getClient());
        }

        return $contacts;
    }
}
