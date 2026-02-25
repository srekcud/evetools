<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Batch scans all manufacturable/reactable products to rank by profit margin.
 * Optimized for bulk processing: single queries for products, materials, prices.
 */
class BatchProfitScannerService
{
    /**
     * Category filter definitions.
     * Each entry maps to SDE categoryId, groupId constraints, or activity filters.
     *
     * @var array<string, array<string, mixed>>
     */
    public const CATEGORIES = [
        'all' => [],
        't1_ships' => ['categoryId' => 6, 'excludeT2' => true],
        't2_ships' => ['categoryId' => 6, 'onlyT2' => true],
        'capitals' => ['categoryId' => 6, 'capitalOnly' => true],
        't1_modules' => ['categoryId' => 7, 'excludeT2' => true],
        't2_modules' => ['categoryId' => 7, 'onlyT2' => true],
        'ammo_charges' => ['categoryId' => 8],
        'drones' => ['categoryId' => 18],
        'rigs' => ['categoryId' => 7, 'rigGroupIds' => self::RIG_GROUP_IDS],
        'components' => ['categoryId' => 17],
        'reactions' => ['activityId' => 11],
    ];

    private const CAPITAL_GROUP_IDS = [
        30,   // Titan
        485,  // Dreadnought
        513,  // Freighter
        547,  // Carrier
        659,  // Supercarrier
        883,  // Capital Industrial Ship
        902,  // Jump Freighter
        1538, // Force Auxiliary
    ];

    private const RIG_GROUP_IDS = [773, 774, 775, 776, 777, 778, 786, 787];

    /**
     * Standard packaged volumes for ships by group ID.
     * Ships in EVE have much smaller packaged volumes than assembled volumes.
     * The SDE only stores assembled volume, so we need this lookup.
     *
     * @var array<int, int>
     */
    private const SHIP_PACKAGED_VOLUMES = [
        // Frigates & variants (2,500 m3)
        25 => 2_500,     // Frigate
        324 => 2_500,    // Assault Frigate
        830 => 2_500,    // Covert Ops
        831 => 2_500,    // Interceptor
        834 => 2_500,    // Stealth Bomber
        893 => 2_500,    // Electronic Attack Ship
        1527 => 2_500,   // Logistics Frigate
        // Destroyers & variants (5,000 m3)
        420 => 5_000,    // Destroyer
        541 => 5_000,    // Interdictor
        1305 => 5_000,   // Tactical Destroyer
        1534 => 5_000,   // Command Destroyer
        // Cruisers & variants (10,000 m3)
        26 => 10_000,    // Cruiser
        358 => 10_000,   // Heavy Assault Cruiser
        832 => 10_000,   // Force Recon Ship
        833 => 10_000,   // Combat Recon Ship
        894 => 10_000,   // Heavy Interdiction Cruiser
        906 => 10_000,   // Combat Battlecruiser
        963 => 10_000,   // Strategic Cruiser
        1972 => 10_000,  // Flag Cruiser
        // Battlecruisers & variants (15,000 m3)
        419 => 15_000,   // Combat Battlecruiser
        540 => 15_000,   // Command Ship
        // Battleships & variants (50,000 m3)
        27 => 50_000,    // Battleship
        898 => 50_000,   // Black Ops
        900 => 50_000,   // Marauder
        // Industrials (20,000 m3)
        28 => 20_000,    // Industrial
        380 => 20_000,   // Transport Ship
        1202 => 20_000,  // Blockade Runner
        // Mining ships
        463 => 3_750,    // Mining Barge
        543 => 50_000,   // Exhumer
        // Capitals
        485 => 1_300_000,   // Dreadnought
        547 => 1_300_000,   // Carrier
        1538 => 1_300_000,  // Force Auxiliary
        883 => 1_000_000,   // Capital Industrial Ship
        513 => 1_000_000,   // Freighter
        902 => 1_000_000,   // Jump Freighter
        659 => 1_300_000,   // Supercarrier
        30 => 10_000_000,   // Titan
    ];

    public function __construct(
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly InventionService $inventionService,
    ) {
    }

