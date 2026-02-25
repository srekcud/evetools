<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PveIncome>
 */
class PveIncomeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PveIncome::class);
    }

    /**
     * @return PveIncome[]
     */
    public function findByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.amount)')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * @return int[]
     */
    public function getImportedTransactionIds(User $user): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.transactionId')
            ->where('i.user = :user')
            ->andWhere('i.transactionId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($r) => (int) $r['transactionId'], $results);
    }

    /**
     * @return int[]
     */
    public function getImportedJournalEntryIds(User $user): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.journalEntryId')
            ->where('i.user = :user')
            ->andWhere('i.journalEntryId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($r) => (int) $r['journalEntryId'], $results);
    }

    /**
     * @return int[]
     */
    public function getImportedContractIds(User $user): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.contractId')
            ->where('i.user = :user')
            ->andWhere('i.contractId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($r) => (int) $r['contractId'], $results);
    }

    /**
     * Get daily totals for income
     * @return array<string, array{date: string, total: float}>
     */
    public function getDailyTotals(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.date as day, SUM(i.amount) as total')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('i.date')
            ->orderBy('i.date', 'ASC')
            ->getQuery()
            ->getResult();

        $dailyTotals = [];
        foreach ($results as $row) {
            $date = $row['day'] instanceof \DateTimeImmutable ? $row['day']->format('Y-m-d') : $row['day'];
            $dailyTotals[$date] = [
                'date' => $date,
                'total' => (float) $row['total'],
            ];
        }

        return $dailyTotals;
    }

    /**
     * Get totals by income type
     * @return array<string, float>
     */
    public function getTotalsByType(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.type, SUM(i.amount) as total')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('i.type')
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach ($results as $row) {
            $key = $row['type'] instanceof PveIncomeType ? $row['type']->value : (string) $row['type'];
            $totals[$key] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * Find bounties (bounty, ess, mission) for a user in date range
     * @return PveIncome[]
     */
    public function findBountiesByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 100): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->andWhere('i.type IN (:types)')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('types', [PveIncomeType::Bounty->value, PveIncomeType::Ess->value, PveIncomeType::Mission->value])
            ->orderBy('i.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total bounties (bounty, ess, mission) for a user in date range
     */
    public function getTotalBountiesByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.amount)')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->andWhere('i.type IN (:types)')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('types', [PveIncomeType::Bounty->value, PveIncomeType::Ess->value, PveIncomeType::Mission->value])
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Find loot sales (including loot contracts) for a user in date range
     * @return PveIncome[]
     */
    public function findLootSalesByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 100): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->andWhere('i.type IN (:types)')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('types', [PveIncomeType::LootSale->value, PveIncomeType::LootContract->value, PveIncomeType::CorpProject->value])
            ->orderBy('i.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total loot sales (including loot contracts and corp projects) for a user in date range
     */
    public function getTotalLootSalesByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.amount)')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->andWhere('i.type IN (:types)')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('types', [PveIncomeType::LootSale->value, PveIncomeType::LootContract->value, PveIncomeType::CorpProject->value])
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Get daily totals grouped by type
     * @param bool $excludeCorpProject If true, corp_project income is excluded from lootSales
     * @return array<string, array{bounties: float, lootSales: float, corpProject: float}>
     */
    public function getDailyTotalsByType(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $excludeCorpProject = false): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.date as day, i.type, SUM(i.amount) as total')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('i.date, i.type')
            ->orderBy('i.date', 'ASC')
            ->getQuery()
            ->getResult();

        $dailyTotals = [];
        foreach ($results as $row) {
            $date = $row['day'] instanceof \DateTimeImmutable ? $row['day']->format('Y-m-d') : $row['day'];
            if (!isset($dailyTotals[$date])) {
                $dailyTotals[$date] = ['bounties' => 0.0, 'lootSales' => 0.0, 'corpProject' => 0.0];
            }

            $type = $row['type'] instanceof PveIncomeType ? $row['type'] : PveIncomeType::tryFrom((string) $row['type']);
            $amount = (float) $row['total'];

            if (in_array($type, [PveIncomeType::Bounty, PveIncomeType::Ess, PveIncomeType::Mission], true)) {
                $dailyTotals[$date]['bounties'] += $amount;
            } elseif ($type === PveIncomeType::CorpProject) {
                if (!$excludeCorpProject) {
                    $dailyTotals[$date]['lootSales'] += $amount;
                }
                $dailyTotals[$date]['corpProject'] += $amount;
            } elseif (in_array($type, [PveIncomeType::LootSale, PveIncomeType::LootContract], true)) {
                $dailyTotals[$date]['lootSales'] += $amount;
            }
        }

        return $dailyTotals;
    }

    /**
     * Get total loot contracts for a user in date range
     */
    public function getTotalLootContractsByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.amount)')
            ->where('i.user = :user')
            ->andWhere('i.date >= :from')
            ->andWhere('i.date <= :to')
            ->andWhere('i.type = :type')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('type', PveIncomeType::LootContract->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }
}
