<?php

namespace App\Repository;

use App\Entity\Domaine;
use App\Entity\Formation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domaine>
 */
class DomaineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domaine::class);
    }

    /** @return list<Domaine> */
    public function parNom(): array
    {
        return $this->findBy([], ['nom' => 'ASC']);
    }

    /**
     * Les domaines par nom, chacun avec le nombre de formations qui le
     * portent (archivées comprises : elles empêchent aussi la suppression).
     *
     * @return list<array{domaine: Domaine, formations: int}>
     */
    public function avecNombreDeFormations(): array
    {
        $lignes = $this->createQueryBuilder('d')
            ->select('d AS domaine', 'COUNT(f.id) AS formations')
            ->leftJoin(Formation::class, 'f', 'WITH', 'f.domaine = d')
            ->groupBy('d.id')
            ->orderBy('d.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn(array $ligne) => ['domaine' => $ligne['domaine'], 'formations' => (int)$ligne['formations']],
            $lignes,
        );
    }

    /**
     * Le domaine qui porte ce nom, sans tenir compte de la casse ni des
     * espaces en trop : pour l'API et l'import, qui le donnent en texte.
     */
    public function parNomSouple(string $nom): ?Domaine
    {
        $nom = trim(preg_replace('/\s+/u', ' ', $nom));

        if ($nom === '') {
            return null;
        }

        return $this->createQueryBuilder('d')
            ->where('LOWER(d.nom) = LOWER(:nom)')
            ->setParameter('nom', $nom)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
