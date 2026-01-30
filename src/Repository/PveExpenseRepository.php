<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PveExpense;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PveExpense>
 */
class PveExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PveExpense::class);
    }

    /**
     * @return PveExpense[]
     */
    public function findByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.date >= :from')
            ->andWhere('e.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalByUserAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.amount)')
            ->where('e.user = :user')
            ->andWhere('e.date >= :from')
            ->andWhere('e.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * @return array<string, float>
     */
    public function getTotalsByTypeAndDateRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('e.type, SUM(e.amount) as total')
            ->where('e.user = :user')
            ->andWhere('e.date >= :from')
            ->andWhere('e.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('e.type')
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach ($results as $row) {
            $totals[$row['type']] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * @return int[]
     */
    public function getImportedContractIds(User $user): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('e.contractId')
            ->where('e.user = :user')
            ->andWhere('e.contractId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($r) => (int) $r['contractId'], $results);
    }

    /**
     * @return int[]
     */
    public function getImportedTransactionIds(User $user): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('e.transactionId')
            ->where('e.user = :user')
            ->andWhere('e.transactionId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn($r) => (int) $r['transactionId'], $results);
    }

    /**
     * Get daily totals for expenses
     * @return array<string, array{date: string, total: float}>
     */
    public function getDailyTotals(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('e.date as day, SUM(e.amount) as total')
            ->where('e.user = :user')
            ->andWhere('e.date >= :from')
            ->andWhere('e.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('e.date')
            ->orderBy('e.date', 'ASC')
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
}
