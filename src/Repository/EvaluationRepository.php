<?php

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\SessionFormation;
use App\Enum\TypeEvaluation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluation>
 */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    /**
     * Ce qui a été rempli au sujet des participants d'une session, rangé
     * par inscription puis par type : [idInscription]['chaud'] => Evaluation.
     *
     * @return array<int, array<string, Evaluation>>
     */
    public function deLaSession(SessionFormation $session): array
    {
        $tableau = [];

        foreach ($this->findBy(['session' => $session]) as $evaluation) {
            if ($evaluation->getInscription() !== null) {
                $tableau[$evaluation->getInscription()->getId()][$evaluation->getType()->value] = $evaluation;
            }
        }

        return $tableau;
    }

    /**
     * Les avis des clients et les bilans des formateurs d'une session :
     * ['client'][idClient] => Evaluation, ['formateur'][idFormateur] => Evaluation.
     *
     * @return array{client: array<int, Evaluation>, formateur: array<int, Evaluation>}
     */
    public function tiersDeLaSession(SessionFormation $session): array
    {
        $tableau = ['client' => [], 'formateur' => []];

        foreach ($this->findBy(['session' => $session, 'inscription' => null]) as $evaluation) {
            if ($evaluation->getType() === TypeEvaluation::Client && $evaluation->getClient() !== null) {
                $tableau['client'][$evaluation->getClient()->getId()] = $evaluation;
            } elseif ($evaluation->getType() === TypeEvaluation::Formateur && $evaluation->getFormateur() !== null) {
                $tableau['formateur'][$evaluation->getFormateur()->getId()] = $evaluation;
            }
        }

        return $tableau;
    }
}
