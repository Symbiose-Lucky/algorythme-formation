<?php

namespace App\Repository;

use App\Entity\EntreeJournal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntreeJournal>
 */
class EntreeJournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntreeJournal::class);
    }

    /**
     * Le journal d'un objet, de l'entrée la plus récente à la première.
     *
     * @return list<EntreeJournal>
     */
    public function pour(string $type, int $objetId): array
    {
        return $this->findBy(['type' => $type, 'objetId' => $objetId], ['id' => 'DESC']);
    }
}
