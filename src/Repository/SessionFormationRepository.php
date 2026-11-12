<?php

namespace App\Repository;

use App\Entity\Formation;
use App\Entity\SessionFormation;
use App\Enum\StatutSession;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionFormation>
 */
class SessionFormationRepository extends ServiceEntityRepository
{
    public const string A_VENIR = 'a-venir';
    public const string PASSEES = 'passees';
    public const string ANNULEES = 'annulees';

    public const array PERIODES = [self::A_VENIR, self::PASSEES, self::ANNULEES];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionFormation::class);
    }

    /**
     * Les sessions d'une période :
     * - à venir : non annulées, pas encore finies ou sans date (les
     *   prochaines d'abord, celles sans date en tête) ;
     * - passées : non annulées, finies (les plus récentes d'abord) ;
     * - annulées.
     *
     * @return list<SessionFormation>
     */
    public function rechercher(string $periode, ?Formation $formation = null): array
    {
        return $this->requete($periode, $formation)
            ->addSelect('f')
            ->orderBy('s.dateDebut', $periode === self::A_VENIR ? 'ASC' : 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int> Le nombre de sessions par période.
     */
    public function compterParPeriode(?Formation $formation = null): array
    {
        $nombres = [];

        foreach (self::PERIODES as $periode) {
            $nombres[$periode] = (int)$this->requete($periode, $formation)
                ->select('COUNT(s.id)')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $nombres;
    }

    /**
     * Toutes les sessions d'une formation, les plus récentes d'abord.
     *
     * @return list<SessionFormation>
     */
    public function deLaFormation(Formation $formation): array
    {
        return $this->findBy(['formation' => $formation], ['dateDebut' => 'DESC', 'id' => 'DESC']);
    }

    private function requete(string $periode, ?Formation $formation): QueryBuilder
    {
        $requete = $this->createQueryBuilder('s')->join('s.formation', 'f');

        if ($formation !== null) {
            $requete->andWhere('s.formation = :formation')->setParameter('formation', $formation);
        }

        if ($periode === self::ANNULEES) {
            return $requete->andWhere('s.statut = :annulee')->setParameter('annulee', StatutSession::Annulee);
        }

        // Le jour courant à Paris : le serveur, lui, est en UTC.
        $aujourdhui = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));

        return $requete
            ->andWhere('s.statut != :annulee')
            ->setParameter('annulee', StatutSession::Annulee)
            ->andWhere($periode === self::PASSEES
                ? 's.dateFin < :aujourdhui'
                : 's.dateFin IS NULL OR s.dateFin >= :aujourdhui')
            ->setParameter('aujourdhui', $aujourdhui->format('Y-m-d'));
    }
}
