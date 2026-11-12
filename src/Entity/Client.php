<?php

namespace App\Entity;

use App\Enum\TypeClient;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un client : l'entreprise, l'organisme ou le particulier qui commande
 * une formation. Ses collaborateurs formés sont des Participant.
 */
#[ORM\Entity]
class Client implements Journalisable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: TypeClient::class)]
    private TypeClient $type = TypeClient::Entreprise;

    /** Raison sociale, ou nom et prénom d'un particulier. */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 200)]
    private string $nom = '';

    #[ORM\Column(length: 14)]
    #[Assert\Regex(pattern: '/^(\d{14})?$/', message: 'Un SIRET compte 14 chiffres.')]
    private string $siret = '';

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $adresse = '';

    #[ORM\Column(length: 10)]
    #[Assert\Length(max: 10)]
    private string $codePostal = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $ville = '';

    /** L'interlocuteur chez le client : celui qui reçoit convention et convocations. */
    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    private string $contactNom = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $contactFonction = '';

    #[ORM\Column(length: 180)]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    private string $contactEmail = '';

    #[ORM\Column(length: 30)]
    #[Assert\Length(max: 30)]
    private string $contactTelephone = '';

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
        return 'client';
    }

    public static function champs(): array
    {
        return [
            'type' => 'Type',
            'nom' => 'Nom',
            'siret' => 'SIRET',
            'adresse' => 'Adresse',
            'codePostal' => 'Code postal',
            'ville' => 'Ville',
            'contactNom' => 'Interlocuteur',
            'contactFonction' => "Fonction de l'interlocuteur",
            'contactEmail' => "Email de l'interlocuteur",
            'contactTelephone' => "Téléphone de l'interlocuteur",
            'notes' => 'Notes internes',
            'archive' => 'Archivé',
        ];
    }

    public function instantane(): array
    {
        return [
            'type' => $this->type->libelle(),
            'nom' => $this->nom,
            'siret' => $this->siret,
            'adresse' => $this->adresse,
            'codePostal' => $this->codePostal,
            'ville' => $this->ville,
            'contactNom' => $this->contactNom,
            'contactFonction' => $this->contactFonction,
            'contactEmail' => $this->contactEmail,
            'contactTelephone' => $this->contactTelephone,
            'notes' => $this->notes,
            'archive' => $this->archive ? 'Oui' : 'Non',
        ];
    }

    public function libelle(): string
    {
        return $this->nom;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): TypeClient
    {
        return $this->type;
    }

    public function setType(TypeClient $type): static
    {
        $this->type = $type;

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

    public function getSiret(): string
    {
        return $this->siret;
    }

    /** Les espaces de présentation (« 123 456 789 00012 ») sont retirés. */
    public function setSiret(?string $siret): static
    {
        $this->siret = preg_replace('/\s+/', '', (string)$siret) ?? '';

        return $this;
    }

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = trim((string)$adresse);

        return $this;
    }

    public function getCodePostal(): string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = trim((string)$codePostal);

        return $this;
    }

    public function getVille(): string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = trim((string)$ville);

        return $this;
    }

    public function getContactNom(): string
    {
        return $this->contactNom;
    }

    public function setContactNom(?string $contactNom): static
    {
        $this->contactNom = trim((string)$contactNom);

        return $this;
    }

    public function getContactFonction(): string
    {
        return $this->contactFonction;
    }

    public function setContactFonction(?string $contactFonction): static
    {
        $this->contactFonction = trim((string)$contactFonction);

        return $this;
    }

    public function getContactEmail(): string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = mb_strtolower(trim((string)$contactEmail));

        return $this;
    }

    public function getContactTelephone(): string
    {
        return $this->contactTelephone;
    }

    public function setContactTelephone(?string $contactTelephone): static
    {
        $this->contactTelephone = trim((string)$contactTelephone);

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
