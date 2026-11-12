<?php

namespace App\Service;

use App\Entity\EntreeJournal;
use App\Entity\Journalisable;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seul point d'écriture des fiches de gestion (formateurs, clients,
 * participants) : tout enregistrement passe par ici et laisse une entrée
 * dans le journal.
 */
class Registre
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Journal                $journal,
    )
    {
    }

    public function creer(Journalisable $fiche, ?User $auteur): EntreeJournal
    {
        $this->em->persist($fiche);
        $this->em->flush();

        return $this->journal->creation($fiche::typeJournal(), $fiche->getId(), $fiche->instantane(), $auteur);
    }

    /**
     * @param array<string, mixed> $avant instantane() pris avant la modification.
     *
     * @return EntreeJournal|null Null si rien n'a changé.
     */
    public function modifier(Journalisable $fiche, array $avant, ?User $auteur, ?string $motif = null): ?EntreeJournal
    {
        $this->em->flush();

        return $this->journal->modification(
            $fiche::typeJournal(),
            $fiche->getId(),
            $avant,
            $fiche->instantane(),
            $auteur,
            $motif,
        );
    }
}
