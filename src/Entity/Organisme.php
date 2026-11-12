<?php

namespace App\Entity;

use App\Repository\OrganismeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * L'identité de l'organisme de formation, telle qu'elle figure sur les
 * documents générés (convention, convocation, certificat de réalisation) :
 * mentions légales, représentant, régime de TVA, signature.
 *
 * Il n'y a qu'une ligne : voir OrganismeRepository::unique().
 */
#[ORM\Entity(repositoryClass: OrganismeRepository::class)]
class Organisme
{
    public const string TYPE_JOURNAL = 'organisme';

    public const array CHAMPS = [
        'nom' => 'Raison sociale',
        'formeJuridique' => 'Forme juridique',
        'adresse' => 'Adresse',
        'codePostal' => 'Code postal',
        'ville' => 'Ville',
        'siret' => 'SIRET',
        'rcs' => 'RCS',
        'nda' => "Numéro de déclaration d'activité",
        'ndaRegion' => 'Région de la déclaration',
        'telephone' => 'Téléphone',
        'email' => 'Email',
        'representantNom' => 'Représentant légal',
        'representantQualite' => 'Qualité du représentant',
        'tauxTva' => 'Taux de TVA',
        'conditionsReglement' => 'Conditions de règlement',
        'conditionsAnnulation' => "Conditions d'annulation et de report",
        'signature' => 'Signature',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'La raison sociale est obligatoire.')]
    #[Assert\Length(max: 200)]
    private string $nom = '';

    /** « SAS au capital de 1 000 euros ». */
    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    private string $formeJuridique = '';

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $adresse = '';

    #[ORM\Column(length: 10)]
    #[Assert\Length(max: 10)]
    private string $codePostal = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $ville = '';

    #[ORM\Column(length: 14)]
    #[Assert\Regex(pattern: '/^(\d{14})?$/', message: 'Un SIRET compte 14 chiffres.')]
    private string $siret = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $rcs = '';

    #[ORM\Column(length: 20)]
    #[Assert\Length(max: 20)]
    private string $nda = '';

    /** La région dont le préfet a enregistré la déclaration d'activité. */
    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $ndaRegion = '';

    #[ORM\Column(length: 30)]
    #[Assert\Length(max: 30)]
    private string $telephone = '';

    #[ORM\Column(length: 180)]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    private string $representantNom = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $representantQualite = '';

    /**
     * En pourcentage. 0 : organisme exonéré (article 261-4-4° a du CGI).
     * Null : pas encore renseigné, aucune convention ne peut être générée.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 30)]
    private ?float $tauxTva = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $conditionsReglement = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $conditionsAnnulation = '';

    /** La signature du représentant, apposée sur les documents générés (data URL PNG). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    /**
     * Ce qui manque pour établir un document qui engage l'organisme.
     *
     * @return list<string>
     */
    public function manques(): array
    {
        $manques = [];

        foreach ([
                     'adresse' => $this->adresse,
                     'siret' => $this->siret,
                     'nda' => $this->nda,
                     'representantNom' => $this->representantNom,
                     'representantQualite' => $this->representantQualite,
                 ] as $champ => $valeur) {
            if ($valeur === '') {
                $manques[] = self::CHAMPS[$champ];
            }
        }

        if ($this->tauxTva === null) {
            $manques[] = self::CHAMPS['tauxTva'];
        }

        return $manques;
    }

    /**
     * @return array<string, string|null>
     */
    public function instantane(): array
    {
        return [
            'nom' => $this->nom,
            'formeJuridique' => $this->formeJuridique,
            'adresse' => $this->adresse,
            'codePostal' => $this->codePostal,
            'ville' => $this->ville,
            'siret' => $this->siret,
            'rcs' => $this->rcs,
            'nda' => $this->nda,
            'ndaRegion' => $this->ndaRegion,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'representantNom' => $this->representantNom,
            'representantQualite' => $this->representantQualite,
            'tauxTva' => $this->tauxTva === null ? '' : rtrim(rtrim(number_format($this->tauxTva, 2, ',', ''), '0'), ',') . ' %',
            'conditionsReglement' => $this->conditionsReglement,
            'conditionsAnnulation' => $this->conditionsAnnulation,
            'signature' => $this->signature === null ? '' : 'Enregistrée (empreinte ' . substr(sha1($this->signature), 0, 6) . ')',
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFormeJuridique(): string
    {
        return $this->formeJuridique;
    }

    public function setFormeJuridique(?string $formeJuridique): static
    {
        $this->formeJuridique = trim((string)$formeJuridique);

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

    public function getSiret(): string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = preg_replace('/\s+/', '', (string)$siret) ?? '';

        return $this;
    }

    public function getRcs(): string
    {
        return $this->rcs;
    }

    public function setRcs(?string $rcs): static
    {
        $this->rcs = trim((string)$rcs);

        return $this;
    }

    public function getNda(): string
    {
        return $this->nda;
    }

    public function setNda(?string $nda): static
    {
        $this->nda = preg_replace('/\s+/', '', (string)$nda) ?? '';

        return $this;
    }

    public function getNdaRegion(): string
    {
        return $this->ndaRegion;
    }

    public function setNdaRegion(?string $ndaRegion): static
    {
        $this->ndaRegion = trim((string)$ndaRegion);

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string)$email));

        return $this;
    }

    public function getRepresentantNom(): string
    {
        return $this->representantNom;
    }

    public function setRepresentantNom(?string $representantNom): static
    {
        $this->representantNom = trim((string)$representantNom);

        return $this;
    }

    public function getRepresentantQualite(): string
    {
        return $this->representantQualite;
    }

    public function setRepresentantQualite(?string $representantQualite): static
    {
        $this->representantQualite = trim((string)$representantQualite);

        return $this;
    }

    public function getTauxTva(): ?float
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?float $tauxTva): static
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function getConditionsReglement(): string
    {
        return $this->conditionsReglement;
    }

    public function setConditionsReglement(?string $conditionsReglement): static
    {
        $this->conditionsReglement = trim(str_replace(["\r\n", "\r"], "\n", (string)$conditionsReglement));

        return $this;
    }

    public function getConditionsAnnulation(): string
    {
        return $this->conditionsAnnulation;
    }

    public function setConditionsAnnulation(?string $conditionsAnnulation): static
    {
        $this->conditionsAnnulation = trim(str_replace(["\r\n", "\r"], "\n", (string)$conditionsAnnulation));

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): static
    {
        $this->signature = $signature;

        return $this;
    }
}
