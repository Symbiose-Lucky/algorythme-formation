<?php

namespace App\Repository;

use App\Entity\Formation;
use App\Entity\FormationVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormationVersion>
 */
class FormationVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormationVersion::class);
    }

    /**
     * L'historique d'une formation, de la version la plus récente à la première.
     *
     * @return list<FormationVersion>
     */
    public function historique(Formation $formation): array
    {
        return $this->findBy(['formation' => $formation], ['numero' => 'DESC']);
    }

    public function derniere(Formation $formation): ?FormationVersion
    {
        return $this->findOneBy(['formation' => $formation], ['numero' => 'DESC']);
    }
}
