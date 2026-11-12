<?php

namespace App\Repository;

use App\Entity\Formation;
use App\Enum\StatutFormation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Formation>
 */
class FormationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Formation::class);
    }

    /**
     * Les formations du catalogue public, triées par adresse.
     *
     * @return list<Formation>
     */
    public function publiees(): array
    {
        return $this->findBy(['statut' => StatutFormation::Publiee], ['slug' => 'ASC']);
    }

    /**
     * Les formations de l'admin, par nom. Sans statut demandé, les
     * archivées sont laissées de côté.
     *
     * @return list<Formation>
     */
    public function rechercher(?StatutFormation $statut, string $recherche = ''): array
    {
        $requete = $this->createQueryBuilder('f')->orderBy('f.nom', 'ASC');

        if ($statut !== null) {
            $requete->andWhere('f.statut = :statut')->setParameter('statut', $statut);
        } else {
            $requete->andWhere('f.statut != :archivee')->setParameter('archivee', StatutFormation::Archivee);
        }

        if ($recherche !== '') {
            $requete
                ->andWhere('f.nom LIKE :recherche OR f.code LIKE :recherche OR f.domaine LIKE :recherche')
                ->setParameter('recherche', '%' . addcslashes($recherche, '%_\\') . '%');
        }

        return $requete->getQuery()->getResult();
    }

    /**
     * Le nombre de formations par statut : ['brouillon' => 2, 'publiee' => 14…].
     *
     * @return array<string, int>
     */
    public function compterParStatut(): array
    {
        $lignes = $this->createQueryBuilder('f')
            ->select('f.statut AS statut, COUNT(f.id) AS nombre')
            ->groupBy('f.statut')
            ->getQuery()
            ->getArrayResult();

        $nombres = [];

        foreach (StatutFormation::cases() as $statut) {
            $nombres[$statut->value] = 0;
        }

        foreach ($lignes as $ligne) {
            $statut = $ligne['statut'] instanceof StatutFormation ? $ligne['statut']->value : $ligne['statut'];
            $nombres[$statut] = (int)$ligne['nombre'];
        }

        return $nombres;
    }

    /**
     * Les domaines déjà saisis, proposés à la saisie pour éviter les
     * variantes d'orthographe.
     *
     * @return list<string>
     */
    public function domaines(): array
    {
        return $this->createQueryBuilder('f')
            ->select('DISTINCT f.domaine')
            ->where("f.domaine != ''")
            ->orderBy('f.domaine', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
