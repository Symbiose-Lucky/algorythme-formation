<?php

namespace App\Service;

use App\Entity\Emargement;
use App\Entity\Formateur;
use App\Entity\Inscription;
use App\Entity\Seance;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Enum\EtatEmargement;
use App\Enum\StatutSession;
use App\Repository\EmargementRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Seul point d'écriture des émargements : signature en ligne par la
 * personne, ou saisie par un administrateur.
 */
class Emargements
{
    /** Taille maximale d'un tracé de signature (image PNG encodée). */
    private const int TAILLE_MAX = 400_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmargementRepository   $emargements,
        private readonly Journal                $journal,
    )
    {
    }

    /**
     * Une séance s'émarge à partir de son heure de début (heure de Paris),
     * jamais à l'avance, et pas si la session est annulée.
     */
    public function estOuverte(Seance $seance): bool
    {
        if ($seance->getSession()->getStatut() === StatutSession::Annulee) {
            return false;
        }

        return $this->debut($seance) <= new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    }

    public function debut(Seance $seance): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $seance->getJour()->format('Y-m-d') . ' ' . $seance->getDebut()->format('H:i'),
            new DateTimeZone('Europe/Paris'),
        );
    }

    /**
     * La personne signe sa présence depuis son lien personnel.
     *
     * @param string $image Le tracé, en data URL PNG.
     *
     * @throws DomainException Si la séance n'est pas ouverte, déjà émargée, ou le tracé invalide.
     */
    public function signer(
        Seance       $seance,
        ?Inscription $inscription,
        ?Formateur   $formateur,
        string       $image,
        ?string      $ip,
        ?string      $navigateur,
    ): Emargement
    {
        if (!$this->estOuverte($seance)) {
            throw new DomainException("Cette séance n'est pas encore ouverte à la signature.");
        }

        if ($inscription !== null && !$inscription->estActive()) {
            throw new DomainException("Votre inscription à cette session a été annulée.");
        }

        if ($this->trouver($seance, $inscription, $formateur) !== null) {
            throw new DomainException('Votre présence à cette séance est déjà enregistrée.');
        }

        if (!$this->traceValide($image)) {
            throw new DomainException('Signez dans le cadre avant de valider.');
        }

        $emargement = Emargement::signe($seance, $inscription, $formateur, $image, $ip, $navigateur);

        $this->em->persist($emargement);
        $this->em->flush();

        return $emargement;
    }

    /**
     * Un administrateur note une présence (feuille papier signée) ou une
     * absence. Consigné dans le journal de la session.
     *
     * @throws DomainException Si la séance n'a pas commencé, ou si la personne a signé en ligne.
     */
    public function saisir(
        Seance         $seance,
        ?Inscription   $inscription,
        ?Formateur     $formateur,
        EtatEmargement $etat,
        ?User          $auteur,
    ): void
    {
        if (!$this->estOuverte($seance)) {
            throw new DomainException("Cette séance n'a pas encore commencé.");
        }

        $emargement = $this->trouver($seance, $inscription, $formateur);
        $avant = $emargement?->getEtat()->libelle() ?? 'non émargé';

        if ($emargement === null) {
            $emargement = Emargement::saisi($seance, $inscription, $formateur, $etat, $auteur?->getEmail());
            $this->em->persist($emargement);
        } elseif ($emargement->estEnLigne()) {
            throw new DomainException(sprintf('%s a signé en ligne : sa présence ne se modifie pas.', $emargement->personne()));
        } else {
            $emargement->corriger($etat, $auteur?->getEmail());
        }

        $this->em->flush();

        $ligne = sprintf('%s, %s : ', $emargement->personne(), $seance->libelle());

        $this->journal->modification(
            SessionFormation::TYPE_JOURNAL,
            $seance->getSession()->getId(),
            ['emargement' => $ligne . $avant],
            ['emargement' => $ligne . $etat->libelle() . ' (saisie)'],
            $auteur,
        );
    }

    /**
     * Les séances d'une session qui portent au moins un émargement, avec
     * leur libellé : elles ne peuvent plus être retirées ni déplacées.
     *
     * @return array<int, string> [idSéance => « 10/12/2026 09:00 – 12:30 »]
     */
    public function seancesEmargees(SessionFormation $session): array
    {
        $emargees = [];

        foreach (array_keys($this->emargements->deLaSession($session)) as $id) {
            foreach ($session->getSeances() as $seance) {
                if ($seance->getId() === $id) {
                    $emargees[$id] = $seance->libelle();
                }
            }
        }

        return $emargees;
    }

    private function trouver(Seance $seance, ?Inscription $inscription, ?Formateur $formateur): ?Emargement
    {
        if (($inscription === null) === ($formateur === null)) {
            throw new \LogicException('Un émargement concerne un participant ou un formateur.');
        }

        return $this->emargements->findOneBy($inscription !== null
            ? ['seance' => $seance, 'inscription' => $inscription]
            : ['seance' => $seance, 'formateur' => $formateur]);
    }

    /**
     * Un PNG en data URL, de taille raisonnable. Un cadre laissé vide est
     * écarté côté navigateur ; ici, on refuse ce qui n'est pas une image.
     */
    private function traceValide(string $image): bool
    {
        return strlen($image) <= self::TAILLE_MAX
            && preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+=*$#', $image) === 1
            && strlen($image) > 200;
    }
}
