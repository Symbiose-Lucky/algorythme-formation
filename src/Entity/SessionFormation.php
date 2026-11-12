<?php

namespace App\Entity;

use App\Enum\FormuleSession;
use App\Enum\ModaliteSession;
use App\Enum\StatutSession;
use App\Repository\SessionFormationRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une session : une formation du catalogue, dispensée à des dates données.
 *
 * La session retient la version du programme qu'elle dispense
 * (`programme`) : si la fiche de la formation évolue ensuite, on sait
 * toujours ce qui a été réellement enseigné. Ses propres modifications
 * (dates, lieu, statut…) sont consignées dans le journal (EntreeJournal).
 *
 * Nommée SessionFormation pour ne pas être confondue avec la session HTTP.
 */
#[ORM\Entity(repositoryClass: SessionFormationRepository::class)]
#[ORM\Index(name: 'IDX_SESSION_DATES', fields: ['dateDebut', 'dateFin'])]
class SessionFormation
{
    /** Type sous lequel les sessions sont consignées dans le journal. */
    public const string TYPE_JOURNAL = 'session';

    /** Les champs suivis par le journal, avec leur libellé. */
    public const array CHAMPS = [
        'formation' => 'Formation',
        'programme' => 'Programme',
        'formule' => 'Formule',
        'modalite' => 'Modalité',
        'statut' => 'Statut',
        'client' => 'Client',
        'formateurs' => 'Formateurs',
        'lieu' => 'Lieu',
        'effectifMax' => 'Effectif maximum',
        'seances' => 'Séances',
        'participants' => 'Participants',
        'emargement' => 'Émargement',
        'acquis' => 'Évaluation des acquis',
        'notes' => 'Notes internes',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'La formation est obligatoire.')]
    private ?Formation $formation = null;

    /** La version du programme que cette session dispense. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?FormationVersion $programme = null;

    #[ORM\Column(length: 20, enumType: FormuleSession::class)]
    private FormuleSession $formule = FormuleSession::Intra;

    #[ORM\Column(length: 20, enumType: ModaliteSession::class)]
    private ModaliteSession $modalite = ModaliteSession::Presentiel;

    #[ORM\Column(length: 20, enumType: StatutSession::class)]
    private StatutSession $statut = StatutSession::Projet;

    /** Le commanditaire d'une session intra. Vide pour une session inter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Client $client = null;

    /**
     * @var Collection<int, Formateur>
     */
    #[ORM\ManyToMany(targetEntity: Formateur::class)]
    #[ORM\JoinTable(name: 'session_formation_formateur')]
    #[ORM\OrderBy(['nom' => 'ASC', 'prenom' => 'ASC'])]
    private Collection $formateurs;

    /**
     * Toutes les inscriptions, annulées comprises.
     *
     * @var Collection<int, Inscription>
     */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'session', cascade: ['persist'])]
    private Collection $inscriptions;

    /** Adresse de la salle, ou « classe virtuelle ». */
    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $lieu = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $effectifMax = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    /**
     * Premier et dernier jour, repris des séances à chaque enregistrement
     * (recalerDates) : ils servent à trier et filtrer les listes.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dateFin = null;

    /**
     * @var Collection<int, Seance>
     */
    #[ORM\OneToMany(targetEntity: Seance::class, mappedBy: 'session', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['jour' => 'ASC', 'debut' => 'ASC'])]
    #[Assert\Valid]
    private Collection $seances;

    #[ORM\Column]
    private DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->seances = new ArrayCollection();
        $this->formateurs = new ArrayCollection();
        $this->inscriptions = new ArrayCollection();
        $this->creeLe = new DateTimeImmutable();
    }

    /** « S-0042 » : la référence portée par les documents de la session. */
    public function getReference(): string
    {
        return sprintf('S-%04d', (int)$this->id);
    }

    /**
     * L'état de la session tel que le journal le compare et l'affiche :
     * des valeurs lisibles, une par champ de CHAMPS.
     *
     * @return array<string, string|int|null>
     */
    public function instantane(): array
    {
        return [
            'formation' => $this->formation?->getNom(),
            'programme' => $this->programme === null ? null : 'Version ' . $this->programme->getNumero(),
            'formule' => $this->formule->libelle(),
            'modalite' => $this->modalite->libelle(),
            'statut' => $this->statut->libelle(),
            'client' => $this->client?->getNom() ?? '',
            'formateurs' => self::liste(array_map(static fn(Formateur $f) => $f->libelle(), $this->formateurs->toArray())),
            'lieu' => $this->lieu,
            'effectifMax' => $this->effectifMax,
            'seances' => implode("\n", array_map(static fn(Seance $s) => $s->libelle(), $this->seancesTriees())),
            'participants' => self::liste(array_map(
                static fn(Inscription $i) => $i->getParticipant()->libelle(),
                $this->inscrits(),
            )),
            'notes' => $this->notes,
        ];
    }

    /**
     * Les inscriptions en cours, par nom de participant.
     *
     * @return list<Inscription>
     */
    public function inscrits(): array
    {
        return array_values(array_filter(
            $this->inscriptionsTriees(),
            static fn(Inscription $i) => $i->estActive(),
        ));
    }

    /**
     * Toutes les inscriptions : en cours d'abord, puis annulées, par nom.
     *
     * @return list<Inscription>
     */
    public function inscriptionsTriees(): array
    {
        $inscriptions = $this->inscriptions->toArray();

        usort($inscriptions, static fn(Inscription $a, Inscription $b) => [!$a->estActive(), mb_strtolower($a->getParticipant()->libelle())]
            <=> [!$b->estActive(), mb_strtolower($b->getParticipant()->libelle())]);

        return $inscriptions;
    }

    public function inscriptionDe(Participant $participant): ?Inscription
    {
        foreach ($this->inscriptions as $inscription) {
            if ($inscription->getParticipant() === $participant) {
                return $inscription;
            }
        }

        return null;
    }

    /** Vrai quand l'effectif maximum est atteint. Sans maximum, jamais. */
    public function estComplete(): bool
    {
        return $this->effectifMax !== null && count($this->inscrits()) >= $this->effectifMax;
    }

    /**
     * @param array<int, string> $noms
     */
    private static function liste(array $noms): string
    {
        sort($noms, SORT_FLAG_CASE | SORT_STRING);

        return implode("\n", $noms);
    }

    /** À appeler avant chaque enregistrement. */
    public function recalerDates(): void
    {
        $seances = $this->seancesTriees();

        $this->dateDebut = $seances === [] ? null : $seances[0]->getJour();
        $this->dateFin = $seances === [] ? null : end($seances)->getJour();
    }

    /**
     * Les séances dans l'ordre chronologique, y compris celles qui
     * viennent d'être ajoutées et ne sont pas encore en base.
     *
     * @return list<Seance>
     */
    public function seancesTriees(): array
    {
        $seances = $this->seances->toArray();
        usort($seances, static fn(Seance $a, Seance $b) => strcmp($a->ordre(), $b->ordre()));

        return $seances;
    }

    /** Volume horaire planifié, en heures. */
    public function getHeuresPlanifiees(): float
    {
        $minutes = 0;

        foreach ($this->seances as $seance) {
            $minutes += max(0, $seance->minutes());
        }

        return round($minutes / 60, 2);
    }

    /**
     * Vrai quand les séances ne couvrent pas la durée du programme : à
     * corriger avant la session, les documents remis annonçant cette durée.
     */
    public function dureeIncoherente(): bool
    {
        $prevue = $this->formation?->getDureeHeures();

        return $prevue !== null
            && !$this->seances->isEmpty()
            && abs($this->getHeuresPlanifiees() - $prevue) > 0.01;
    }

    /** Vrai si la fiche de la formation a changé depuis la version retenue. */
    public function programmeDepasse(): bool
    {
        return $this->programme !== null
            && $this->formation !== null
            && $this->programme->getNumero() < $this->formation->getVersion();
    }

    /** Une session terminée ou annulée ne change plus de programme. */
    public function estOuverte(): bool
    {
        return in_array($this->statut, [StatutSession::Projet, StatutSession::Confirmee], true);
    }

    /**
     * Une session en projet peut rester sans dates ; confirmée ou
     * terminée, elle a forcément des séances.
     */
    #[Assert\Callback]
    public function validerSeances(ExecutionContextInterface $contexte): void
    {
        if (in_array($this->statut, [StatutSession::Confirmee, StatutSession::Terminee], true)
            && $this->seances->isEmpty()) {
            $contexte->buildViolation('Une session confirmée ou terminée doit avoir au moins une séance.')
                ->atPath('statut')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(?Formation $formation): static
    {
        $this->formation = $formation;

        return $this;
    }

    public function getProgramme(): ?FormationVersion
    {
        return $this->programme;
    }

    public function setProgramme(FormationVersion $programme): static
    {
        $this->programme = $programme;

        return $this;
    }

    public function getFormule(): FormuleSession
    {
        return $this->formule;
    }

    public function setFormule(FormuleSession $formule): static
    {
        $this->formule = $formule;

        return $this;
    }

    public function getModalite(): ModaliteSession
    {
        return $this->modalite;
    }

    public function setModalite(ModaliteSession $modalite): static
    {
        $this->modalite = $modalite;

        return $this;
    }

    public function getStatut(): StatutSession
    {
        return $this->statut;
    }

    public function setStatut(StatutSession $statut): static
    {
        $this->statut = $statut;

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

    /**
     * @return Collection<int, Formateur>
     */
    public function getFormateurs(): Collection
    {
        return $this->formateurs;
    }

    public function addFormateur(Formateur $formateur): static
    {
        if (!$this->formateurs->contains($formateur)) {
            $this->formateurs->add($formateur);
        }

        return $this;
    }

    public function removeFormateur(Formateur $formateur): static
    {
        $this->formateurs->removeElement($formateur);

        return $this;
    }

    /**
     * @return Collection<int, Inscription>
     */
    public function getInscriptions(): Collection
    {
        return $this->inscriptions;
    }

    public function addInscription(Inscription $inscription): static
    {
        if (!$this->inscriptions->contains($inscription)) {
            $this->inscriptions->add($inscription);
        }

        return $this;
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

    public function getEffectifMax(): ?int
    {
        return $this->effectifMax;
    }

    public function setEffectifMax(?int $effectifMax): static
    {
        $this->effectifMax = $effectifMax;

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

    public function getDateDebut(): ?DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateFin(): ?DateTimeImmutable
    {
        return $this->dateFin;
    }

    /**
     * @return Collection<int, Seance>
     */
    public function getSeances(): Collection
    {
        return $this->seances;
    }

    public function addSeance(Seance $seance): static
    {
        if (!$this->seances->contains($seance)) {
            $this->seances->add($seance);
            $seance->setSession($this);
        }

        return $this;
    }

    public function removeSeance(Seance $seance): static
    {
        $this->seances->removeElement($seance);

        return $this;
    }

    public function getCreeLe(): DateTimeImmutable
    {
        return $this->creeLe;
    }
}
