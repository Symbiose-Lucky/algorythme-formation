<?php

namespace App\Entity;

use App\Enum\CategorieDocument;
use App\Repository\DocumentRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fichier déposé dans l'admin et rattaché à une session, une
 * formation, un formateur, un client ou un participant (voir
 * App\Service\Rattachements pour les types).
 *
 * Le fichier lui-même est rangé hors du dossier public ; son empreinte
 * SHA-256 est gardée ici : elle prouve que le fichier signé est bien celui
 * qui a été déposé. Un document ne se supprime pas, il s'archive.
 *
 * Un PDF peut être envoyé pour signature : voir Signataire.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(name: 'IDX_DOCUMENT_OBJET', fields: ['objetType', 'objetId'])]
class Document
{
    public const string A_ENVOYER = 'a_envoyer';
    public const string EN_ATTENTE = 'en_attente';
    public const string SIGNE = 'signe';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $objetType;

    #[ORM\Column]
    private int $objetId;

    #[ORM\Column(length: 200)]
    private string $nom;

    #[ORM\Column(length: 30, enumType: CategorieDocument::class)]
    private CategorieDocument $categorie;

    /** Chemin du fichier dans le dossier des documents : « 2026/11/ab12….pdf ». */
    #[ORM\Column(length: 255)]
    private string $fichier;

    #[ORM\Column(length: 255)]
    private string $nomOriginal;

    #[ORM\Column(length: 120)]
    private string $mime;

    #[ORM\Column]
    private int $taille;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column]
    private DateTimeImmutable $deposeLe;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $deposePar;

    #[ORM\Column]
    private bool $archive = false;

    /**
     * Pour un document généré : ce dont il est issu (« convention:client:3 »,
     * « convocation:inscription:12 »). Sert à retrouver la version en vigueur
     * et à archiver la précédente. Null pour un fichier déposé à la main.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $origine = null;

    /**
     * @var Collection<int, Signataire>
     */
    #[ORM\OneToMany(targetEntity: Signataire::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $signataires;

    public function __construct(
        string            $objetType,
        int               $objetId,
        string            $nom,
        CategorieDocument $categorie,
        string            $fichier,
        string            $nomOriginal,
        string            $mime,
        int               $taille,
        string            $empreinte,
        ?string           $deposePar,
        ?string           $origine = null,
    )
    {
        $this->objetType = $objetType;
        $this->objetId = $objetId;
        $this->nom = $nom;
        $this->categorie = $categorie;
        $this->fichier = $fichier;
        $this->nomOriginal = $nomOriginal;
        $this->mime = $mime;
        $this->taille = $taille;
        $this->empreinte = $empreinte;
        $this->deposePar = $deposePar;
        $this->origine = $origine;
        $this->deposeLe = new DateTimeImmutable();
        $this->signataires = new ArrayCollection();
    }

    /** Seul un PDF peut recevoir des zones de signature. */
    public function estPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /**
     * Où en est la signature : null sans signataire, sinon « à envoyer »
     * (au moins une demande pas encore partie), « en attente », ou « signé »
     * quand tous ont signé.
     */
    public function etatSignature(): ?string
    {
        if ($this->signataires->isEmpty()) {
            return null;
        }

        $enAttente = false;

        foreach ($this->signataires as $signataire) {
            if ($signataire->aSigne()) {
                continue;
            }

            if ($signataire->getEnvoyeLe() === null) {
                return self::A_ENVOYER;
            }

            $enAttente = true;
        }

        return $enAttente ? self::EN_ATTENTE : self::SIGNE;
    }

    /** « 1,2 Mo », « 340 Ko ». */
    public function tailleLisible(): string
    {
        return $this->taille >= 1_000_000
            ? number_format($this->taille / 1_000_000, 1, ',', ' ') . ' Mo'
            : max(1, (int)round($this->taille / 1000)) . ' Ko';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjetType(): string
    {
        return $this->objetType;
    }

    public function getObjetId(): int
    {
        return $this->objetId;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getCategorie(): CategorieDocument
    {
        return $this->categorie;
    }

    public function getFichier(): string
    {
        return $this->fichier;
    }

    public function getNomOriginal(): string
    {
        return $this->nomOriginal;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function getDeposeLe(): DateTimeImmutable
    {
        return $this->deposeLe;
    }

    public function getDeposePar(): ?string
    {
        return $this->deposePar;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function isArchive(): bool
    {
        return $this->archive;
    }

    public function setArchive(bool $archive): void
    {
        $this->archive = $archive;
    }

    /**
     * @return Collection<int, Signataire>
     */
    public function getSignataires(): Collection
    {
        return $this->signataires;
    }

    public function addSignataire(Signataire $signataire): void
    {
        if (!$this->signataires->contains($signataire)) {
            $this->signataires->add($signataire);
        }
    }

    public function removeSignataire(Signataire $signataire): void
    {
        $this->signataires->removeElement($signataire);
    }
}
