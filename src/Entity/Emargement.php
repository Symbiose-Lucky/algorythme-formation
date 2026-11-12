<?php

namespace App\Entity;

use App\Enum\EtatEmargement;
use App\Repository\EmargementRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La présence d'une personne (participant ou formateur) à une séance.
 *
 * Deux origines :
 * - « en ligne » : la personne a signé elle-même depuis son lien
 *   personnel. La signature, l'heure, l'adresse IP et le navigateur sont
 *   conservés ; la ligne ne se modifie plus.
 * - « saisie » : un administrateur a noté la présence ou l'absence (feuille
 *   papier signée en salle, absence constatée). Son adresse est conservée,
 *   et lui seul peut corriger sa saisie.
 *
 * C'est la preuve d'assiduité que demande Qualiopi : rien ne s'efface.
 */
#[ORM\Entity(repositoryClass: EmargementRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EMARGEMENT_PARTICIPANT', fields: ['seance', 'inscription'])]
#[ORM\UniqueConstraint(name: 'UNIQ_EMARGEMENT_FORMATEUR', fields: ['seance', 'formateur'])]
class Emargement
{
    public const string EN_LIGNE = 'en_ligne';
    public const string SAISIE = 'saisie';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Seance $seance;

    /** Le participant, par son inscription à la session. Null pour un formateur. */
    #[ORM\ManyToOne]
    private ?Inscription $inscription;

    #[ORM\ManyToOne]
    private ?Formateur $formateur;

    #[ORM\Column(length: 20, enumType: EtatEmargement::class)]
    private EtatEmargement $etat;

    #[ORM\Column(length: 20)]
    private string $origine;

    /** Le tracé de la signature, en image PNG (data URL). Null pour une saisie. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column]
    private DateTimeImmutable $horodatage;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $navigateur = null;

    /** L'administrateur qui a saisi la ligne. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $saisiPar = null;

    private function __construct(Seance $seance, ?Inscription $inscription, ?Formateur $formateur, EtatEmargement $etat, string $origine)
    {
        $this->seance = $seance;
        $this->inscription = $inscription;
        $this->formateur = $formateur;
        $this->etat = $etat;
        $this->origine = $origine;
        $this->horodatage = new DateTimeImmutable();
    }

    public static function signe(
        Seance       $seance,
        ?Inscription $inscription,
        ?Formateur   $formateur,
        string       $signature,
        ?string      $ip,
        ?string      $navigateur,
    ): self
    {
        $emargement = new self($seance, $inscription, $formateur, EtatEmargement::Present, self::EN_LIGNE);
        $emargement->signature = $signature;
        $emargement->ip = $ip;
        $emargement->navigateur = $navigateur === null ? null : mb_substr($navigateur, 0, 255);

        return $emargement;
    }

    public static function saisi(
        Seance         $seance,
        ?Inscription   $inscription,
        ?Formateur     $formateur,
        EtatEmargement $etat,
        ?string        $saisiPar,
    ): self
    {
        $emargement = new self($seance, $inscription, $formateur, $etat, self::SAISIE);
        $emargement->saisiPar = $saisiPar;

        return $emargement;
    }

    /** Corrige une saisie. Une signature en ligne, elle, ne se corrige pas. */
    public function corriger(EtatEmargement $etat, ?string $saisiPar): void
    {
        if ($this->estEnLigne()) {
            throw new \LogicException('Une signature en ligne ne se modifie pas.');
        }

        $this->etat = $etat;
        $this->saisiPar = $saisiPar;
        $this->horodatage = new DateTimeImmutable();
    }

    public function estEnLigne(): bool
    {
        return $this->origine === self::EN_LIGNE;
    }

    public function estPresent(): bool
    {
        return $this->etat === EtatEmargement::Present;
    }

    /** Le nom de la personne émargée. */
    public function personne(): string
    {
        return $this->inscription?->getParticipant()->libelle() ?? $this->formateur?->libelle() ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeance(): Seance
    {
        return $this->seance;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function getFormateur(): ?Formateur
    {
        return $this->formateur;
    }

    public function getEtat(): EtatEmargement
    {
        return $this->etat;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getHorodatage(): DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getNavigateur(): ?string
    {
        return $this->navigateur;
    }

    public function getSaisiPar(): ?string
    {
        return $this->saisiPar;
    }
}
