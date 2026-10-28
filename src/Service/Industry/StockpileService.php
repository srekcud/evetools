<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\IndustryStockpileTarget;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Exception\TypeMissingFromSdeException;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\Job;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class StockpileService
{
    use PlansStockpileImports;

    private const int FULL_PERMILLE = 1000;
    private const float PERMILLE_PER_PERCENT = 10.0;

    private const array EMPTY_STAGES = [
        'raw_material' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
        'intermediate' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
        'component' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
        'final_product' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
    ];

    public function __construct(
        private readonly IndustryStockpileTargetRepository $targetRepository,
        private readonly CachedAssetRepository $assetRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly ProductionPlanning $productionPlanning,
        private readonly UserProductionStructures $userStructures,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryStockpileImportRepository $importRepository,
        private readonly StockpileStartingStockReducer $startingStockReducer,
    ) {
    }

    /**
     * Preview the import of a blueprint into stockpile targets.
     *
     * @return array{stages: array<string, list<array{typeId: int, typeName: string, quantity: int, unitPrice: float|null}>>, totalItems: int, estimatedCost: float|null, missingPriceTypeIds: list<int>}
     */
    public function previewImport(User $user, int $typeId, int $runs, int $me): array
    {
        $stockpileTargets = $this->stockpileTargetsOf($user, $typeId, $runs, $me);

        $allTypeIds = array_keys($stockpileTargets);
        $prices = $this->jitaMarketService->getPricesWithFallback($allTypeIds);

        $stages = [
            'raw_material' => [],
            'intermediate' => [],
            'component' => [],
            'final_product' => [],
        ];

        $totalItems = 0;
        $estimatedCost = 0.0;

        foreach ($stockpileTargets as $matTypeId => $item) {
            $unitPrice = $prices[$matTypeId] ?? null;
            $entry = [
                'typeId' => $matTypeId,
                'typeName' => $item['typeName'],
                'quantity' => $item['quantity'],
                'unitPrice' => $unitPrice,
            ];
            $stages[$item['stage']][] = $entry;
            $totalItems++;
            if ($unitPrice !== null) {
                $estimatedCost += $item['quantity'] * $unitPrice;
            }
        }

        $missingPriceTypeIds = $this->typeIdsWithoutPrice($allTypeIds, $prices);

        return [
            'stages' => $stages,
            'totalItems' => $totalItems,
            'estimatedCost' => $missingPriceTypeIds === [] ? round($estimatedCost, 2) : null,
            'missingPriceTypeIds' => $missingPriceTypeIds,
        ];
    }

    /**
     * Import targets from a blueprint production tree.
     *
     * @param string $mode 'replace' or 'merge'
     */
    public function importFromBlueprint(User $user, int $typeId, int $runs, int $me, string $mode): void
    {
        $stockpileTargets = $this->stockpileTargetsOf($user, $typeId, $runs, $me);

        if ($mode === 'replace') {
            $this->targetRepository->deleteAllForUser($user);
            $this->importRepository->deleteAllForUser($user);
            $this->entityManager->flush();
        }

        $this->entityManager->persist(new IndustryStockpileImport($user, $typeId, $runs, $me));

        foreach ($stockpileTargets as $matTypeId => $item) {
            if ($mode === 'merge') {
                $existing = $this->targetRepository->findByUserAndTypeId($user, $matTypeId);
                if ($existing !== null) {
                    $existing->setTargetQuantity($existing->getTargetQuantity() + $item['quantity']);
                    $existing->setUpdatedAt(new \DateTimeImmutable());
                    continue;
                }
            }

            $target = new IndustryStockpileTarget();
            $target->setUser($user);
            $target->setTypeId($matTypeId);
            $target->setTypeName($item['typeName']);
            $target->setTargetQuantity($item['quantity']);
            $target->setStage($item['stage']);
            $target->setSourceProductTypeId($typeId);
            $this->entityManager->persist($target);
        }

        $this->entityManager->flush();
    }

    /**
     * Compute the full stockpile status with KPIs for a user.
     *
     * @return array{targetCount: int, stages: array<string, array{items: list<array<string, mixed>>, totalValue: float|null, healthPercent: float}>, kpis: array{pipelineHealth: float, totalInvested: float|null, bottleneck: array<string, mixed>|null, estOutput: array{ready: int, total: int, readyNames: list<string>}}, shoppingList: list<array<string, mixed>>, importsWithoutPlan: list<int>, failedProducts: list<array{typeId: int, reason: string}>, missingPriceTypeIds: list<int>}
     */
    public function getStockpileStatus(User $user): array
    {
        $targets = $this->targetRepository->findByUser($user);

        if (empty($targets)) {
            return [
                'targetCount' => 0,
                'stages' => self::EMPTY_STAGES,
                'kpis' => [
                    'pipelineHealth' => 100.0,
                    'totalInvested' => 0.0,
                    'bottleneck' => null,
                    'estOutput' => ['ready' => 0, 'total' => 0, 'readyNames' => []],
                ],
                'shoppingList' => [],
                'importsWithoutPlan' => [],
                'failedProducts' => [],
                'missingPriceTypeIds' => [],
            ];
        }

        $stockData = $this->assetRepository->getQuantitiesByUserWithLocations($user);
        $assetQuantities = array_map(fn (array $data) => $data['total'], $stockData);
        $typeIds = array_map(fn (IndustryStockpileTarget $t) => $t->getTypeId(), $targets);
        $prices = $this->jitaMarketService->getPricesWithFallback($typeIds);

        $startingStockReduction = $this->reduceTargetsByStartingStock($user, $targets, $assetQuantities);
        $remainingTargetQuantities = $startingStockReduction['remainingTargetQuantities'];

        // Build computed items grouped by stage
        $stageGroups = [];
        $allItems = [];
        $metCount = 0;
        $totalInvested = 0.0;
        $worstItem = null;
        $shoppingList = [];

        foreach ($targets as $target) {
            $tid = $target->getTypeId();
            $stock = $assetQuantities[$tid] ?? 0;
            $remainingTargetQty = $remainingTargetQuantities[$tid];
            $percent = $remainingTargetQty > 0
                ? min(self::FULL_PERMILLE, intdiv($stock * self::FULL_PERMILLE, $remainingTargetQty)) / self::PERMILLE_PER_PERCENT
                : 100.0;

            if ($stock >= $remainingTargetQty) {
                $status = 'met';
                $metCount++;
            } elseif ($percent >= 50) {
                $status = 'partial';
            } else {
                $status = 'critical';
            }

            $unitPrice = $prices[$tid] ?? null;
            $stockValue = $this->amountOf($stock, $unitPrice);
            $deficit = max(0, $remainingTargetQty - $stock);
            $deficitCost = $this->amountOf($deficit, $unitPrice);
            $totalInvested = $stockValue === null || $totalInvested === null ? null : $totalInvested + $stockValue;

            $item = [
                'id' => $target->getId()?->toRfc4122(),
                'typeId' => $tid,
                'typeName' => $target->getTypeName(),
                'targetQuantity' => $target->getTargetQuantity(),
                'remainingTargetQuantity' => $remainingTargetQty,
                'stock' => $stock,
                'percent' => $percent,
                'status' => $status,
                'unitPrice' => $unitPrice,
                'stockValue' => $stockValue === null ? null : round($stockValue, 2),
                'deficit' => $deficit,
                'deficitCost' => $deficitCost === null ? null : round($deficitCost, 2),
                'stage' => $target->getStage(),
                'sourceProductTypeId' => $target->getSourceProductTypeId(),
                'locations' => $stockData[$tid]['locations'] ?? [],
            ];

            $stage = $target->getStage();
            $stageGroups[$stage][] = $item;
            $allItems[] = $item;

            if ($percent < 100 && ($worstItem === null || $percent < $worstItem['percent'])) {
                $worstItem = $item;
            }

            if ($stock < $remainingTargetQty) {
                $shoppingList[] = $item;
            }
        }

        // Sort shopping list by percent ASC (most critical first)
        usort($shoppingList, static fn (array $a, array $b) => $a['percent'] <=> $b['percent']);

        // Build stages output with health metrics (initialize all 4 stages)
        $stages = self::EMPTY_STAGES;
        foreach ($stageGroups as $stageName => $items) {
            $stageMet = 0;
            $stageTotalValue = 0.0;
            foreach ($items as $item) {
                if ($item['percent'] >= 100) {
                    $stageMet++;
                }
                $stageTotalValue = $item['stockValue'] === null || $stageTotalValue === null
                    ? null
                    : $stageTotalValue + $item['stockValue'];
            }

            $stages[$stageName] = [
                'items' => $items,
                'totalValue' => $stageTotalValue === null ? null : round($stageTotalValue, 2),
                'healthPercent' => count($items) > 0
                    ? round(($stageMet / count($items)) * 100, 1)
                    : 100.0,
            ];
        }

        // KPI: pipeline health
        $totalTargets = count($targets);
        $pipelineHealth = $totalTargets > 0
            ? round(($metCount / $totalTargets) * 100, 1)
            : 100.0;

        // KPI: bottleneck
        $bottleneck = null;
        if ($worstItem !== null) {
            $bottleneck = [
                'typeId' => $worstItem['typeId'],
                'typeName' => $worstItem['typeName'],
                'percent' => $worstItem['percent'],
                'blocksProducts' => $this->countBlockedProducts($targets, $worstItem['typeId']),
            ];
        }

        // KPI: estimated output
        $estOutput = $this->computeEstOutput($targets, $assetQuantities, $remainingTargetQuantities);

        return [
            'targetCount' => $totalTargets,
            'stages' => $stages,
            'kpis' => [
                'pipelineHealth' => $pipelineHealth,
                'totalInvested' => $totalInvested === null ? null : round($totalInvested, 2),
                'bottleneck' => $bottleneck,
                'estOutput' => $estOutput,
            ],
            'shoppingList' => $shoppingList,
            'importsWithoutPlan' => $startingStockReduction['importsWithoutPlan'],
            'failedProducts' => $startingStockReduction['failedProducts'],
            'missingPriceTypeIds' => $this->typeIdsWithoutPrice($typeIds, $prices),
        ];
    }

    private function amountOf(int $quantity, ?float $unitPrice): ?float
    {
        if ($quantity === 0) {
            return 0.0;
        }

        return $unitPrice === null ? null : $quantity * $unitPrice;
    }

    /**
     * @param array<int> $typeIds
     * @param array<int, float|null> $prices
     * @return list<int>
     */
    private function typeIdsWithoutPrice(array $typeIds, array $prices): array
    {
        $missing = array_unique(array_filter(
            $typeIds,
            static fn (int $typeId): bool => ($prices[$typeId] ?? null) === null,
        ));
        sort($missing);

        return $missing;
    }

    /**
     * @param IndustryStockpileTarget[] $targets
     * @param array<int, int> $assetQuantities
     * @return array{remainingTargetQuantities: array<int, int>, importsWithoutPlan: list<int>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    private function reduceTargetsByStartingStock(User $user, array $targets, array $assetQuantities): array
    {
        $stockpileImports = $this->importRepository->findByUserInCreationOrder($user);
        $importedProductTypeIds = array_map(static fn (IndustryStockpileImport $i): int => $i->getProductTypeId(), $stockpileImports);
        $targetSourceProductTypeIds = array_map(static fn (IndustryStockpileTarget $t): ?int => $t->getSourceProductTypeId(), $targets);
        $importsWithTargets = array_values(array_filter(
            $stockpileImports,
            static fn (IndustryStockpileImport $i): bool => in_array($i->getProductTypeId(), $targetSourceProductTypeIds, true),
        ));
        $reduction = $this->startingStockReducer->reduce($importsWithTargets, $assetQuantities, $user);

        $remainingTargetQuantities = [];
        $importsWithoutPlan = [];
        foreach ($targets as $target) {
            $source = $target->getSourceProductTypeId();
            $isImportRecorded = in_array($source, $importedProductTypeIds, true);
            $remainingTargetQuantities[$target->getTypeId()] = $isImportRecorded
                ? max(0, $target->getTargetQuantity() - ($reduction['reductionByTypeId'][$target->getTypeId()] ?? 0))
                : $target->getTargetQuantity();
            if ($source !== null && !$isImportRecorded) {
                $importsWithoutPlan[$source] = $source;
            }
        }
        ksort($importsWithoutPlan);

        return [
            'remainingTargetQuantities' => $remainingTargetQuantities,
            'importsWithoutPlan' => array_values($importsWithoutPlan),
            'failedProducts' => $reduction['failedProducts'],
        ];
    }

    /**
     * @return array<int, array{typeName: string, quantity: int, stage: string}>
     */
    private function stockpileTargetsOf(User $user, int $typeId, int $runs, int $me): array
    {
        $producer = $this->producerOf($typeId);
        $plan = $this->planOfImport($user, $producer, $runs, $me);
        $needs = self::needsOf($plan);
        ksort($needs);
        $typeNames = $this->sdeTypeNames([$typeId, ...array_keys($needs)]);
        $typeIdsConsumedByTheProduct = array_merge(...array_map(
            static fn (Job $job): array => array_keys($job->materials),
            $plan->jobsFor($typeId),
        ));

        $targets = [$typeId => ['typeName' => $typeNames[$typeId], 'quantity' => $runs * $producer->getQuantity(), 'stage' => 'final_product']];
        foreach ($needs as $needTypeId => $quantity) {
            $targets[$needTypeId] = [
                'typeName' => $typeNames[$needTypeId],
                'quantity' => $quantity,
                'stage' => match (true) {
                    isset($plan->leaves[$needTypeId]) => 'raw_material',
                    \in_array($needTypeId, $typeIdsConsumedByTheProduct, true) => 'component',
                    default => 'intermediate',
                },
            ];
        }

        return $targets;
    }

    /**
     * @param list<int> $typeIds
     * @return array<int, string>
     */
    private function sdeTypeNames(array $typeIds): array
    {
        $typeNames = [];
        foreach ($this->entityManager->getRepository(InvType::class)->findBy(['typeId' => $typeIds]) as $type) {
            $typeNames[$type->getTypeId()] = $type->getTypeName();
        }

        $missingTypeIds = array_values(array_diff($typeIds, array_keys($typeNames)));
        if ($missingTypeIds !== []) {
            throw new TypeMissingFromSdeException($missingTypeIds);
        }

        return $typeNames;
    }

    /**
     * Count how many distinct final products are blocked by a given target typeId.
     *
     * @param IndustryStockpileTarget[] $targets
     */
    private function countBlockedProducts(array $targets, int $typeId): int
    {
        // Find which sourceProductTypeIds share this typeId's source
        $sourceIds = [];
        $mySource = null;
        foreach ($targets as $t) {
            if ($t->getTypeId() === $typeId) {
                $mySource = $t->getSourceProductTypeId();
            }
        }

        if ($mySource === null) {
            return 0;
        }

        $count = 0;
        foreach ($targets as $t) {
            if ($t->getStage() === 'final_product' && $t->getSourceProductTypeId() === $mySource) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param IndustryStockpileTarget[] $targets
     * @param array<int, int> $assetQuantities
     * @param array<int, int> $remainingTargetQuantities
     * @return array{ready: int, total: int, readyNames: list<string>}
     */
    private function computeEstOutput(array $targets, array $assetQuantities, array $remainingTargetQuantities): array
    {
        // Group targets by sourceProductTypeId
        $bySource = [];
        $finalProducts = [];

        foreach ($targets as $target) {
            $source = $target->getSourceProductTypeId();
            if ($source === null) {
                continue;
            }
            $bySource[$source][] = $target;
            if ($target->getStage() === 'final_product') {
                $finalProducts[] = [$target, $source];
            }
        }

        $total = count($finalProducts);
        $ready = 0;
        $readyNames = [];

        foreach ($finalProducts as [$finalProduct, $source]) {
            $upstreamMet = true;
            foreach ($bySource[$source] as $target) {
                if ($target !== $finalProduct && !$this->isRemainingTargetMet($target, $assetQuantities, $remainingTargetQuantities)) {
                    $upstreamMet = false;
                    break;
                }
            }

            if ($upstreamMet || $this->isRemainingTargetMet($finalProduct, $assetQuantities, $remainingTargetQuantities)) {
                $ready++;
                $readyNames[] = $finalProduct->getTypeName();
            }
        }

        return ['ready' => $ready, 'total' => $total, 'readyNames' => $readyNames];
    }

    /**
     * @param array<int, int> $assetQuantities
     * @param array<int, int> $remainingTargetQuantities
     */
    private function isRemainingTargetMet(IndustryStockpileTarget $target, array $assetQuantities, array $remainingTargetQuantities): bool
    {
        return ($assetQuantities[$target->getTypeId()] ?? 0) >= $remainingTargetQuantities[$target->getTypeId()];
    }
}
