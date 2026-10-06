<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStepJobMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryStepJobMatch>
 */
class IndustryStepJobMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryStepJobMatch::class);
    }

    /**
     * Find job matches by their ESI job IDs.
     *
     * @param int[] $esiJobIds
     * @return IndustryStepJobMatch[]
     */
    public function findByEsiJobIds(array $esiJobIds): array
    {
        if (empty($esiJobIds)) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.esiJobId IN (:ids)')
            ->setParameter('ids', $esiJobIds)
            ->getQuery()
            ->getResult();
    }
}
