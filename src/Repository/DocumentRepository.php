<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Les documents rattachés à un objet : les actifs d'abord, du plus
     * récent au plus ancien, puis les archivés.
     *
     * @return list<Document>
     */
    public function de(string $type, int $id): array
    {
        return $this->findBy(['objetType' => $type, 'objetId' => $id], ['archive' => 'ASC', 'id' => 'DESC']);
    }
}
