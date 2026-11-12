<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une personne formée (stagiaire). Rattachée à son employeur quand elle
 * vient par une entreprise ; sans client, c'est un particulier.
 */
#[ORM\Entity]
#[ORM\Index(name: 'IDX_PARTICIPANT_NOM', fields: ['nom', 'prenom'])]
class Participant implements Journalisable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100)]
    private string $prenom = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100)]
    private string $nom = '';

    #[ORM\Column(length: 180)]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 30)]
    #[Assert\Length(max: 30)]
    private string $telephone = '';

    #[ORM\Column(length: 150)]
    #[Assert\Length(max: 150)]
    private string $fonction = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Client $client = null;

    /**
     * Aménagements à prévoir (situation de handicap, contrainte
     * particulière). Donnée sensible : son contenu n'est jamais recopié
     * dans le journal, qui note seulement qu'elle est renseignée.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $amenagements = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    #[ORM\Column]
    private bool $archive = false;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new DateTimeImmutable();
    }

    public static function typeJournal(): string
    {
        return 'participant';
    }

    public static function champs(): array
    {
        return [
            'prenom' => 'Prénom',
            'nom' => 'Nom',
            'email' => 'Email',
            'telephone' => 'Téléphone',
            'fonction' => 'Fonction',
            'client' => 'Client',
            'amenagements' => 'Aménagements à prévoir',
            'notes' => 'Notes internes',
            'archive' => 'Archivé',
        ];
    }

    public function instantane(): array
    {
        return [
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'fonction' => $this->fonction,
            'client' => $this->client?->getNom() ?? '',
            // Le journal sait qu'il y a eu un changement (l'empreinte diffère),
            // pas ce qui a été écrit.
            'amenagements' => $this->amenagements === '' ? '' : 'Renseignés (empreinte ' . substr(sha1($this->amenagements), 0, 6) . ')',
            'notes' => $this->notes,
            'archive' => $this->archive ? 'Oui' : 'Non',
        ];
    }

    /** « Dupont Marie » : le nom d'abord, pour des listes triées lisibles. */
    public function libelle(): string
    {
        return trim($this->nom . ' ' . $this->prenom);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = trim((string)$prenom);

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = trim((string)$nom);

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string)$email));

        return $this;
    }

    public function getTelephone(): string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = trim((string)$telephone);

        return $this;
    }

    public function getFonction(): string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = trim((string)$fonction);

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAmenagements(): string
    {
        return $this->amenagements;
    }

    public function setAmenagements(?string $amenagements): static
    {
        $this->amenagements = trim(str_replace(["\r\n", "\r"], "\n", (string)$amenagements));

        return $this;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = trim(str_replace(["\r\n", "\r"], "\n", (string)$notes));

        return $this;
    }

    public function isArchive(): bool
    {
        return $this->archive;
    }

    public function setArchive(bool $archive): static
    {
        $this->archive = $archive;

        return $this;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
