<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un créneau d'une session : une demi-journée le plus souvent
 * (9 h – 12 h 30, 13 h 30 – 17 h). C'est l'unité de l'émargement.
 */
#[ORM\Entity]
#[ORM\Index(name: 'IDX_SEANCE_JOUR', fields: ['jour'])]
class Seance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?SessionFormation $session = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    private ?DateTimeImmutable $jour = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull(message: "L'heure de début est obligatoire.")]
    private ?DateTimeImmutable $debut = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull(message: "L'heure de fin est obligatoire.")]
    private ?DateTimeImmutable $fin = null;

    #[Assert\Callback]
    public function validerHoraires(ExecutionContextInterface $contexte): void
    {
        if ($this->debut !== null && $this->fin !== null && $this->minutes() <= 0) {
            $contexte->buildViolation('La fin doit suivre le début.')->atPath('fin')->addViolation();
        }
    }

    /** Durée du créneau, en minutes. */
    public function minutes(): int
    {
        if ($this->debut === null || $this->fin === null) {
            return 0;
        }

        // Seule l'heure compte : la date portée par un champ TIME varie
        // selon qu'il vient du formulaire ou de la base.
        return (int)$this->fin->format('G') * 60 + (int)$this->fin->format('i')
            - (int)$this->debut->format('G') * 60 - (int)$this->debut->format('i');
    }

    /** « 01/12/2026 09:00 – 12:30 », pour le journal. */
    public function libelle(): string
    {
        return sprintf(
            '%s %s – %s',
            $this->jour?->format('d/m/Y') ?? '?',
            $this->debut?->format('H:i') ?? '?',
            $this->fin?->format('H:i') ?? '?',
        );
    }

    /** Clé de tri : jour puis heure de début. */
    public function ordre(): string
    {
        return ($this->jour?->format('Y-m-d') ?? '') . ' ' . ($this->debut?->format('H:i') ?? '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?SessionFormation
    {
        return $this->session;
    }

    public function setSession(?SessionFormation $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getJour(): ?DateTimeImmutable
    {
        return $this->jour;
    }

    public function setJour(?DateTimeImmutable $jour): static
    {
        $this->jour = $jour;

        return $this;
    }

    public function getDebut(): ?DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?DateTimeImmutable $debut): static
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?DateTimeImmutable $fin): static
    {
        $this->fin = $fin;

        return $this;
    }
}
