<?php

namespace App\Repository;

use App\Entity\Emargement;
use App\Entity\SessionFormation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Emargement>
 */
class EmargementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emargement::class);
    }

    /**
     * Tous les émargements d'une session, rangés par séance puis par
     * personne : [idSéance]['p12' | 'f3'] => Emargement. La clé d'une
     * personne est « p » + l'identifiant de son inscription, ou « f » +
     * celui du formateur.
     *
     * @return array<int, array<string, Emargement>>
     */
    public function deLaSession(SessionFormation $session): array
    {
        $emargements = $this->createQueryBuilder('e')
            ->join('e.seance', 's')
            ->where('s.session = :session')
            ->setParameter('session', $session)
            ->getQuery()
            ->getResult();

        $tableau = [];

        foreach ($emargements as $emargement) {
            $cle = $emargement->getInscription() !== null
                ? 'p' . $emargement->getInscription()->getId()
                : 'f' . $emargement->getFormateur()->getId();

            $tableau[$emargement->getSeance()->getId()][$cle] = $emargement;
        }

        return $tableau;
    }
}
