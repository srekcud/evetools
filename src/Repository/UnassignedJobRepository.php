<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UnassignedJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnassignedJob>
 */
class UnassignedJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnassignedJob::class);
    }

    /**
     * @param list<int> $esiJobIds
     *
     * @return array<int, UnassignedJob>
     */
    public function findByEsiJobIds(array $esiJobIds): array
    {
        if ($esiJobIds === []) {
            return [];
        }

        $byEsiJobId = [];
        foreach ($this->createQueryBuilder('u')
            ->join('u.job', 'j')
            ->where('j.jobId IN (:ids)')
            ->setParameter('ids', $esiJobIds)
            ->getQuery()
            ->getResult() as $unassignedJob) {
            $byEsiJobId[$unassignedJob->getJob()->getJobId()] = $unassignedJob;
        }

        return $byEsiJobId;
    }
}