    /**
     * Scan all manufacturable products and return ranked profit data.
     *
     * @return list<array{typeId: int, typeName: string, groupName: string, categoryLabel: string, marginPercent: float, profitPerUnit: float, dailyVolume: float, iskPerDay: float, materialCost: float, importCost: float, exportCost: float, sellPrice: float, meUsed: int, activityType: string}>
     */
    public function scan(
        ?string $category,
        ?float $minMarginPercent,
        ?float $minDailyVolume,
        string $sellVenue,
        ?int $structureId,
        int $solarSystemId,
        float $brokerFeeRate,
        float $salesTaxRate,
        float $exportCostPerM3,
        ?User $user,
    ): array {
        // 1. Fetch all manufacturable products
        $allProducts = $this->productRepository->findAllManufacturableProducts();

        if (empty($allProducts)) {
            return [];
        }

        // 2. Identify T2 products (those with an invention activity_id=8 path)
        $t2ProductIds = $this->inventionService->identifyT2Products($allProducts);

        // 3. Load type metadata (group, category, volume) for filtering
        $productTypeIds = array_map(fn (array $p) => $p['productTypeId'], $allProducts);
        $typeMetadata = $this->loadTypeMetadata($productTypeIds);

        // 4. Filter by category early to reduce work
        $filteredProducts = $this->filterByCategory($allProducts, $category ?? 'all', $t2ProductIds, $typeMetadata);

        if (empty($filteredProducts)) {
            return [];
        }

        // 5. Bulk-fetch materials for all remaining blueprints
        $blueprintTypeIds = array_unique(array_map(fn (array $p) => $p['blueprintTypeId'], $filteredProducts));
        $activityIds = [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value];
        $materialsByBlueprint = $this->materialRepository->findMaterialsForBlueprints($blueprintTypeIds, $activityIds);

        // 6. Collect all unique type IDs needed for pricing
        $allTypeIds = [];
        foreach ($filteredProducts as $product) {
            $allTypeIds[$product['productTypeId']] = true;
        }
        foreach ($materialsByBlueprint as $materials) {
            foreach ($materials as $mat) {
                $allTypeIds[$mat['materialTypeId']] = true;
            }
        }
        $uniqueTypeIds = array_keys($allTypeIds);

        // 6b. Load volumes for material types (for import cost calculation)
        $materialTypeIds = [];
        foreach ($materialsByBlueprint as $materials) {
            foreach ($materials as $mat) {
                $materialTypeIds[$mat['materialTypeId']] = true;
            }
        }
        $materialVolumes = $this->loadTypeVolumes(array_keys($materialTypeIds));

        // 7. Batch prices
        $jitaPrices = $this->jitaMarketService->getPrices($uniqueTypeIds);

        // Structure prices if needed
        $structurePrices = [];
        if ($sellVenue === 'structure' && $structureId !== null) {
            $productTypeIdsForStructure = array_map(fn (array $p) => $p['productTypeId'], $filteredProducts);
            $structurePrices = $this->structureMarketService->getLowestSellPrices($structureId, $productTypeIdsForStructure);
        }

        // 8. Batch daily volumes — use sell region if structure, else The Forge (Jita)
        $productTypeIdsFiltered = array_map(fn (array $p) => $p['productTypeId'], $filteredProducts);

        if ($sellVenue === 'structure' && $structureId !== null) {
            $volumeRegionId = $this->resolveStructureRegionId($structureId);
            if ($volumeRegionId !== null) {
                $dailyVolumes = $this->jitaMarketService->getAverageDailyVolumesForRegion($volumeRegionId, $productTypeIdsFiltered);
            } else {
                $dailyVolumes = $this->jitaMarketService->getCachedDailyVolumes($productTypeIdsFiltered);
            }
        } else {
            $dailyVolumes = $this->jitaMarketService->getCachedDailyVolumes($productTypeIdsFiltered);
        }

        // 9. Resolve type names
        $typeNames = $this->typeNameResolver->resolveMany($uniqueTypeIds);

        // 10. Calculate profit for each product
        $results = [];
        foreach ($filteredProducts as $product) {
            $typeId = $product['productTypeId'];
            $blueprintTypeId = $product['blueprintTypeId'];
            $outputPerRun = $product['outputPerRun'];
            $activityId = $product['activityId'];
            $isT2 = isset($t2ProductIds[$typeId]);
            $isReaction = $activityId === IndustryActivityType::Reaction->value;

            // ME: 2 for T2 (Attainment decryptor default), 0 for reaction, 10 otherwise
            $me = $isT2 ? 2 : ($isReaction ? 0 : 10);

            $activityType = $isReaction ? 'reaction' : 'manufacturing';

            // Material cost (1 run) and material volume (for import cost)
            $materials = $materialsByBlueprint[$blueprintTypeId] ?? [];
            $materialCost = 0.0;
            $materialVolume = 0.0;
            foreach ($materials as $mat) {
                $baseQty = $mat['quantity'];
                // Apply ME reduction (only for manufacturing, not reactions)
                $meMultiplier = !$isReaction && $me > 0 ? (1 - $me / 100) : 1.0;
                $adjustedQty = max(1, (int) ceil($baseQty * $meMultiplier));
                $unitPrice = $jitaPrices[$mat['materialTypeId']] ?? 0.0;
                $materialCost += $adjustedQty * $unitPrice;
                $materialVolume += $adjustedQty * ($materialVolumes[$mat['materialTypeId']] ?? 0.0);
            }

            // Import cost: transport materials from Jita to production site
            $importCost = $materialVolume * $exportCostPerM3;

            // Job install cost (1 run) — EIV uses ME0 quantities from SDE
            $eiv = $this->esiCostIndexService->calculateEiv($materials);
            $jobInstallCost = $this->esiCostIndexService->calculateJobInstallCost(
                $eiv,
                1,
                $solarSystemId,
                $activityType,
            );

            // Export cost: only when selling remotely (Jita), not at local structure
            $exportCost = 0.0;
            if ($sellVenue !== 'structure') {
                $exportVolume = $this->getExportVolume($typeId, $typeMetadata);
                $exportCost = $exportVolume * $exportCostPerM3;
            }

            $totalCost = $materialCost + $jobInstallCost + $importCost + $exportCost;

            // Sell price based on venue
            $sellPrice = match ($sellVenue) {
                'structure' => $structureId !== null ? ($structurePrices[$typeId] ?? null) : null,
                default => $jitaPrices[$typeId] ?? null,
            };

            if ($sellPrice === null || $sellPrice <= 0.0 || $totalCost <= 0.0) {
                continue;
            }

            $profitPerUnit = ($sellPrice * $outputPerRun - $totalCost) / $outputPerRun;
            // Apply broker + sales tax to sell price
            $fees = $sellPrice * ($brokerFeeRate + $salesTaxRate);
            $netSellPrice = $sellPrice - $fees;
            $netProfitPerUnit = ($netSellPrice * $outputPerRun - $totalCost) / $outputPerRun;
            $marginPercent = $totalCost > 0 ? ($netProfitPerUnit * $outputPerRun / $totalCost) * 100 : 0.0;

            $dailyVolume = $dailyVolumes[$typeId] ?? 0.0;
            $iskPerDay = $netProfitPerUnit * $dailyVolume;

            // Apply filters
            if ($minMarginPercent !== null && $marginPercent < $minMarginPercent) {
                continue;
            }
            if ($minDailyVolume !== null && $dailyVolume < $minDailyVolume) {
                continue;
            }

            $meta = $typeMetadata[$typeId] ?? null;
            $categoryLabel = $this->resolveCategoryLabel($isT2, $isReaction, $meta);

            $results[] = [
                'typeId' => $typeId,
                'typeName' => $typeNames[$typeId] ?? "Type #{$typeId}",
                'groupName' => $meta['groupName'] ?? '',
                'categoryLabel' => $categoryLabel,
                'marginPercent' => round($marginPercent, 2),
                'profitPerUnit' => round($netProfitPerUnit, 2),
                'dailyVolume' => $dailyVolume,
                'iskPerDay' => round($iskPerDay, 2),
                'materialCost' => round($materialCost, 2),
                'importCost' => round($importCost, 2),
                'exportCost' => round($exportCost, 2),
                'sellPrice' => round($sellPrice, 2),
                'meUsed' => $me,
                'activityType' => $activityType,
            ];
        }

        // Sort by iskPerDay descending
        usort($results, static fn (array $a, array $b) => $b['iskPerDay'] <=> $a['iskPerDay']);

        return $results;
    }

