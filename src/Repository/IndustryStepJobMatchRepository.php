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
     * Find active job matches that should have completed (endDate in the past).
     *
     * @return IndustryStepJobMatch[]
     */
    public function findExpiredActiveJobs(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->andWhere('m.endDate IS NOT NULL')
            ->andWhere('m.endDate <= :now')
            ->setParameter('status', 'active')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
