<?php

namespace App\Entity;

use App\Enum\StatutInscription;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'inscription d'un participant à une session.
 *
 * Elle retient le client du participant au moment de l'inscription : si
 * la personne change d'employeur ensuite, on sait toujours pour qui elle
 * a été formée. Un désistement n'efface rien : l'inscription est annulée.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_INSCRIPTION', fields: ['session', 'participant'])]
class Inscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private SessionFormation $session;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Participant $participant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Client $client;

    #[ORM\Column(length: 20, enumType: StatutInscription::class)]
    private StatutInscription $statut = StatutInscription::Inscrit;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    public function __construct(SessionFormation $session, Participant $participant)
    {
        $this->session = $session;
        $this->participant = $participant;
        $this->client = $participant->getClient();
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): SessionFormation
    {
        return $this->session;
    }

    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getStatut(): StatutInscription
    {
        return $this->statut;
    }

    public function estActive(): bool
    {
        return $this->statut === StatutInscription::Inscrit;
    }

    public function annuler(): void
    {
        $this->statut = StatutInscription::Annulee;
    }

    public function retablir(): void
    {
        $this->statut = StatutInscription::Inscrit;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
