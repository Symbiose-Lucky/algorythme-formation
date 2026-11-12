<?php

namespace App\Entity;

use App\Enum\TypeEvaluation;
use App\Repository\EvaluationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un questionnaire rempli autour d'une session.
 *
 * Au sujet d'un participant (par son inscription) : positionnement à
 * l'entrée, satisfaction à chaud ou à froid, remplis par lui, ou
 * évaluation des acquis, remplie par le formateur ou saisie par un
 * administrateur. Sans inscription : la satisfaction d'un client ou du
 * financeur de la session, ou le bilan d'un de ses formateurs.
 *
 * Les réponses sont conservées avec l'énoncé des questions tel qu'il a été
 * posé : la preuve reste lisible même si le questionnaire ou le programme
 * change ensuite. Un questionnaire rempli par la personne elle-même ne se modifie
 * plus ; une évaluation des acquis peut être corrigée, et la correction est
 * consignée au journal de la session.
 */
#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EVALUATION', fields: ['inscription', 'type'])]
class Evaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SessionFormation $session;

    /** Le participant concerné. Null pour l'avis d'un client ou d'un formateur. */
    #[ORM\ManyToOne]
    private ?Inscription $inscription;

    /** Le client ou le financeur (une fiche client aussi) qui donne son avis sur la session. */
    #[ORM\ManyToOne]
    private ?Client $client;

    #[ORM\Column(length: 20, enumType: TypeEvaluation::class)]
    private TypeEvaluation $type;

    /**
     * Une ligne par question : clé, énoncé, genre (niveau, note, choix,
     * texte, acquis), valeur donnée et son libellé.
     *
     * @var list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}>
     */
    #[ORM\Column]
    private array $reponses;

    /** Le formateur qui a évalué les acquis depuis son lien personnel, ou qui donne son bilan de la session. */
    #[ORM\ManyToOne]
    private ?Formateur $formateur = null;

    /** L'administrateur qui a saisi l'évaluation des acquis. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $saisiPar = null;

    #[ORM\Column]
    private DateTimeImmutable $horodatage;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $navigateur = null;

    /**
     * @param list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}> $reponses
     */
    public function __construct(
        SessionFormation $session,
        TypeEvaluation   $type,
        array            $reponses,
        ?Inscription     $inscription = null,
        ?Client          $client = null,
    )
    {
        $this->session = $session;
        $this->inscription = $inscription;
        $this->client = $client;
        $this->type = $type;
        $this->reponses = $reponses;
        $this->horodatage = new DateTimeImmutable();
    }

    /** Retient qui a rempli, et d'où. */
    public function attribuer(?Formateur $formateur, ?string $saisiPar, ?string $ip, ?string $navigateur): void
    {
        $this->formateur = $formateur;
        $this->saisiPar = $saisiPar;
        $this->ip = $ip;
        $this->navigateur = $navigateur === null ? null : mb_substr($navigateur, 0, 255);
    }

    /**
     * Corrige une évaluation des acquis. Les réponses d'un participant,
     * elles, ne se corrigent pas.
     *
     * @param list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}> $reponses
     */
    public function corriger(array $reponses): void
    {
        if ($this->type->estQuestionnaire()) {
            throw new \LogicException("Les réponses d'un participant ne se modifient pas.");
        }

        $this->reponses = $reponses;
        $this->horodatage = new DateTimeImmutable();
    }

    /** La valeur donnée à une question, ou null. */
    public function valeur(string $cle): int|string|null
    {
        foreach ($this->reponses as $reponse) {
            if ($reponse['cle'] === $cle) {
                return $reponse['valeur'];
            }
        }

        return null;
    }

    /**
     * Les réponses d'un genre donné.
     *
     * @return list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}>
     */
    public function duGenre(string $genre): array
    {
        return array_values(array_filter($this->reponses, static fn(array $r) => $r['genre'] === $genre));
    }

    /** Qui a rempli : le participant, le client, le formateur, ou l'administrateur. */
    public function auteur(): string
    {
        if ($this->client !== null) {
            return $this->client->getContactNom() !== ''
                ? $this->client->getContactNom() . ' (' . $this->client->getNom() . ')'
                : $this->client->getNom();
        }

        if ($this->type->estQuestionnaire() && $this->inscription !== null) {
            return $this->inscription->getParticipant()->libelle();
        }

        return $this->formateur?->libelle() ?? $this->saisiPar ?? '';
    }

    /** Sur qui porte la réponse : le participant, ou à défaut celui qui donne son avis sur la session. */
    public function sujet(): string
    {
        return $this->inscription?->getParticipant()->libelle() ?? $this->auteur();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): SessionFormation
    {
        return $this->session;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getType(): TypeEvaluation
    {
        return $this->type;
    }

    /**
     * @return list<array{cle: string, question: string, genre: string, valeur: int|string, libelle: string}>
     */
    public function getReponses(): array
    {
        return $this->reponses;
    }

    public function getFormateur(): ?Formateur
    {
        return $this->formateur;
    }

    public function getSaisiPar(): ?string
    {
        return $this->saisiPar;
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
}
