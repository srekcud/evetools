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
    public const int MAX_SNAPSHOT_AGE_DAYS = 2;

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
     * @param list<int> $typeIds
     *
     * @return array<int, int> quantity on sale from the most recent snapshot of each type, types without a snapshot of the last MAX_SNAPSHOT_AGE_DAYS days are absent
     */
    public function latestSellVolumes(int $structureId, array $typeIds): array
    {
        if ([] === $typeIds) {
            return [];
        }

        $snapshots = $this->createQueryBuilder('s')
            ->where('s.structureId = :structureId')
            ->andWhere('s.typeId IN (:typeIds)')
            ->andWhere('s.date >= :since')
            ->setParameter('structureId', $structureId)
            ->setParameter('typeIds', $typeIds)
            ->setParameter('since', new \DateTimeImmutable(\sprintf('-%d days', self::MAX_SNAPSHOT_AGE_DAYS)), 'date_immutable')
            ->orderBy('s.date', 'DESC')
            ->getQuery()
            ->getResult();

        $volumes = [];
        foreach ($snapshots as $snapshot) {
            $volumes[$snapshot->getTypeId()] ??= $snapshot->getSellVolume();
        }

        return $volumes;
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