    /**
     * Load type metadata (groupId, groupName, categoryId, volume) for filtering.
     *
     * @param int[] $typeIds
     * @return array<int, array{groupId: int, groupName: string, categoryId: int, volume: float|null}>
     */
    private function loadTypeMetadata(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $conn = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));

        $sql = <<<SQL
            SELECT t.type_id, g.group_id, g.group_name, c.category_id, t.volume
            FROM sde_inv_types t
            JOIN sde_inv_groups g ON g.group_id = t.group_id
            JOIN sde_inv_categories c ON c.category_id = g.category_id
            WHERE t.type_id IN ({$placeholders})
        SQL;

        $rows = $conn->fetchAllAssociative($sql, array_values($typeIds));

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['type_id']] = [
                'groupId' => (int) $row['group_id'],
                'groupName' => (string) $row['group_name'],
                'categoryId' => (int) $row['category_id'],
                'volume' => $row['volume'] !== null ? (float) $row['volume'] : null,
            ];
        }

        return $result;
    }

    /**
     * Filter products by category definition.
     *
     * @param list<array{blueprintTypeId: int, productTypeId: int, outputPerRun: int, activityId: int}> $products
     * @param array<int, true> $t2ProductIds
     * @param array<int, array{groupId: int, groupName: string, categoryId: int, volume: float|null}> $typeMetadata
     * @return list<array{blueprintTypeId: int, productTypeId: int, outputPerRun: int, activityId: int}>
     */
    private function filterByCategory(array $products, string $categoryKey, array $t2ProductIds, array $typeMetadata): array
    {
        $categoryDef = self::CATEGORIES[$categoryKey] ?? [];

        if (empty($categoryDef)) {
            return $products;
        }

        return array_values(array_filter($products, function (array $product) use ($categoryDef, $t2ProductIds, $typeMetadata): bool {
            $typeId = $product['productTypeId'];
            $meta = $typeMetadata[$typeId] ?? null;
            $isT2 = isset($t2ProductIds[$typeId]);

            // Filter by activity
            if (isset($categoryDef['activityId']) && $product['activityId'] !== $categoryDef['activityId']) {
                return false;
            }

            // Filter by category
            if (isset($categoryDef['categoryId']) && $meta !== null && $meta['categoryId'] !== $categoryDef['categoryId']) {
                return false;
            }

            // Filter by rig group IDs
            if (isset($categoryDef['rigGroupIds']) && $meta !== null && !in_array($meta['groupId'], $categoryDef['rigGroupIds'], true)) {
                return false;
            }

            // T2 filtering
            if (isset($categoryDef['excludeT2']) && $categoryDef['excludeT2'] && $isT2) {
                return false;
            }
            if (isset($categoryDef['onlyT2']) && $categoryDef['onlyT2'] && !$isT2) {
                return false;
            }

            // Capital filtering (group ID-based)
            if (isset($categoryDef['capitalOnly']) && $categoryDef['capitalOnly']) {
                if ($meta === null || !in_array($meta['groupId'], self::CAPITAL_GROUP_IDS, true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Resolve a human-readable category label for display.
     * Labels match the frontend CATEGORY_OPTIONS for client-side filtering.
     *
     * @param array{groupId: int, groupName: string, categoryId: int, volume: float|null}|null $meta
     */
    private function resolveCategoryLabel(bool $isT2, bool $isReaction, ?array $meta): string
    {
        if ($isReaction) {
            return 'Reaction';
        }

        if ($meta === null) {
            return $isT2 ? 'T2' : 'T1';
        }

        $categoryId = $meta['categoryId'];
        $groupId = $meta['groupId'];

        if (in_array($groupId, self::CAPITAL_GROUP_IDS, true)) {
            return 'Capitals';
        }

        if (in_array($groupId, self::RIG_GROUP_IDS, true)) {
            return 'Rigs';
        }

        return match ($categoryId) {
            6 => $isT2 ? 'T2 Ships' : 'T1 Ships',       // Ships
            7 => $isT2 ? 'T2 Modules' : 'T1 Modules',   // Modules (non-rig)
            8 => 'Ammo & Charges',                        // Charges
            17 => 'Components',                           // Components
            18 => 'Drones',                               // Drones
            default => $isT2 ? 'T2' : 'T1',
        };
    }

    /**
     * Resolve the region ID for a structure via CachedStructure -> MapSolarSystem.
     */
    private function resolveStructureRegionId(int $structureId): ?int
    {
        $structure = $this->cachedStructureRepository->findByStructureId($structureId);
        if ($structure === null || $structure->getSolarSystemId() === null) {
            return null;
        }

        $solarSystem = $this->solarSystemRepository->find($structure->getSolarSystemId());

        return $solarSystem?->getRegionId();
    }

    /**
     * Get the volume to use for export cost calculation.
     * Ships use packaged volume (much smaller than assembled), other items use SDE volume.
     *
     * @param array<int, array{groupId: int, groupName: string, categoryId: int, volume: float|null}> $typeMetadata
     */
    private function getExportVolume(int $typeId, array $typeMetadata): float
    {
        $meta = $typeMetadata[$typeId] ?? null;
        if ($meta === null) {
            return 0.0;
        }

        // Ships (category 6): use packaged volume from lookup table
        if ($meta['categoryId'] === 6 && isset(self::SHIP_PACKAGED_VOLUMES[$meta['groupId']])) {
            return (float) self::SHIP_PACKAGED_VOLUMES[$meta['groupId']];
        }

        return $meta['volume'] ?? 0.0;
    }

    /**
     * Load volumes for type IDs from SDE.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => volume
     */
    private function loadTypeVolumes(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $conn = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $sql = "SELECT type_id, volume FROM sde_inv_types WHERE type_id IN ({$placeholders})";
        $rows = $conn->fetchAllAssociative($sql, array_values($typeIds));

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['type_id']] = (float) ($row['volume'] ?? 0.0);
        }

        return $result;
    }
}
