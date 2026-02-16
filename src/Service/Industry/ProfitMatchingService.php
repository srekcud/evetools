<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\CachedWalletTransaction;
use App\Entity\ProfitMatch;
use App\Entity\User;
use App\Repository\ProfitMatchRepository;
use App\Repository\ProfitSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ProfitMatchingService
{
    private const ACTIVITY_MANUFACTURING = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfitMatchRepository $profitMatchRepository,
        private readonly ProfitSettingsRepository $profitSettingsRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityMaterialRepository $activityMaterialRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute FIFO profit matches between delivered jobs and sell transactions.
     *
     * @return int Number of matches created
     */
    public function computeMatches(User $user, int $days = 30): int
    {
        // Cap at 365 days to prevent excessive queries
        $days = min(max($days, 1), 365);
        $from = new \DateTimeImmutable("-{$days} days");
        $settings = $this->profitSettingsRepository->getOrCreate($user);

        // Delete old matches for idempotency
        $this->profitMatchRepository->deleteByUserAndPeriod($user, $from);

        // Collect delivered manufacturing jobs for all user's characters
        $jobs = $this->getDeliveredJobs($user, $from);
        // Collect sell transactions
        $sellTransactions = $this->getSellTransactions($user, $from);

        if (empty($jobs) || empty($sellTransactions)) {
            $this->logger->info('No jobs or transactions to match', [
                'userId' => $user->getId()?->toRfc4122(),
                'jobs' => count($jobs),
                'transactions' => count($sellTransactions),
            ]);
            return 0;
        }

        // Group jobs by product type ID
        $jobsByType = $this->groupJobsByProductType($jobs);
        // Group transactions by type ID
        $txByType = $this->groupTransactionsByType($sellTransactions);

        // Pre-fetch output quantities per blueprint
        $outputQuantities = $this->preloadOutputQuantities(array_keys($jobsByType));

        $matchCount = 0;
        $batchSize = 50;

        foreach ($jobsByType as $typeId => $typeJobs) {
            if (!isset($txByType[$typeId])) {
                continue;
            }

            $typeTxs = $txByType[$typeId];
            $txIndex = 0;
            /** @var array<int, int> $txAllocated Track allocated quantity per transaction index */
            $txAllocated = [];

            foreach ($typeJobs as $job) {
                $outputPerRun = $outputQuantities[$job->getBlueprintTypeId()] ?? 1;
                $totalUnits = $job->getRuns() * $outputPerRun;
                $remainingUnits = $totalUnits;

                // Estimate material cost per unit for this job
                $materialCostPerUnit = $this->estimateMaterialCostPerUnit(
                    $job->getBlueprintTypeId(),
                    $outputPerRun,
                );

                while ($remainingUnits > 0 && $txIndex < count($typeTxs)) {
                    $tx = $typeTxs[$txIndex];
                    $alreadyAllocated = $txAllocated[$txIndex] ?? 0;
                    $txAvailable = $tx->getQuantity() - $alreadyAllocated;

                    if ($txAvailable <= 0) {
                        $txIndex++;
                        continue;
                    }

                    $allocate = min($remainingUnits, $txAvailable);
                    $revenue = $tx->getUnitPrice() * $allocate;
                    $matCost = $materialCostPerUnit * $allocate;
                    $jobInstall = $totalUnits > 0
                        ? $job->getCost() * ($allocate / $totalUnits)
                        : 0.0;
                    $tax = $revenue * $settings->getSalesTaxRate();
                    $profit = $revenue - $matCost - $jobInstall - $tax;

                    // Determine how many "runs" this allocation represents
                    $allocatedRuns = $outputPerRun > 0
                        ? (int) ceil($allocate / $outputPerRun)
                        : $allocate;

                    $match = new ProfitMatch();
                    $match->setUser($user);
                    $match->setProductTypeId($typeId);
                    $match->setJob($job);
                    $match->setTransaction($tx);
                    $match->setJobRuns($allocatedRuns);
                    $match->setQuantitySold($allocate);
                    $match->setMaterialCost(round($matCost, 2));
                    $match->setJobInstallCost(round($jobInstall, 2));
                    $match->setTaxAmount(round($tax, 2));
                    $match->setRevenue(round($revenue, 2));
                    $match->setProfit(round($profit, 2));
                    $match->setCostSource(ProfitMatch::COST_SOURCE_MARKET);
                    $match->setStatus(
                        $allocate < $remainingUnits && $txAvailable <= $allocate
                            ? ProfitMatch::STATUS_PARTIAL
                            : ProfitMatch::STATUS_MATCHED
                    );
                    $match->setMatchedAt($tx->getDate());

                    $this->entityManager->persist($match);
                    $matchCount++;

                    $remainingUnits -= $allocate;
                    $txAllocated[$txIndex] = $alreadyAllocated + $allocate;

                    if ($txAllocated[$txIndex] >= $tx->getQuantity()) {
                        $txIndex++;
                    }

                    // Flush in batches to avoid memory issues
                    if ($matchCount % $batchSize === 0) {
                        $this->entityManager->flush();
                    }
                }
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Profit matching completed', [
            'userId' => $user->getId()?->toRfc4122(),
            'matchCount' => $matchCount,
            'days' => $days,
        ]);

        return $matchCount;
    }

    /**
     * Get character IDs for a user.
     *
     * @return list<\Symfony\Component\Uid\Uuid>
     */
    private function getUserCharacterIds(User $user): array
    {
        $characterIds = [];
        foreach ($user->getCharacters() as $character) {
            $id = $character->getId();
            if ($id !== null) {
                $characterIds[] = $id;
            }
        }
        return $characterIds;
    }

    /**
     * Get delivered manufacturing jobs for all user's characters.
     *
     * @return CachedIndustryJob[]
     */
    private function getDeliveredJobs(User $user, \DateTimeImmutable $from): array
    {
        $characterIds = $this->getUserCharacterIds($user);

        if (empty($characterIds)) {
            return [];
        }

        /** @var CachedIndustryJob[] $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('j')
            ->from(CachedIndustryJob::class, 'j')
            ->where('j.character IN (:chars)')
            ->andWhere('j.status = :status')
            ->andWhere('j.activityId = :activity')
            ->andWhere('j.completedDate >= :from OR j.endDate >= :from')
            ->setParameter('chars', $characterIds)
            ->setParameter('status', 'delivered')
            ->setParameter('activity', self::ACTIVITY_MANUFACTURING)
            ->setParameter('from', $from)
            ->orderBy('j.completedDate', 'ASC')
            ->addOrderBy('j.endDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get sell transactions for all user's characters.
     *
     * @return CachedWalletTransaction[]
     */
    private function getSellTransactions(User $user, \DateTimeImmutable $from): array
    {
        $characterIds = $this->getUserCharacterIds($user);

        if (empty($characterIds)) {
            return [];
        }

        /** @var CachedWalletTransaction[] $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(CachedWalletTransaction::class, 't')
            ->where('t.character IN (:chars)')
            ->andWhere('t.isBuy = :isBuy')
            ->andWhere('t.date >= :from')
            ->setParameter('chars', $characterIds)
            ->setParameter('isBuy', false)
            ->setParameter('from', $from)
            ->orderBy('t.date', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Group jobs by product type ID, sorted by completion date.
     *
     * @param CachedIndustryJob[] $jobs
     * @return array<int, list<CachedIndustryJob>>
     */
    private function groupJobsByProductType(array $jobs): array
    {
        $grouped = [];
        foreach ($jobs as $job) {
            $grouped[$job->getProductTypeId()][] = $job;
        }
        return $grouped;
    }

    /**
     * Group sell transactions by type ID, sorted by date.
     *
     * @param CachedWalletTransaction[] $transactions
     * @return array<int, list<CachedWalletTransaction>>
     */
    private function groupTransactionsByType(array $transactions): array
    {
        $grouped = [];
        foreach ($transactions as $tx) {
            $grouped[$tx->getTypeId()][] = $tx;
        }
        return $grouped;
    }

    /**
     * Preload output quantities per run from SDE for all product types.
     *
     * @param int[] $productTypeIds
     * @return array<int, int> blueprintTypeId => outputPerRun
     */
    private function preloadOutputQuantities(array $productTypeIds): array
    {
        $result = [];

        foreach ($productTypeIds as $productTypeId) {
            $product = $this->activityProductRepository->findBlueprintForProduct(
                $productTypeId,
                self::ACTIVITY_MANUFACTURING
            );

            if ($product !== null) {
                $result[$product->getTypeId()] = $product->getQuantity();
            }
        }

        return $result;
    }

    /**
     * Estimate material cost per unit using Jita buy prices for the blueprint's materials.
     */
    private function estimateMaterialCostPerUnit(int $blueprintTypeId, int $outputPerRun): float
    {
        $materials = $this->activityMaterialRepository->findByBlueprintAndActivity(
            $blueprintTypeId,
            self::ACTIVITY_MANUFACTURING
        );

        if (empty($materials)) {
            // Fallback: use 80% of Jita sell price for the product
            return $this->getFallbackCost($blueprintTypeId, $outputPerRun);
        }

        $costPerRun = 0.0;
        $materialTypeIds = array_map(
            fn($m) => $m->getMaterialTypeId(),
            $materials
        );

        $prices = $this->jitaMarketService->getBuyPrices($materialTypeIds);

        foreach ($materials as $material) {
            $price = $prices[$material->getMaterialTypeId()] ?? null;

            if ($price === null) {
                // Try sell price as fallback
                $price = $this->jitaMarketService->getPrice($material->getMaterialTypeId());
            }

            if ($price !== null) {
                $costPerRun += $price * $material->getQuantity();
            }
        }

        if ($outputPerRun <= 0) {
            return $costPerRun;
        }

        return $costPerRun / $outputPerRun;
    }

    /**
     * Fallback cost estimation when no SDE material data is available.
     */
    private function getFallbackCost(int $blueprintTypeId, int $outputPerRun): float
    {
        // Try to find which product this blueprint produces
        $products = $this->activityProductRepository->findBy([
            'typeId' => $blueprintTypeId,
            'activityId' => self::ACTIVITY_MANUFACTURING,
        ]);

        if (empty($products)) {
            return 0.0;
        }

        $productTypeId = $products[0]->getProductTypeId();
        $sellPrice = $this->jitaMarketService->getPrice($productTypeId);

        if ($sellPrice === null) {
            return 0.0;
        }

        // Use 80% of sell price as estimated material cost
        return $sellPrice * 0.8;
    }
}
