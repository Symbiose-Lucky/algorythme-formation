<?php

namespace App\Entity;

use App\Enum\StatutFormation;
use App\Repository\FormationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une formation du catalogue : son programme et tout ce que l'indicateur 1
 * de Qualiopi demande de rendre public (objectifs, prérequis, durée,
 * tarifs, modalités et délais d'accès, méthodes, évaluation, accessibilité).
 *
 * Les textes longs gardent la convention de saisie de SmartOF : une ligne
 * par point, « • » en tête ; le programme s'écrit « JOUR n – titre »,
 * « ◦ séquence (1h30) », « • point ».
 *
 * Chaque enregistrement qui change quelque chose produit une
 * FormationVersion (voir HistoriqueFormations) : on peut ainsi montrer le
 * programme tel qu'il était à une date donnée.
 */
#[ORM\Entity(repositoryClass: FormationRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_FORMATION_CODE', fields: ['code'])]
#[ORM\UniqueConstraint(name: 'UNIQ_FORMATION_SLUG', fields: ['slug'])]
#[ORM\UniqueConstraint(name: 'UNIQ_FORMATION_SMARTOF', fields: ['smartofUid'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code est déjà utilisé par une autre formation.')]
#[UniqueEntity(fields: ['slug'], message: 'Cette adresse est déjà utilisée par une autre formation.')]
class Formation
{
    /**
     * Les champs suivis par l'historique, avec leur libellé. L'ordre est
     * celui de l'affichage d'une version.
     */
    public const array CHAMPS = [
        'nom' => 'Intitulé',
        'code' => 'Code',
        'slug' => 'Adresse de la page',
        'statut' => 'Statut',
        'domaine' => 'Domaine',
        'accroche' => 'Accroche',
        'enPageAccueil' => "En page d'accueil",
        'dureeHeures' => 'Durée',
        'lieu' => 'Lieu',
        'tarifInterHT' => 'Tarif inter HT',
        'tarifIntraHT' => 'Tarif intra HT',
        'objectifs' => 'Objectifs',
        'publicVise' => 'Public visé',
        'prerequis' => 'Prérequis',
        'programme' => 'Programme',
        'ceQuiChange' => 'Ce que ça change',
        'methodes' => 'Méthodes pédagogiques',
        'moyens' => 'Moyens et supports',
        'evaluation' => "Modalités d'évaluation et de suivi",
        'acces' => "Modalités et délais d'accès",
        'accessibilite' => 'Accessibilité aux personnes en situation de handicap',
        'profilFormateurs' => 'Profil des formateurs',
        'note' => 'Note de satisfaction',
        'nbFormes' => 'Personnes formées',
    ];

    /** Ce qu'une formation doit contenir pour être publiée. */
    private const array REQUIS_POUR_PUBLIER = ['objectifs', 'publicVise', 'prerequis', 'programme'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code interne court : « PY-FOND », « LLM-GEN ». */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Le code est obligatoire.')]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[A-Z0-9][A-Z0-9-]*$/', message: 'Majuscules, chiffres et tirets uniquement.')]
    private string $code = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: "L'intitulé est obligatoire.")]
    #[Assert\Length(max: 200)]
    private string $nom = '';

    /**
     * Adresse de la page publique. Fixée à la création : renommer la
     * formation ne la change pas, les liens déjà diffusés restent valables.
     */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', message: 'Minuscules, chiffres et tirets uniquement.')]
    private string $slug = '';

    #[ORM\Column(length: 20, enumType: StatutFormation::class)]
    private StatutFormation $statut = StatutFormation::Brouillon;

    #[ORM\Column(length: 120)]
    #[Assert\Length(max: 120)]
    private string $domaine = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $accroche = '';

    #[ORM\Column]
    private bool $enPageAccueil = false;

    /** 7 heures par journée de formation. */
    #[ORM\Column(nullable: true)]
    #[Assert\NotBlank(message: 'La durée est obligatoire.')]
    #[Assert\Positive]
    private ?float $dureeHeures = null;

    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    private string $lieu = '';

    /** Prix HT d'une session, en centimes. Null : pas de tarif pour cette formule. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $tarifInterHT = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $tarifIntraHT = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $objectifs = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $publicVise = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $prerequis = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $programme = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $ceQuiChange = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $methodes = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $moyens = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $evaluation = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $acces = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $accessibilite = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $profilFormateurs = '';

    /** Note de satisfaction sur 5. Null : pas encore de note. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 5)]
    private ?float $note = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $nbFormes = null;

    /** Identifiant du produit SmartOF d'origine, pour une formation importée. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $smartofUid = null;

    /** Numéro de la dernière FormationVersion enregistrée. */
    #[ORM\Column]
    private int $version = 0;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    #[ORM\Column]
    private DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->creeLe = new DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
    }

    /**
     * L'état de la formation, tel que l'historique le conserve : une valeur
     * simple par champ de CHAMPS.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function instantane(): array
    {
        $instantane = [];

        foreach (array_keys(self::CHAMPS) as $champ) {
            $valeur = $this->$champ;
            $instantane[$champ] = $valeur instanceof StatutFormation ? $valeur->value : $valeur;
        }

        return $instantane;
    }

    /** Appelée par HistoriqueFormations à chaque nouvelle version. */
    public function nouvelleVersion(DateTimeImmutable $date): int
    {
        $this->modifieLe = $date;

        return ++$this->version;
    }

    /**
     * Réservée à l'import : une formation reprise d'un autre outil garde
     * ses dates d'origine, pour que « programme mis à jour le… » reste vrai.
     */
    public function reprendreDates(DateTimeImmutable $creeLe, DateTimeImmutable $modifieLe): void
    {
        $this->creeLe = $creeLe;
        $this->modifieLe = $modifieLe;
    }

    /**
     * Un brouillon peut rester incomplet ; une formation publiée doit
     * porter son programme et les informations que le public attend.
     */
    #[Assert\Callback]
    public function validerPublication(ExecutionContextInterface $contexte): void
    {
        if ($this->statut !== StatutFormation::Publiee) {
            return;
        }

        foreach (self::REQUIS_POUR_PUBLIER as $champ) {
            if (trim($this->$champ) === '') {
                $contexte->buildViolation('À renseigner avant de publier la formation.')
                    ->atPath($champ)
                    ->addViolation();
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = mb_strtoupper(trim((string)$code));

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = trim((string)$slug);

        return $this;
    }

    public function getStatut(): StatutFormation
    {
        return $this->statut;
    }

    public function setStatut(StatutFormation $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDomaine(): string
    {
        return $this->domaine;
    }

    public function setDomaine(?string $domaine): static
    {
        $this->domaine = trim((string)$domaine);

        return $this;
    }

    public function getAccroche(): string
    {
        return $this->accroche;
    }

    public function setAccroche(?string $accroche): static
    {
        $this->accroche = trim((string)$accroche);

        return $this;
    }

    public function isEnPageAccueil(): bool
    {
        return $this->enPageAccueil;
    }

    public function setEnPageAccueil(bool $enPageAccueil): static
    {
        $this->enPageAccueil = $enPageAccueil;

        return $this;
    }

    public function getDureeHeures(): ?float
    {
        return $this->dureeHeures;
    }

    public function setDureeHeures(?float $dureeHeures): static
    {
        $this->dureeHeures = $dureeHeures;

        return $this;
    }

    /** Nombre de journées de 7 heures : 21 h → 3. */
    public function getDureeJours(): ?float
    {
        return $this->dureeHeures === null ? null : round($this->dureeHeures / 7, 2);
    }

    public function getLieu(): string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = trim((string)$lieu);

        return $this;
    }

    public function getTarifInterHT(): ?int
    {
        return $this->tarifInterHT;
    }

    public function setTarifInterHT(?int $tarifInterHT): static
    {
        $this->tarifInterHT = $tarifInterHT;

        return $this;
    }

    public function getTarifIntraHT(): ?int
    {
        return $this->tarifIntraHT;
    }

    public function setTarifIntraHT(?int $tarifIntraHT): static
    {
        $this->tarifIntraHT = $tarifIntraHT;

        return $this;
    }

    public function getObjectifs(): string
    {
        return $this->objectifs;
    }

    public function setObjectifs(?string $objectifs): static
    {
        $this->objectifs = self::texte($objectifs);

        return $this;
    }

    public function getPublicVise(): string
    {
        return $this->publicVise;
    }

    public function setPublicVise(?string $publicVise): static
    {
        $this->publicVise = self::texte($publicVise);

        return $this;
    }

    public function getPrerequis(): string
    {
        return $this->prerequis;
    }

    public function setPrerequis(?string $prerequis): static
    {
        $this->prerequis = self::texte($prerequis);

        return $this;
    }

    public function getProgramme(): string
    {
        return $this->programme;
    }

    public function setProgramme(?string $programme): static
    {
        $this->programme = self::texte($programme);

        return $this;
    }

    public function getCeQuiChange(): string
    {
        return $this->ceQuiChange;
    }

    public function setCeQuiChange(?string $ceQuiChange): static
    {
        $this->ceQuiChange = self::texte($ceQuiChange);

        return $this;
    }

    public function getMethodes(): string
    {
        return $this->methodes;
    }

    public function setMethodes(?string $methodes): static
    {
        $this->methodes = self::texte($methodes);

        return $this;
    }

    public function getMoyens(): string
    {
        return $this->moyens;
    }

    public function setMoyens(?string $moyens): static
    {
        $this->moyens = self::texte($moyens);

        return $this;
    }

    public function getEvaluation(): string
    {
        return $this->evaluation;
    }

    public function setEvaluation(?string $evaluation): static
    {
        $this->evaluation = self::texte($evaluation);

        return $this;
    }

    public function getAcces(): string
    {
        return $this->acces;
    }

    public function setAcces(?string $acces): static
    {
        $this->acces = self::texte($acces);

        return $this;
    }

    public function getAccessibilite(): string
    {
        return $this->accessibilite;
    }

    public function setAccessibilite(?string $accessibilite): static
    {
        $this->accessibilite = self::texte($accessibilite);

        return $this;
    }

    public function getProfilFormateurs(): string
    {
        return $this->profilFormateurs;
    }

    public function setProfilFormateurs(?string $profilFormateurs): static
    {
        $this->profilFormateurs = self::texte($profilFormateurs);

        return $this;
    }

    public function getNote(): ?float
    {
        return $this->note;
    }

    public function setNote(?float $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getNbFormes(): ?int
    {
        return $this->nbFormes;
    }

    public function setNbFormes(?int $nbFormes): static
    {
        $this->nbFormes = $nbFormes;

        return $this;
    }

    public function getSmartofUid(): ?string
    {
        return $this->smartofUid;
    }

    public function setSmartofUid(?string $smartofUid): static
    {
        $this->smartofUid = $smartofUid;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): DateTimeImmutable
    {
        return $this->modifieLe;
    }

    /**
     * Les fins de ligne sont ramenées à « \n » : un texte ressaisi à
     * l'identique depuis le navigateur (« \r\n ») ne compte pas comme une
     * modification.
     */
    private static function texte(?string $saisie): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)$saisie));
    }
}
