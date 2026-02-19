<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StructureMarketSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StructureMarketSnapshot>
 */
class StructureMarketSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StructureMarketSnapshot::class);
    }

    /**
     * @return StructureMarketSnapshot[]
     */
    public function findHistory(int $structureId, int $typeId, int $days): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('s')
            ->where('s.structureId = :sid')
            ->andWhere('s.typeId = :tid')
            ->andWhere('s.date >= :since')
            ->setParameter('sid', $structureId)
            ->setParameter('tid', $typeId)
            ->setParameter('since', $since)
            ->orderBy('s.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete snapshots older than a given number of days.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = new \DateTimeImmutable("-{$days} days");

        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.date < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
