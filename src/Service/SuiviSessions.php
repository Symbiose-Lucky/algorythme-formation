<?php

namespace App\Service;

use App\Entity\EntreeJournal;
use App\Entity\Inscription;
use App\Entity\Participant;
use App\Entity\SessionFormation;
use App\Entity\User;
use App\Repository\FormationVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use LogicException;

/**
 * Seul point d'écriture des sessions : tout enregistrement passe par ici
 * et laisse une entrée dans le journal.
 */
class SuiviSessions
{
    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly FormationVersionRepository $versions,
        private readonly Journal                    $journal,
    )
    {
    }

    /**
     * Crée la session, sur la version actuelle du programme de sa formation.
     */
    public function creer(SessionFormation $session, ?User $auteur): EntreeJournal
    {
        $this->alignerProgramme($session);
        $session->recalerDates();

        $this->em->persist($session);
        $this->em->flush();

        return $this->journal->creation(
            SessionFormation::TYPE_JOURNAL,
            $session->getId(),
            $session->instantane(),
            $auteur,
        );
    }

    /**
     * Enregistre les modifications d'une session.
     *
     * @param array<string, mixed> $avant SessionFormation::instantane() pris avant la modification.
     *
     * @return EntreeJournal|null Null si rien n'a changé.
     */
    public function modifier(SessionFormation $session, array $avant, ?User $auteur, ?string $motif = null): ?EntreeJournal
    {
        $session->recalerDates();
        $this->em->flush();

        return $this->journal->modification(
            SessionFormation::TYPE_JOURNAL,
            $session->getId(),
            $avant,
            $session->instantane(),
            $auteur,
            $motif,
        );
    }

    /**
     * Fait passer la session sur la version actuelle du programme, quand la
     * fiche de la formation a évolué depuis sa création.
     */
    public function actualiserProgramme(SessionFormation $session, ?User $auteur): ?EntreeJournal
    {
        $avant = $session->instantane();
        $this->alignerProgramme($session);

        return $this->modifier($session, $avant, $auteur, 'Programme actualisé');
    }

    /**
     * Inscrit un participant, ou rétablit son inscription s'il s'était
     * désisté. L'inscription retient son client du moment.
     *
     * @throws DomainException Si la session est complète ou fermée.
     */
    public function inscrire(SessionFormation $session, Participant $participant, ?User $auteur): void
    {
        if (!$session->estOuverte()) {
            throw new DomainException('Les inscriptions sont closes : la session est terminée ou annulée.');
        }

        $inscription = $session->inscriptionDe($participant);

        if ($inscription?->estActive()) {
            return;
        }

        if ($session->estComplete()) {
            throw new DomainException(sprintf(
                "La session est complète : l'effectif maximum (%d) est atteint. Augmentez-le pour inscrire quelqu'un d'autre.",
                $session->getEffectifMax(),
            ));
        }

        $avant = $session->instantane();

        if ($inscription === null) {
            $session->addInscription(new Inscription($session, $participant));
        } else {
            $inscription->retablir();
        }

        $this->modifier($session, $avant, $auteur);
    }

    /** Annule une inscription (désistement). La ligne est conservée. */
    public function desinscrire(Inscription $inscription, ?User $auteur): void
    {
        $session = $inscription->getSession();
        $avant = $session->instantane();

        $inscription->annuler();

        $this->modifier($session, $avant, $auteur, 'Désistement');
    }

    private function alignerProgramme(SessionFormation $session): void
    {
        $formation = $session->getFormation();
        $version = $formation === null ? null : $this->versions->derniere($formation);

        if ($version === null) {
            throw new LogicException('Une session ne peut porter que sur une formation enregistrée.');
        }

        $session->setProgramme($version);
    }
}
