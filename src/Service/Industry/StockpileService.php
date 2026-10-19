<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;

class StockpileService
{
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
        private readonly IndustryTreeService $treeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryStockpileImportRepository $importRepository,
        private readonly StockpileStartingStockReducer $startingStockReducer,
    ) {
    }

    /**
     * Preview the import of a blueprint into stockpile targets.
     *
     * @return array{stages: array<string, list<array{typeId: int, typeName: string, quantity: int, unitPrice: float|null}>>, totalItems: int, estimatedCost: float}
     */
    public function previewImport(User $user, int $typeId, int $runs, int $me): array
    {
        $tree = $this->treeService->buildProductionTree($typeId, $runs, $me, [], $user);

        $flatTargets = $this->flattenTree($tree, $typeId);

        $allTypeIds = array_keys($flatTargets);
        $prices = $this->jitaMarketService->getPrices($allTypeIds);

        $stages = [
            'raw_material' => [],
            'intermediate' => [],
            'component' => [],
            'final_product' => [],
        ];

        $totalItems = 0;
        $estimatedCost = 0.0;

        foreach ($flatTargets as $matTypeId => $item) {
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

        return [
            'stages' => $stages,
            'totalItems' => $totalItems,
            'estimatedCost' => round($estimatedCost, 2),
        ];
    }

    /**
     * Import targets from a blueprint production tree.
     *
     * @param string $mode 'replace' or 'merge'
     */
    public function importFromBlueprint(User $user, int $typeId, int $runs, int $me, string $mode): void
    {
        $tree = $this->treeService->buildProductionTree($typeId, $runs, $me, [], $user);
        $flatTargets = $this->flattenTree($tree, $typeId);

        if ($mode === 'replace') {
            $this->targetRepository->deleteAllForUser($user);
            $this->importRepository->deleteAllForUser($user);
            $this->entityManager->flush();
        }

        $this->entityManager->persist(new IndustryStockpileImport($user, $typeId, $runs, $me));

        foreach ($flatTargets as $matTypeId => $item) {
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
     * @return array{targetCount: int, stages: array<string, array{items: list<array<string, mixed>>, totalValue: float, healthPercent: float}>, kpis: array{pipelineHealth: float, totalInvested: float, bottleneck: array<string, mixed>|null, estOutput: array{ready: int, total: int, readyNames: list<string>}}, shoppingList: list<array<string, mixed>>, importsWithoutPlan: list<int>, failedProducts: list<array{typeId: int, reason: string}>}
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
            ];
        }

        $stockData = $this->assetRepository->getQuantitiesByUserWithLocations($user);
        $assetQuantities = array_map(fn (array $data) => $data['total'], $stockData);
        $typeIds = array_map(fn (IndustryStockpileTarget $t) => $t->getTypeId(), $targets);
        $prices = $this->jitaMarketService->getPrices($typeIds);

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

            $unitPrice = $prices[$tid] ?? 0.0;
            $stockValue = $stock * ($unitPrice ?? 0.0);
            $deficitCost = max(0, $remainingTargetQty - $stock) * ($unitPrice ?? 0.0);
            $totalInvested += $stockValue;

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
                'stockValue' => round($stockValue, 2),
                'deficitCost' => round($deficitCost, 2),
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
                $stageTotalValue += $item['stockValue'];
            }

            $stages[$stageName] = [
                'items' => $items,
                'totalValue' => round($stageTotalValue, 2),
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
                'totalInvested' => round($totalInvested, 2),
                'bottleneck' => $bottleneck,
                'estOutput' => $estOutput,
            ],
            'shoppingList' => $shoppingList,
            'importsWithoutPlan' => $startingStockReduction['importsWithoutPlan'],
            'failedProducts' => $startingStockReduction['failedProducts'],
        ];
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
     * Flatten a production tree into a map of typeId => {typeName, quantity, stage}.
     * Merges duplicate typeIds by summing quantities.
     *
     * @param array<string, mixed> $tree
     * @return array<int, array{typeName: string, quantity: int, stage: string}>
     */
    public function flattenTree(array $tree, int $rootTypeId): array
    {
        $targets = [];

        // Add the root product as final_product
        $rootTypeName = (string) $tree['productTypeName'];
        $rootQuantity = (int) $tree['runs'] * (int) $tree['outputPerRun'];
        $targets[$rootTypeId] = [
            'typeName' => $rootTypeName,
            'quantity' => $rootQuantity,
            'stage' => 'final_product',
        ];

        // Walk the materials tree
        $this->collectMaterials($tree['materials'], $targets, 0);

        return $targets;
    }

    /**
     * Recursively collect materials from the tree, classifying by stage.
     *
     * @param list<array<string, mixed>> $materials
     * @param array<int, array{typeName: string, quantity: int, stage: string}> $targets
     */
    private function collectMaterials(array $materials, array &$targets, int $parentDepth): void
    {
        foreach ($materials as $material) {
            $matTypeId = (int) $material['typeId'];
            $matTypeName = (string) $material['typeName'];
            $quantity = (int) $material['quantity'];
            $isBuildable = (bool) ($material['isBuildable'] ?? false);

            if (!$isBuildable) {
                $stage = 'raw_material';
            } elseif ($parentDepth === 0) {
                $stage = 'component';
            } else {
                $stage = 'intermediate';
            }

            if (isset($targets[$matTypeId])) {
                $targets[$matTypeId]['quantity'] += $quantity;
            } else {
                $targets[$matTypeId] = [
                    'typeName' => $matTypeName,
                    'quantity' => $quantity,
                    'stage' => $stage,
                ];
            }

            // Recurse into buildable sub-materials
            if ($isBuildable && isset($material['blueprint']['materials'])) {
                $childDepth = (int) ($material['blueprint']['depth'] ?? $parentDepth + 1);
                $this->collectMaterials($material['blueprint']['materials'], $targets, $childDepth);
            }
        }
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
