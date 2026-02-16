<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketPriceHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketPriceHistory>
 */
class MarketPriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketPriceHistory::class);
    }

    /**
     * Get latest date stored for a type/region combo.
     */
    public function getLatestDate(int $typeId, int $regionId): ?\DateTimeInterface
    {
        /** @var string|null $result */
        $result = $this->createQueryBuilder('h')
            ->select('MAX(h.date)')
            ->where('h.typeId = :typeId')
            ->andWhere('h.regionId = :regionId')
            ->setParameter('typeId', $typeId)
            ->setParameter('regionId', $regionId)
            ->getQuery()
            ->getSingleScalarResult();

        if ($result === null) {
            return null;
        }

        return new \DateTime($result);
    }

    /**
     * @return MarketPriceHistory[]
     */
    public function findHistory(int $typeId, int $days = 30, int $regionId = MarketPriceHistory::DEFAULT_REGION_ID): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('h')
            ->where('h.typeId = :typeId')
            ->andWhere('h.regionId = :regionId')
            ->andWhere('h.date >= :since')
            ->setParameter('typeId', $typeId)
            ->setParameter('regionId', $regionId)
            ->setParameter('since', $since)
            ->orderBy('h.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the average price on a specific date (or closest before).
     */
    public function getAveragePriceOnDate(int $typeId, \DateTimeInterface $date, int $regionId = MarketPriceHistory::DEFAULT_REGION_ID): ?float
    {
        /** @var array{average: float}|null $result */
        $result = $this->createQueryBuilder('h')
            ->select('h.average')
            ->where('h.typeId = :typeId')
            ->andWhere('h.regionId = :regionId')
            ->andWhere('h.date <= :date')
            ->setParameter('typeId', $typeId)
            ->setParameter('regionId', $regionId)
            ->setParameter('date', $date)
            ->orderBy('h.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null ? (float) $result['average'] : null;
    }

    /**
     * Delete entries older than a given number of days.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = new \DateTimeImmutable("-{$days} days");

        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->where('h.date < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
