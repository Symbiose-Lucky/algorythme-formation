<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une personne à qui un document est soumis pour signature.
 *
 * Elle reçoit par email un lien personnel (son jeton), y lit le document
 * et signe à la main ; sa signature s'affiche alors dans les zones que
 * l'administrateur a placées pour elle. Sont conservés avec la signature :
 * la date, l'adresse IP, le navigateur, et l'empreinte du fichier signé
 * (portée par le Document).
 *
 * Une fois la signature apposée, la ligne ne change plus.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_SIGNATAIRE_JETON', fields: ['jeton'])]
class Signataire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'signataires')]
    #[ORM\JoinColumn(nullable: false)]
    private Document $document;

    #[ORM\Column(length: 200)]
    private string $nom;

    #[ORM\Column(length: 180)]
    private string $email;

    /** Le secret du lien personnel. */
    #[ORM\Column(length: 64)]
    private string $jeton;

    /**
     * Les zones où s'affiche sa signature. Les coordonnées sont des
     * fractions de la page (0 à 1), depuis son coin supérieur gauche :
     * elles ne dépendent pas de la taille d'affichage.
     *
     * @var list<array{page: int, x: float, y: float, l: float, h: float}>
     */
    #[ORM\Column]
    private array $zones = [];

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $envoyeLe = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $signeLe = null;

    /** Le tracé de la signature, en image PNG (data URL). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $navigateur = null;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    public function __construct(Document $document, string $nom, string $email)
    {
        $this->document = $document;
        $this->nom = $nom;
        $this->email = $email;
        $this->jeton = bin2hex(random_bytes(24));
        $this->creeLe = new DateTimeImmutable();
    }

    public function aSigne(): bool
    {
        return $this->signeLe !== null;
    }

    /** Ses zones ne bougent plus dès que la demande est partie. */
    public function estVerrouille(): bool
    {
        return $this->envoyeLe !== null || $this->aSigne();
    }

    public function signer(string $signature, ?string $ip, ?string $navigateur): void
    {
        if ($this->aSigne()) {
            throw new \LogicException('Ce document est déjà signé par cette personne.');
        }

        $this->signature = $signature;
        $this->signeLe = new DateTimeImmutable();
        $this->ip = $ip;
        $this->navigateur = $navigateur === null ? null : mb_substr($navigateur, 0, 255);
    }

    public function marquerEnvoye(): void
    {
        $this->envoyeLe = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    /**
     * @return list<array{page: int, x: float, y: float, l: float, h: float}>
     */
    public function getZones(): array
    {
        return $this->zones;
    }

    /**
     * @param list<array{page: int, x: float, y: float, l: float, h: float}> $zones
     */
    public function setZones(array $zones): void
    {
        if ($this->estVerrouille()) {
            throw new \LogicException('Les zones ne changent plus une fois la demande envoyée.');
        }

        $this->zones = $zones;
    }

    public function getEnvoyeLe(): ?DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function getSigneLe(): ?DateTimeImmutable
    {
        return $this->signeLe;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getNavigateur(): ?string
    {
        return $this->navigateur;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
