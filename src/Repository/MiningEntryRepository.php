<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MiningEntry>
 */
class MiningEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningEntry::class);
    }

    /**
     * Find an existing entry by unique constraint.
     */
    public function findByUniqueKey(User $user, int $characterId, \DateTimeImmutable $date, int $typeId, int $solarSystemId): ?MiningEntry
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.characterId = :characterId')
            ->andWhere('m.date = :date')
            ->andWhere('m.typeId = :typeId')
            ->andWhere('m.solarSystemId = :solarSystemId')
            ->setParameter('user', $user)
            ->setParameter('characterId', $characterId)
            ->setParameter('date', $date)
            ->setParameter('typeId', $typeId)
            ->setParameter('solarSystemId', $solarSystemId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string>|null $excludeUsages
     * @return MiningEntry[]
     */
    public function findByUserAndDateRange(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null,
        ?int $limit = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.date', 'DESC');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get total value by user in date range.
     * @param list<string>|null $excludeUsages
     */
    public function getTotalValueByUserAndDateRange(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): float {
        $qb = $this->createQueryBuilder('m')
            ->select('SUM(m.totalValue)')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->andWhere('m.totalValue IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Get total quantity by user in date range.
     * @param list<string>|null $excludeUsages
     */
    public function getTotalQuantityByUserAndDateRange(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): int {
        $qb = $this->createQueryBuilder('m')
            ->select('SUM(m.quantity)')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Get daily totals for mining.
     * @param list<string>|null $excludeUsages
     * @return array<string, array{date: string, totalValue: float, totalQuantity: int}>
     */
    public function getDailyTotals(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('m.date as day, SUM(m.totalValue) as totalValue, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.date')
            ->orderBy('m.date', 'ASC');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $results = $qb->getQuery()->getResult();

        $dailyTotals = [];
        foreach ($results as $row) {
            $date = $row['day'] instanceof \DateTimeImmutable ? $row['day']->format('Y-m-d') : $row['day'];
            $dailyTotals[$date] = [
                'date' => $date,
                'totalValue' => (float) ($row['totalValue'] ?? 0),
                'totalQuantity' => (int) ($row['totalQuantity'] ?? 0),
            ];
        }

        return $dailyTotals;
    }

    /**
     * Get totals by ore type.
     * @param list<string>|null $excludeUsages
     * @return array<int, array{typeId: int, typeName: string, totalValue: float, totalQuantity: int}>
     */
    public function getTotalsByType(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('m.typeId, m.typeName, SUM(m.totalValue) as totalValue, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.typeId, m.typeName')
            ->orderBy('totalValue', 'DESC');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $results = $qb->getQuery()->getResult();

        $totals = [];
        foreach ($results as $row) {
            $totals[$row['typeId']] = [
                'typeId' => (int) $row['typeId'],
                'typeName' => $row['typeName'],
                'totalValue' => (float) ($row['totalValue'] ?? 0),
                'totalQuantity' => (int) ($row['totalQuantity'] ?? 0),
            ];
        }

        return $totals;
    }

    /**
     * Get totals by usage type.
     * @return array<string, array{usage: string, totalValue: float, totalQuantity: int}>
     */
    public function getTotalsByUsage(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $results = $this->createQueryBuilder('m')
            ->select('m.usage, SUM(m.totalValue) as totalValue, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.usage')
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach ($results as $row) {
            $totals[$row['usage']] = [
                'usage' => $row['usage'],
                'totalValue' => (float) ($row['totalValue'] ?? 0),
                'totalQuantity' => (int) ($row['totalQuantity'] ?? 0),
            ];
        }

        return $totals;
    }

    /**
     * Get all unique type IDs for a user without a price set.
     * @return int[]
     */
    public function getTypeIdsWithoutPrice(User $user): array
    {
        $results = $this->createQueryBuilder('m')
            ->select('DISTINCT m.typeId')
            ->where('m.user = :user')
            ->andWhere('m.unitPrice IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($r) => (int) $r['typeId'], $results);
    }

    /**
     * Update price for all entries of a specific type for a user.
     */
    public function updatePriceByTypeId(User $user, int $typeId, float $unitPrice): int
    {
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.unitPrice', ':unitPrice')
            ->set('m.totalValue', 'm.quantity * :unitPrice')
            ->where('m.user = :user')
            ->andWhere('m.typeId = :typeId')
            ->setParameter('user', $user)
            ->setParameter('typeId', $typeId)
            ->setParameter('unitPrice', $unitPrice)
            ->getQuery()
            ->execute();
    }

    /**
     * Get totals by solar system.
     * @param list<string>|null $excludeUsages
     * @return array<int, array{solarSystemId: int, solarSystemName: string, totalValue: float, totalQuantity: int}>
     */
    public function getTotalsBySolarSystem(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('m.solarSystemId, m.solarSystemName, SUM(m.totalValue) as totalValue, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.solarSystemId, m.solarSystemName')
            ->orderBy('totalValue', 'DESC');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $results = $qb->getQuery()->getResult();

        $totals = [];
        foreach ($results as $row) {
            $totals[$row['solarSystemId']] = [
                'solarSystemId' => (int) $row['solarSystemId'],
                'solarSystemName' => $row['solarSystemName'],
                'totalValue' => (float) ($row['totalValue'] ?? 0),
                'totalQuantity' => (int) ($row['totalQuantity'] ?? 0),
            ];
        }

        return $totals;
    }

    /**
     * Get aggregated quantities by type (no LIMIT).
     * Used for best-price calculation across all entries.
     *
     * @param list<string>|null $excludeUsages
     * @return array<int, array{typeId: int, quantity: int}>
     */
    public function getQuantitiesByType(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('m.typeId, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.typeId');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $results = $qb->getQuery()->getResult();

        $quantities = [];
        foreach ($results as $row) {
            $typeId = (int) $row['typeId'];
            $quantities[$typeId] = [
                'typeId' => $typeId,
                'quantity' => (int) ($row['totalQuantity'] ?? 0),
            ];
        }

        return $quantities;
    }

    /**
     * Get aggregated quantities by type and date (no LIMIT).
     * Used for best-price daily stats calculation.
     *
     * @param list<string>|null $excludeUsages
     * @return array<string, array<int, int>> date => [typeId => quantity]
     */
    public function getQuantitiesByTypeAndDate(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('m.date as day, m.typeId, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.date, m.typeId');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $results = $qb->getQuery()->getResult();

        $byDateAndType = [];
        foreach ($results as $row) {
            $date = $row['day'] instanceof \DateTimeImmutable ? $row['day']->format('Y-m-d') : $row['day'];
            $typeId = (int) $row['typeId'];
            $byDateAndType[$date][$typeId] = (int) ($row['totalQuantity'] ?? 0);
        }

        return $byDateAndType;
    }

    /**
     * Get aggregated quantities by type and usage.
     * Used for best-price calculation per usage breakdown.
     *
     * @return array<string, array<int, int>> usage => [typeId => quantity]
     */
    public function getQuantitiesByTypeAndUsage(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $results = $this->createQueryBuilder('m')
            ->select('m.usage, m.typeId, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.usage, m.typeId')
            ->getQuery()
            ->getResult();

        $byUsageAndType = [];
        foreach ($results as $row) {
            $usage = $row['usage'];
            $typeId = (int) $row['typeId'];
            $byUsageAndType[$usage][$typeId] = (int) ($row['totalQuantity'] ?? 0);
        }

        return $byUsageAndType;
    }

    /**
     * Get totals by character.
     * @param list<string>|null $excludeUsages
     * @return array<int, array{characterId: int, characterName: string, totalValue: float, totalQuantity: int}>
     */
    public function getTotalsByCharacter(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->select('m.characterId, m.characterName, SUM(m.totalValue) as totalValue, SUM(m.quantity) as totalQuantity')
            ->where('m.user = :user')
            ->andWhere('m.date >= :from')
            ->andWhere('m.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('m.characterId, m.characterName')
            ->orderBy('totalValue', 'DESC');

        if ($excludeUsages !== null && !empty($excludeUsages)) {
            $qb->andWhere('m.usage NOT IN (:excludeUsages)')
               ->setParameter('excludeUsages', $excludeUsages);
        }

        $results = $qb->getQuery()->getResult();

        $totals = [];
        foreach ($results as $row) {
            $totals[$row['characterId']] = [
                'characterId' => (int) $row['characterId'],
                'characterName' => $row['characterName'],
                'totalValue' => (float) ($row['totalValue'] ?? 0),
                'totalQuantity' => (int) ($row['totalQuantity'] ?? 0),
            ];
        }

        return $totals;
    }
}
