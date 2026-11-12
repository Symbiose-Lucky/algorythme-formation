<?php

namespace App\Repository;

use App\Entity\Organisme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @extends ServiceEntityRepository<Organisme>
 */
class OrganismeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry         $registry,
        #[Autowire('%app_nom_of%')]
        private readonly string $nomParDefaut,
    )
    {
        parent::__construct($registry, Organisme::class);
    }

    /**
     * L'unique fiche de l'organisme. Créée au premier appel, avec son seul
     * nom : le reste se renseigne dans l'admin (/admin/organisme).
     */
    public function unique(): Organisme
    {
        $organisme = $this->findOneBy([]);

        if ($organisme === null) {
            $organisme = (new Organisme())->setNom($this->nomParDefaut);

            $this->getEntityManager()->persist($organisme);
            $this->getEntityManager()->flush();
        }

        return $organisme;
    }
}
