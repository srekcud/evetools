<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfitMatch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfitMatch>
 */
class ProfitMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfitMatch::class);
    }

    /**
     * Delete all matches for a user within a date range.
     */
    public function deleteByUserAndPeriod(User $user, \DateTimeImmutable $from): int
    {
        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->where('m.user = :user')
            ->andWhere('m.matchedAt >= :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute();
    }

    /**
     * Find all matches for a user within a date range.
     *
     * @return ProfitMatch[]
     */
    public function findByUserAndPeriod(User $user, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.matchedAt >= :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->orderBy('m.matchedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find matches for a specific product type.
     *
     * @return ProfitMatch[]
     */
    public function findByUserAndProductType(User $user, int $productTypeId, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.productTypeId = :typeId')
            ->andWhere('m.matchedAt >= :from')
            ->setParameter('user', $user)
            ->setParameter('typeId', $productTypeId)
            ->setParameter('from', $from)
            ->orderBy('m.matchedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get aggregated profit data per product type.
     *
     * @return list<array{productTypeId: int, totalRevenue: float, totalMaterialCost: float, totalJobInstallCost: float, totalTaxAmount: float, totalProfit: float, quantitySold: int, matchCount: int, lastMatchedAt: string}>
     */
    public function getAggregatedByProductType(User $user, \DateTimeImmutable $from): array
    {
        $result = $this->createQueryBuilder('m')
            ->select(
                'm.productTypeId AS productTypeId',
                'SUM(m.revenue) AS totalRevenue',
                'SUM(m.materialCost) AS totalMaterialCost',
                'SUM(m.jobInstallCost) AS totalJobInstallCost',
                'SUM(m.taxAmount) AS totalTaxAmount',
                'SUM(m.profit) AS totalProfit',
                'SUM(m.quantitySold) AS quantitySold',
                'COUNT(m.id) AS matchCount',
                'MAX(m.matchedAt) AS lastMatchedAt'
            )
            ->where('m.user = :user')
            ->andWhere('m.matchedAt >= :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->groupBy('m.productTypeId')
            ->getQuery()
            ->getResult();

        /** @var list<array{productTypeId: int, totalRevenue: float, totalMaterialCost: float, totalJobInstallCost: float, totalTaxAmount: float, totalProfit: float, quantitySold: int, matchCount: int, lastMatchedAt: string}> */
        return $result;
    }

    /**
     * Get total counts for admin stats.
     *
     * @return array{totalMatches: int, totalProfit: float}
     */
    public function getGlobalStats(\DateTimeImmutable $from): array
    {
        $result = $this->createQueryBuilder('m')
            ->select(
                'COUNT(m.id) AS totalMatches',
                'COALESCE(SUM(m.profit), 0) AS totalProfit'
            )
            ->where('m.matchedAt >= :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleResult();

        return [
            'totalMatches' => (int) $result['totalMatches'],
            'totalProfit' => (float) $result['totalProfit'],
        ];
    }

    /**
     * Count distinct product types tracked for a user.
     */
    public function countDistinctProductTypes(User $user, \DateTimeImmutable $from): int
    {
        $result = $this->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.productTypeId)')
            ->where('m.user = :user')
            ->andWhere('m.matchedAt >= :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
