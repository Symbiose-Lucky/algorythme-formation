<?php

namespace App\Entity;

use App\Repository\EntreeJournalRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne du journal des modifications : qui a changé quoi, quand, et
 * pourquoi. Commun à tous les objets de gestion (sessions, puis
 * formateurs, clients…), repérés par leur type et leur identifiant.
 *
 * Une entrée ne se modifie pas et ne se supprime pas : c'est la trace
 * que l'audit Qualiopi peut demander.
 *
 * Les formations ont en plus leurs FormationVersion, qui gardent la fiche
 * entière à chaque version : le journal, lui, ne garde que les écarts.
 */
#[ORM\Entity(repositoryClass: EntreeJournalRepository::class)]
#[ORM\Index(name: 'IDX_JOURNAL_OBJET', fields: ['type', 'objetId'])]
class EntreeJournal
{
    public const string CREATION = 'creation';
    public const string MODIFICATION = 'modification';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column]
    private int $objetId;

    #[ORM\Column(length: 20)]
    private string $action;

    /**
     * Par champ modifié, sa valeur avant et après. À la création, « avant »
     * vaut null et seuls les champs renseignés figurent.
     *
     * @var array<string, array{avant: mixed, apres: mixed}>
     */
    #[ORM\Column]
    private array $changements;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $auteur;

    /** Conservé à part : le journal reste lisible si le compte est supprimé. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $auteurEmail;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    /**
     * @param array<string, array{avant: mixed, apres: mixed}> $changements
     */
    public function __construct(
        string  $type,
        int     $objetId,
        string  $action,
        array   $changements,
        ?User   $auteur,
        ?string $motif,
    )
    {
        $this->type = $type;
        $this->objetId = $objetId;
        $this->action = $action;
        $this->changements = $changements;
        $this->auteur = $auteur;
        $this->auteurEmail = $auteur?->getEmail();
        $this->motif = $motif;
        $this->creeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getObjetId(): int
    {
        return $this->objetId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function estCreation(): bool
    {
        return $this->action === self::CREATION;
    }

    /**
     * @return array<string, array{avant: mixed, apres: mixed}>
     */
    public function getChangements(): array
    {
        return $this->changements;
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
