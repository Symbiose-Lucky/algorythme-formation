<?php

namespace App\Entity;

use App\Enum\StatutFormateur;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un formateur, salarié ou indépendant. Sa fiche porte ce que Qualiopi
 * demande de pouvoir montrer : son parcours, ses domaines de compétence
 * et les formations qu'il est habilité à animer.
 */
#[ORM\Entity]
class Formateur implements Journalisable
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

    #[ORM\Column(length: 20, enumType: StatutFormateur::class)]
    private StatutFormateur $statut = StatutFormateur::Salarie;

    /** Pour un indépendant : sa société, son SIRET, son numéro de déclaration d'activité. */
    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    private string $societe = '';

    #[ORM\Column(length: 14)]
    #[Assert\Regex(pattern: '/^(\d{14})?$/', message: 'Un SIRET compte 14 chiffres.')]
    private string $siret = '';

    #[ORM\Column(length: 20)]
    #[Assert\Length(max: 20)]
    private string $nda = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $specialites = '';

    /** Diplômes, certifications, expérience : ce qui fonde sa compétence. */
    #[ORM\Column(type: Types::TEXT)]
    private string $parcours = '';

    /**
     * Les formations qu'il est habilité à animer.
     *
     * @var Collection<int, Formation>
     */
    #[ORM\ManyToMany(targetEntity: Formation::class)]
    #[ORM\OrderBy(['nom' => 'ASC'])]
    private Collection $formations;

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    #[ORM\Column]
    private bool $archive = false;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->formations = new ArrayCollection();
        $this->creeLe = new DateTimeImmutable();
    }

    public static function typeJournal(): string
    {
        return 'formateur';
    }

    public static function champs(): array
    {
        return [
            'prenom' => 'Prénom',
            'nom' => 'Nom',
            'email' => 'Email',
            'telephone' => 'Téléphone',
            'statut' => 'Statut',
            'societe' => 'Société',
            'siret' => 'SIRET',
            'nda' => "Numéro de déclaration d'activité",
            'specialites' => 'Domaines de compétence',
            'parcours' => 'Parcours',
            'formations' => 'Formations animées',
            'notes' => 'Notes internes',
            'archive' => 'Archivé',
        ];
    }

    public function instantane(): array
    {
        $formations = array_map(static fn(Formation $f) => $f->getNom(), $this->formations->toArray());
        sort($formations);

        return [
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'statut' => $this->statut->libelle(),
            'societe' => $this->societe,
            'siret' => $this->siret,
            'nda' => $this->nda,
            'specialites' => $this->specialites,
            'parcours' => $this->parcours,
            'formations' => implode("\n", $formations),
            'notes' => $this->notes,
            'archive' => $this->archive ? 'Oui' : 'Non',
        ];
    }

    public function libelle(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
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

    public function getStatut(): StatutFormateur
    {
        return $this->statut;
    }

    public function setStatut(StatutFormateur $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getSociete(): string
    {
        return $this->societe;
    }

    public function setSociete(?string $societe): static
    {
        $this->societe = trim((string)$societe);

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

    public function getNda(): string
    {
        return $this->nda;
    }

    public function setNda(?string $nda): static
    {
        $this->nda = trim((string)$nda);

        return $this;
    }

    public function getSpecialites(): string
    {
        return $this->specialites;
    }

    public function setSpecialites(?string $specialites): static
    {
        $this->specialites = self::texte($specialites);

        return $this;
    }

    public function getParcours(): string
    {
        return $this->parcours;
    }

    public function setParcours(?string $parcours): static
    {
        $this->parcours = self::texte($parcours);

        return $this;
    }

    /**
     * @return Collection<int, Formation>
     */
    public function getFormations(): Collection
    {
        return $this->formations;
    }

    public function addFormation(Formation $formation): static
    {
        if (!$this->formations->contains($formation)) {
            $this->formations->add($formation);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        $this->formations->removeElement($formation);

        return $this;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = self::texte($notes);

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

    private static function texte(?string $saisie): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)$saisie));
    }
}
