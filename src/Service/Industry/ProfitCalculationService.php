<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\ProfitMatch;
use App\Entity\User;
use App\Repository\ProfitMatchRepository;
use App\Repository\Sde\InvTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProfitCalculationService
{
    public function __construct(
        private readonly ProfitMatchRepository $profitMatchRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Get KPI summary for the user.
     *
     * @return array{totalProfit: float, totalRevenue: float, totalCost: float, avgMargin: float, itemCount: int, bestItem: array{typeName: string, profit: float}|null, worstItem: array{typeName: string, profit: float}|null}
     */
    public function getSummary(User $user, int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");
        $aggregated = $this->profitMatchRepository->getAggregatedByProductType($user, $from);

        $totalProfit = 0.0;
        $totalRevenue = 0.0;
        $totalCost = 0.0;
        $bestItem = null;
        $worstItem = null;
        $bestProfit = PHP_FLOAT_MIN;
        $worstProfit = PHP_FLOAT_MAX;

        $typeIds = array_map(fn(array $row) => (int) $row['productTypeId'], $aggregated);
        $typeNames = $this->resolveTypeNames($typeIds);

        foreach ($aggregated as $row) {
            $typeId = (int) $row['productTypeId'];
            $profit = (float) $row['totalProfit'];
            $revenue = (float) $row['totalRevenue'];
            $matCost = (float) $row['totalMaterialCost'];
            $installCost = (float) $row['totalJobInstallCost'];
            $tax = (float) $row['totalTaxAmount'];

            $totalProfit += $profit;
            $totalRevenue += $revenue;
            $totalCost += $matCost + $installCost + $tax;

            $typeName = $typeNames[$typeId] ?? "Type #{$typeId}";

            if ($profit > $bestProfit) {
                $bestProfit = $profit;
                $bestItem = ['typeName' => $typeName, 'profit' => round($profit, 2)];
            }

            if ($profit < $worstProfit) {
                $worstProfit = $profit;
                $worstItem = ['typeName' => $typeName, 'profit' => round($profit, 2)];
            }
        }

        $avgMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0.0;

        return [
            'totalProfit' => round($totalProfit, 2),
            'totalRevenue' => round($totalRevenue, 2),
            'totalCost' => round($totalCost, 2),
            'avgMargin' => round($avgMargin, 2),
            'itemCount' => count($aggregated),
            'bestItem' => $bestItem,
            'worstItem' => $worstItem,
        ];
    }

    /**
     * Get per-item profit aggregations.
     *
     * @return list<array{productTypeId: int, typeName: string, quantitySold: int, materialCost: float, jobInstallCost: float, taxAmount: float, totalCost: float, revenue: float, profit: float, marginPercent: float, lastSaleDate: string|null}>
     */
    public function getItemProfits(User $user, int $days = 30, string $sort = 'profit', string $order = 'desc', string $filter = 'all'): array
    {
        $from = new \DateTimeImmutable("-{$days} days");
        $aggregated = $this->profitMatchRepository->getAggregatedByProductType($user, $from);

        $typeIds = array_map(fn(array $row) => (int) $row['productTypeId'], $aggregated);
        $typeNames = $this->resolveTypeNames($typeIds);

        $items = [];
        foreach ($aggregated as $row) {
            $typeId = (int) $row['productTypeId'];
            $revenue = (float) $row['totalRevenue'];
            $matCost = (float) $row['totalMaterialCost'];
            $installCost = (float) $row['totalJobInstallCost'];
            $tax = (float) $row['totalTaxAmount'];
            $profit = (float) $row['totalProfit'];
            $totalCost = $matCost + $installCost + $tax;
            $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0.0;

            // Apply filter
            if ($filter === 'profit' && $margin <= 0) {
                continue;
            }
            if ($filter === 'loss' && $margin > 0) {
                continue;
            }

            $items[] = [
                'productTypeId' => $typeId,
                'typeName' => $typeNames[$typeId] ?? "Type #{$typeId}",
                'quantitySold' => (int) $row['quantitySold'],
                'materialCost' => round($matCost, 2),
                'jobInstallCost' => round($installCost, 2),
                'taxAmount' => round($tax, 2),
                'totalCost' => round($totalCost, 2),
                'revenue' => round($revenue, 2),
                'profit' => round($profit, 2),
                'marginPercent' => round($margin, 2),
                'lastSaleDate' => (string) $row['lastMatchedAt'],
            ];
        }

        // Sort
        $sortKey = match ($sort) {
            'revenue' => 'revenue',
            'margin' => 'marginPercent',
            'quantity' => 'quantitySold',
            'name' => 'typeName',
            default => 'profit',
        };

        usort($items, function (array $a, array $b) use ($sortKey, $order): int {
            if ($sortKey === 'typeName') {
                $cmp = strcasecmp((string) $a[$sortKey], (string) $b[$sortKey]);
            } else {
                $cmp = $a[$sortKey] <=> $b[$sortKey];
            }
            return $order === 'asc' ? $cmp : -$cmp;
        });

        return $items;
    }

    /**
     * Get detailed breakdown for a specific item.
     *
     * @return array{costBreakdown: array{materialCost: float, jobInstallCost: float, taxAmount: float, totalCost: float}, matches: list<array{jobId: int|null, transactionId: int|null, quantitySold: int, revenue: float, materialCost: float, jobInstallCost: float, taxAmount: float, profit: float, matchedAt: string}>, marginTrend: list<array{date: string, profit: float, revenue: float, margin: float}>}
     */
    public function getItemDetail(User $user, int $typeId, int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");
        $matches = $this->profitMatchRepository->findByUserAndProductType($user, $typeId, $from);

        $totalMatCost = 0.0;
        $totalInstallCost = 0.0;
        $totalTax = 0.0;
        $matchList = [];
        /** @var array<string, array{profit: float, revenue: float}> $dailyData */
        $dailyData = [];

        foreach ($matches as $match) {
            $totalMatCost += $match->getMaterialCost();
            $totalInstallCost += $match->getJobInstallCost();
            $totalTax += $match->getTaxAmount();

            $job = $match->getJob();
            $tx = $match->getTransaction();

            $matchList[] = [
                'jobId' => $job?->getJobId(),
                'transactionId' => $tx !== null ? (int) $tx->getTransactionId() : null,
                'quantitySold' => $match->getQuantitySold(),
                'revenue' => $match->getRevenue(),
                'materialCost' => $match->getMaterialCost(),
                'jobInstallCost' => $match->getJobInstallCost(),
                'taxAmount' => $match->getTaxAmount(),
                'profit' => $match->getProfit(),
                'matchedAt' => $match->getMatchedAt()->format('c'),
            ];

            // Aggregate for margin trend
            $dateKey = $match->getMatchedAt()->format('Y-m-d');
            if (!isset($dailyData[$dateKey])) {
                $dailyData[$dateKey] = ['profit' => 0.0, 'revenue' => 0.0];
            }
            $dailyData[$dateKey]['profit'] += $match->getProfit();
            $dailyData[$dateKey]['revenue'] += $match->getRevenue();
        }

        $totalCost = $totalMatCost + $totalInstallCost + $totalTax;

        // Build margin trend
        ksort($dailyData);
        $marginTrend = [];
        foreach ($dailyData as $date => $data) {
            $margin = $data['revenue'] > 0 ? ($data['profit'] / $data['revenue']) * 100 : 0.0;
            $marginTrend[] = [
                'date' => $date,
                'profit' => round($data['profit'], 2),
                'revenue' => round($data['revenue'], 2),
                'margin' => round($margin, 2),
            ];
        }

        return [
            'costBreakdown' => [
                'materialCost' => round($totalMatCost, 2),
                'jobInstallCost' => round($totalInstallCost, 2),
                'taxAmount' => round($totalTax, 2),
                'totalCost' => round($totalCost, 2),
            ],
            'matches' => $matchList,
            'marginTrend' => $marginTrend,
        ];
    }

    /**
     * Get unmatched jobs (produced but not sold) and sales (sold without matching job).
     *
     * @return array{unmatchedJobs: list<array{jobId: int, productTypeId: int, typeName: string, runs: int, completedDate: string|null}>, unmatchedSales: list<array{transactionId: int, typeId: int, typeName: string, quantity: int, unitPrice: float, date: string}>}
     */
    public function getUnmatched(User $user, int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");
        $matches = $this->profitMatchRepository->findByUserAndPeriod($user, $from);

        // Collect matched job IDs and transaction IDs
        $matchedJobIds = [];
        $matchedTxIds = [];
        foreach ($matches as $match) {
            $job = $match->getJob();
            if ($job !== null) {
                $matchedJobIds[$job->getJobId()] = true;
            }
            $tx = $match->getTransaction();
            if ($tx !== null) {
                $matchedTxIds[$tx->getTransactionId()] = true;
            }
        }

        // Get all delivered manufacturing jobs
        $characterIds = [];
        foreach ($user->getCharacters() as $character) {
            $id = $character->getId();
            if ($id !== null) {
                $characterIds[] = $id;
            }
        }

        $unmatchedJobs = [];
        $unmatchedSales = [];

        if (!empty($characterIds)) {
            // Unmatched jobs
            $qb = $this->entityManager->createQueryBuilder();
            /** @var CachedIndustryJob[] $allJobs */
            $allJobs = $qb->select('j')
                ->from(CachedIndustryJob::class, 'j')
                ->where('j.character IN (:chars)')
                ->andWhere('j.status = :status')
                ->andWhere('j.activityId = :activity')
                ->andWhere('j.completedDate >= :from OR j.endDate >= :from')
                ->setParameter('chars', $characterIds)
                ->setParameter('status', 'delivered')
                ->setParameter('activity', 1)
                ->setParameter('from', $from)
                ->orderBy('j.endDate', 'DESC')
                ->getQuery()
                ->getResult();

            $jobTypeIds = [];
            foreach ($allJobs as $job) {
                if (!isset($matchedJobIds[$job->getJobId()])) {
                    $jobTypeIds[] = $job->getProductTypeId();
                }
            }

            $jobTypeNames = $this->resolveTypeNames(array_unique($jobTypeIds));

            foreach ($allJobs as $job) {
                if (!isset($matchedJobIds[$job->getJobId()])) {
                    $unmatchedJobs[] = [
                        'jobId' => $job->getJobId(),
                        'productTypeId' => $job->getProductTypeId(),
                        'typeName' => $jobTypeNames[$job->getProductTypeId()] ?? "Type #{$job->getProductTypeId()}",
                        'runs' => $job->getRuns(),
                        'completedDate' => $job->getCompletedDate()?->format('c'),
                    ];
                }
            }

            // Unmatched sell transactions
            $qb2 = $this->entityManager->createQueryBuilder();
            /** @var CachedWalletTransaction[] $allTxs */
            $allTxs = $qb2->select('t')
                ->from(CachedWalletTransaction::class, 't')
                ->where('t.character IN (:chars)')
                ->andWhere('t.isBuy = :isBuy')
                ->andWhere('t.date >= :from')
                ->setParameter('chars', $characterIds)
                ->setParameter('isBuy', false)
                ->setParameter('from', $from)
                ->orderBy('t.date', 'DESC')
                ->getQuery()
                ->getResult();

            $txTypeIds = [];
            foreach ($allTxs as $tx) {
                if (!isset($matchedTxIds[$tx->getTransactionId()])) {
                    $txTypeIds[] = $tx->getTypeId();
                }
            }

            $txTypeNames = $this->resolveTypeNames(array_unique($txTypeIds));

            foreach ($allTxs as $tx) {
                if (!isset($matchedTxIds[$tx->getTransactionId()])) {
                    $unmatchedSales[] = [
                        'transactionId' => (int) $tx->getTransactionId(),
                        'typeId' => $tx->getTypeId(),
                        'typeName' => $txTypeNames[$tx->getTypeId()] ?? "Type #{$tx->getTypeId()}",
                        'quantity' => $tx->getQuantity(),
                        'unitPrice' => $tx->getUnitPrice(),
                        'date' => $tx->getDate()->format('c'),
                    ];
                }
            }
        }

        return [
            'unmatchedJobs' => $unmatchedJobs,
            'unmatchedSales' => $unmatchedSales,
        ];
    }

    /**
     * Resolve type IDs to names from SDE.
     *
     * @param int[] $typeIds
     * @return array<int, string>
     */
    private function resolveTypeNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $types = $this->invTypeRepository->findByTypeIds($typeIds);
        $names = [];

        foreach ($types as $typeId => $type) {
            $names[$typeId] = $type->getTypeName();
        }

        return $names;
    }
}
