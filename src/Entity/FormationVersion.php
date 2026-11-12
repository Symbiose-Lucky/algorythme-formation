<?php

namespace App\Entity;

use App\Repository\FormationVersionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'état complet d'une formation à un instant donné, avec l'auteur de
 * l'enregistrement et, s'il l'a précisé, la raison du changement.
 *
 * Une version ne se modifie pas et ne se supprime pas : c'est la preuve,
 * pour l'audit Qualiopi, de ce qui était proposé au public à une date
 * donnée et de la façon dont le programme a évolué.
 */
#[ORM\Entity(repositoryClass: FormationVersionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_FORMATION_VERSION_NUMERO', fields: ['formation', 'numero'])]
class FormationVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Formation $formation;

    #[ORM\Column]
    private int $numero;

    /**
     * Formation::instantane() au moment de l'enregistrement.
     *
     * @var array<string, string|int|float|bool|null>
     */
    #[ORM\Column]
    private array $contenu;

    /**
     * Clés de Formation::CHAMPS qui ont changé depuis la version
     * précédente. Vide pour la première version.
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $modifications;

    /** Renseigné par l'auteur : « mise à jour suite aux retours de la session de mars ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $auteur;

    /** Conservé à part : l'historique reste lisible si le compte est supprimé. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $auteurEmail;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    /**
     * @param array<string, string|int|float|bool|null> $contenu
     * @param list<string> $modifications
     */
    public function __construct(
        Formation         $formation,
        int               $numero,
        array             $contenu,
        array             $modifications,
        ?User             $auteur,
        ?string           $motif,
        DateTimeImmutable $creeLe,
    )
    {
        $this->formation = $formation;
        $this->numero = $numero;
        $this->contenu = $contenu;
        $this->modifications = $modifications;
        $this->auteur = $auteur;
        $this->auteurEmail = $auteur?->getEmail();
        $this->motif = $motif;
        $this->creeLe = $creeLe;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): Formation
    {
        return $this->formation;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public function getContenu(): array
    {
        return $this->contenu;
    }

    /**
     * @return list<string>
     */
    public function getModifications(): array
    {
        return $this->modifications;
    }

    /**
     * Les libellés des champs modifiés, pour l'affichage.
     *
     * @return list<string>
     */
    public function getLibellesModifications(): array
    {
        return array_map(
            static fn(string $champ) => Formation::CHAMPS[$champ] ?? $champ,
            $this->modifications,
        );
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getAuteurEmail(): ?string
    {
        return $this->auteurEmail;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
