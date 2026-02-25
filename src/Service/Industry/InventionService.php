<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Constant\EveConstants;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;

/**
 * Calculates BPC copy costs, invention costs, and decryptor comparisons
 * for T2 manufacturing projects.
 */
class InventionService
{
    // Base invention output (no decryptor)
    public const BASE_INVENTION_ME = 2;
    public const BASE_INVENTION_TE = 4;

    /**
     * Standard decryptors with their modifiers.
     * These are static SDE data (group 1304) that rarely changes.
     *
     * @var array<int, array{name: string, probabilityMultiplier: float, meModifier: int, teModifier: int, runModifier: int}>
     */
    private const DECRYPTORS = [
        34201 => ['name' => 'Accelerant Decryptor', 'probabilityMultiplier' => 1.2, 'meModifier' => 2, 'teModifier' => 10, 'runModifier' => 1],
        34202 => ['name' => 'Attainment Decryptor', 'probabilityMultiplier' => 1.8, 'meModifier' => -1, 'teModifier' => 4, 'runModifier' => 4],
        34203 => ['name' => 'Augmentation Decryptor', 'probabilityMultiplier' => 0.6, 'meModifier' => -2, 'teModifier' => 2, 'runModifier' => 9],
        34204 => ['name' => 'Optimized Attainment Decryptor', 'probabilityMultiplier' => 1.0, 'meModifier' => 1, 'teModifier' => -2, 'runModifier' => 2],
        34205 => ['name' => 'Parity Decryptor', 'probabilityMultiplier' => 1.5, 'meModifier' => 1, 'teModifier' => -2, 'runModifier' => 3],
        34206 => ['name' => 'Process Decryptor', 'probabilityMultiplier' => 1.1, 'meModifier' => 3, 'teModifier' => 6, 'runModifier' => 0],
        34207 => ['name' => 'Symmetry Decryptor', 'probabilityMultiplier' => 1.0, 'meModifier' => 1, 'teModifier' => 8, 'runModifier' => 2],
        34208 => ['name' => 'Optimized Augmentation Decryptor', 'probabilityMultiplier' => 0.9, 'meModifier' => -2, 'teModifier' => 0, 'runModifier' => 7],
    ];

    public function __construct(
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityMaterialRepository $activityMaterialRepository,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * Calculate the job install cost for copying a blueprint.
     *
     * EVE formula: 0.02 x EIV x runs x system_cost_index x (1 + facility_tax / 100)
     * Where EIV = sum of (adjusted_price x quantity) of the blueprint's manufacturing outputs.
     */
    public function getCopyJobCost(
        int $blueprintTypeId,
        int $runs,
        int $solarSystemId,
        ?float $facilityTaxRate = null,
    ): float {
        $eiv = $this->calculateBlueprintEiv($blueprintTypeId);
        if ($eiv <= 0.0) {
            return 0.0;
        }

        $costIndex = $this->esiCostIndexService->getCostIndex($solarSystemId, 'copying');
        if ($costIndex === null) {
            return 0.0;
        }

        $taxMultiplier = 1.0 + ($facilityTaxRate ?? 0.0) / 100.0;

        return 0.02 * $eiv * $runs * $costIndex * $taxMultiplier;
    }

    /**
     * Check if a product type is T2 (has an invention path in the SDE).
     */
    public function isT2(int $productTypeId): bool
    {
        return $this->getInventionData($productTypeId) !== null;
    }

    /**
     * Identify T2 products from a batch list by checking invention paths.
     *
     * A product is T2 if its manufacturing blueprint is produced via invention (activity_id=8).
     * Uses a single batch query for efficiency.
     *
     * @param list<array{blueprintTypeId: int, productTypeId: int, outputPerRun: int, activityId: int}> $products
     * @return array<int, true> Set of T2 product type IDs
     */
    public function identifyT2Products(array $products): array
    {
        $manufacturingBlueprintIds = [];
        $blueprintToProduct = [];
        foreach ($products as $product) {
            if ($product['activityId'] === IndustryActivityType::Manufacturing->value) {
                $manufacturingBlueprintIds[] = $product['blueprintTypeId'];
                $blueprintToProduct[$product['blueprintTypeId']] = $product['productTypeId'];
            }
        }

        if (empty($manufacturingBlueprintIds)) {
            return [];
        }

        $t2BlueprintIds = $this->activityProductRepository->findInventedBlueprintIds($manufacturingBlueprintIds);

        $t2ProductIds = [];
        foreach ($t2BlueprintIds as $bpId => $_) {
            if (isset($blueprintToProduct[$bpId])) {
                $t2ProductIds[$blueprintToProduct[$bpId]] = true;
            }
        }

        return $t2ProductIds;
    }

    /**
     * Find the invention chain for a T2 item: which T1 blueprint invents into it.
     *
     * Flow:
     * 1. Find the T2 blueprint that manufactures this T2 item
     * 2. Find the T1 blueprint that invents into that T2 blueprint
     * 3. Get invention materials and probability
     *
     * @return array{t1BlueprintTypeId: int, t2BlueprintTypeId: int, probability: float, baseRuns: int, materials: list<array{typeId: int, typeName: string, quantity: int}>, inventionTime: int}|null
     */
    public function getInventionData(int $t2TypeId): ?array
    {
        // Step 1: Find T2 blueprint that manufactures this T2 item
        $t2Manufacturing = $this->activityProductRepository->findBlueprintForProduct(
            $t2TypeId,
            IndustryActivityType::Manufacturing->value,
        );

        if ($t2Manufacturing === null) {
            return null;
        }

        $t2BlueprintTypeId = $t2Manufacturing->getTypeId();

        // Step 2: Find T1 blueprint that invents into this T2 blueprint
        $inventionProduct = $this->activityProductRepository->findBlueprintForProduct(
            $t2BlueprintTypeId,
            IndustryActivityType::Invention->value,
        );

        if ($inventionProduct === null) {
            return null;
        }

        $t1BlueprintTypeId = $inventionProduct->getTypeId();
        $baseProbability = $inventionProduct->getProbability() ?? 0.0;
        $baseRuns = $inventionProduct->getQuantity();

        // Step 3: Get invention materials for the T1 blueprint
        $materialEntities = $this->activityMaterialRepository->findByBlueprintAndActivity(
            $t1BlueprintTypeId,
            IndustryActivityType::Invention->value,
        );

        $materialTypeIds = array_map(
            fn ($m) => $m->getMaterialTypeId(),
            $materialEntities,
        );
        $typeNames = $this->invTypeRepository->findByTypeIds($materialTypeIds);

        $materials = [];
        foreach ($materialEntities as $material) {
            $matTypeId = $material->getMaterialTypeId();
            $materials[] = [
                'typeId' => $matTypeId,
                'typeName' => $typeNames[$matTypeId]?->getTypeName() ?? "Type #{$matTypeId}",
                'quantity' => $material->getQuantity(),
            ];
        }

        // Get invention time from the activity table
        $inventionActivity = $this->activityRepository->findOneBy([
            'typeId' => $t1BlueprintTypeId,
            'activityId' => IndustryActivityType::Invention->value,
        ]);
        $inventionTime = $inventionActivity?->getTime() ?? 0;

        return [
            't1BlueprintTypeId' => $t1BlueprintTypeId,
            't2BlueprintTypeId' => $t2BlueprintTypeId,
            'probability' => $baseProbability,
            'baseRuns' => $baseRuns,
            'materials' => $materials,
            'inventionTime' => $inventionTime,
        ];
    }

    /**
     * Return all standard decryptors with their modifiers.
     *
     * @return array<int, array{name: string, probabilityMultiplier: float, meModifier: int, teModifier: int, runModifier: int}>
     */
    public function getDecryptorOptions(): array
    {
        return self::DECRYPTORS;
    }

    /**
     * Calculate the full invention cost for producing T2 BPCs.
     *
     * @return array{
     *     baseProbability: float,
     *     effectiveProbability: float,
     *     expectedAttempts: int,
     *     me: int,
     *     te: int,
     *     runs: int,
     *     costPerAttempt: float,
     *     totalCost: float,
     *     costBreakdown: array{datacores: float, decryptor: float, copyCost: float, inventionInstall: float},
     *     datacores: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>,
     *     decryptorName: ?string
     * }|null
     */
    public function calculateInventionCost(
        int $t2TypeId,
        int $solarSystemId,
        ?int $decryptorTypeId = null,
        int $desiredSuccesses = 1,
        ?float $facilityTaxRate = null,
    ): ?array {
        $inventionData = $this->getInventionData($t2TypeId);

        if ($inventionData === null) {
            return null;
        }

        $baseProbability = $inventionData['probability'];
        $baseRuns = $inventionData['baseRuns'];
        $t1BlueprintTypeId = $inventionData['t1BlueprintTypeId'];
        $t2BlueprintTypeId = $inventionData['t2BlueprintTypeId'];

        // Apply decryptor modifiers
        $probabilityMultiplier = 1.0;
        $meModifier = 0;
        $teModifier = 0;
        $runModifier = 0;
        $decryptorName = null;
        $decryptorPrice = 0.0;

        if ($decryptorTypeId !== null && isset(self::DECRYPTORS[$decryptorTypeId])) {
            $decryptor = self::DECRYPTORS[$decryptorTypeId];
            $probabilityMultiplier = $decryptor['probabilityMultiplier'];
            $meModifier = $decryptor['meModifier'];
            $teModifier = $decryptor['teModifier'];
            $runModifier = $decryptor['runModifier'];
            $decryptorName = $decryptor['name'];
            $decryptorPrice = $this->jitaMarketService->getPrice($decryptorTypeId) ?? 0.0;
        }

        $effectiveProbability = $baseProbability * $probabilityMultiplier;
        $me = self::BASE_INVENTION_ME + $meModifier;
        $te = self::BASE_INVENTION_TE + $teModifier;
        $runs = $baseRuns + $runModifier;
        $expectedAttempts = (int) ceil($desiredSuccesses / $effectiveProbability);

        // Cost per attempt: datacores + decryptor + T1 BPC copy cost + invention install
        $datacoreTypeIds = array_map(fn (array $m) => $m['typeId'], $inventionData['materials']);
        $datacorePrices = $this->jitaMarketService->getPricesWithFallback($datacoreTypeIds);

        $datacores = [];
        $datacoreTotalCost = 0.0;
        foreach ($inventionData['materials'] as $material) {
            $unitPrice = $datacorePrices[$material['typeId']] ?? 0.0;
            $totalPrice = $unitPrice * $material['quantity'];
            $datacoreTotalCost += $totalPrice;
            $datacores[] = [
                'typeId' => $material['typeId'],
                'typeName' => $material['typeName'],
                'quantity' => $material['quantity'],
                'unitPrice' => $unitPrice,
                'totalPrice' => $totalPrice,
            ];
        }

        $copyCost = $this->getCopyJobCost($t1BlueprintTypeId, 1, $solarSystemId, $facilityTaxRate);

        // Invention install cost: 0.02 x EIV(T2 blueprint) x system_cost_index x (1 + tax)
        $t2Eiv = $this->calculateBlueprintEiv($t2BlueprintTypeId);
        $inventionCostIndex = $this->esiCostIndexService->getCostIndex($solarSystemId, 'invention');
        $inventionTaxMultiplier = 1.0 + ($facilityTaxRate ?? 0.0) / 100.0;
        $inventionInstallCost = $inventionCostIndex !== null && $t2Eiv > 0
            ? 0.02 * $t2Eiv * $inventionCostIndex * $inventionTaxMultiplier
            : 0.0;

        $costPerAttempt = $datacoreTotalCost + $decryptorPrice + $copyCost + $inventionInstallCost;
        $totalCost = $expectedAttempts * $costPerAttempt;

        return [
            'baseProbability' => $baseProbability,
            'effectiveProbability' => $effectiveProbability,
            'expectedAttempts' => $expectedAttempts,
            'me' => $me,
            'te' => $te,
            'runs' => $runs,
            'costPerAttempt' => $costPerAttempt,
            'totalCost' => $totalCost,
            'costBreakdown' => [
                'datacores' => $datacoreTotalCost,
                'decryptor' => $decryptorPrice,
                'copyCost' => $copyCost,
                'inventionInstall' => $inventionInstallCost,
            ],
            'datacores' => $datacores,
            'decryptorName' => $decryptorName,
        ];
    }

    /**
     * Get BPC kit breakdown for a project (invention data only).
     *
     * For T2 items: invention costs with each decryptor option + "no decryptor" baseline.
     * Copy costs are handled separately by getProjectCopyCosts().
     *
     * @return array{
     *     isT2: bool,
     *     inventions: list<array{
     *         productTypeId: int,
     *         productName: string,
     *         baseProbability: float,
     *         desiredSuccesses: int,
     *         datacores: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>,
     *         decryptorOptions: list<array{
     *             decryptorTypeId: ?int,
     *             decryptorName: string,
     *             me: int,
     *             te: int,
     *             runs: int,
     *             probability: float,
     *             costPerAttempt: float,
     *             expectedAttempts: int,
     *             totalCost: float,
     *             costBreakdown: array{datacores: float, decryptor: float, copyCost: float, inventionInstall: float}
     *         }>
     *     }>,
     *     summary: array{totalInventionCost: float, bestDecryptorTypeId: ?int, totalBpcKitCost: float}
     * }
     */
    public function getBpcKitBreakdown(IndustryProject $project, int $desiredBpcCount = 1): array
    {
        $productTypeId = $project->getProductTypeId();
        $solarSystemId = $this->resolveSolarSystemForProject($project);

        // Check if this is a T2 item (has invention path in SDE)
        $inventionData = $this->getInventionData($productTypeId);
        $isT2 = $inventionData !== null;

        $inventions = [];
        $totalInventionCost = 0.0;
        $bestDecryptorTypeId = null;

        $facilityTaxRate = $this->resolveFacilityTaxRate($project);

        if ($isT2) {
            // T2 item: calculate invention costs with all decryptor options
            $decryptorOptions = $this->buildDecryptorOptions(
                $productTypeId,
                $solarSystemId,
                $desiredBpcCount,
                $facilityTaxRate,
            );

            // Get datacores info from the "no decryptor" result (materials are the same regardless)
            $noDecryptorResult = $this->calculateInventionCost(
                $productTypeId,
                $solarSystemId,
                null,
                $desiredBpcCount,
                $facilityTaxRate,
            );

            $productName = $this->resolveTypeName($productTypeId);

            $inventions[] = [
                'productTypeId' => $productTypeId,
                'productName' => $productName,
                'baseProbability' => $inventionData['probability'],
                'desiredSuccesses' => $desiredBpcCount,
                'datacores' => $noDecryptorResult['datacores'] ?? [],
                'decryptorOptions' => $decryptorOptions,
            ];

            // Find cheapest option for the summary
            $cheapestCost = PHP_FLOAT_MAX;
            foreach ($decryptorOptions as $option) {
                if ($option['totalCost'] < $cheapestCost) {
                    $cheapestCost = $option['totalCost'];
                    $bestDecryptorTypeId = $option['decryptorTypeId'];
                }
            }
            $totalInventionCost = $cheapestCost < PHP_FLOAT_MAX ? $cheapestCost : 0.0;
        }

        return [
            'isT2' => $isT2,
            'inventions' => $inventions,
            'summary' => [
                'totalInventionCost' => $totalInventionCost,
                'bestDecryptorTypeId' => $bestDecryptorTypeId,
                'totalBpcKitCost' => $totalInventionCost,
            ],
        ];
    }

    /**
     * Calculate all T1 BPC copy costs for a project.
     * Returns copy costs for ALL steps with activityType === 'copy'.
     *
     * @return array{copies: list<array{blueprintTypeId: int, blueprintName: string, productTypeName: string, runs: int, cost: float, depth: int}>, totalCopyCost: float}
     */
    public function getProjectCopyCosts(IndustryProject $project): array
    {
        $solarSystemId = $this->resolveSolarSystemForProject($project);
        $facilityTaxRate = $this->resolveFacilityTaxRate($project);

        $copies = [];
        $totalCopyCost = 0.0;

        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() !== 'copy') {
                continue;
            }

            $blueprintTypeId = $step->getBlueprintTypeId();
            $stepRuns = $step->getRuns();

            $copyCost = $this->getCopyJobCost(
                $blueprintTypeId,
                $stepRuns,
                $solarSystemId,
                $facilityTaxRate,
            );
            $totalCopyCost += $copyCost;

            $blueprintName = $this->resolveTypeName($blueprintTypeId);
            $productTypeName = $this->resolveTypeName($step->getProductTypeId());
            $copies[] = [
                'blueprintTypeId' => $blueprintTypeId,
                'blueprintName' => $blueprintName,
                'productTypeName' => $productTypeName,
                'runs' => $stepRuns,
                'cost' => $copyCost,
                'depth' => $step->getDepth(),
            ];
        }

        return [
            'copies' => $copies,
            'totalCopyCost' => $totalCopyCost,
        ];
    }

    /**
     * Build all decryptor options (including "no decryptor") for a T2 item.
     *
     * @return list<array{decryptorTypeId: ?int, decryptorName: string, me: int, te: int, runs: int, probability: float, costPerAttempt: float, expectedAttempts: int, totalCost: float, costBreakdown: array{datacores: float, decryptor: float, copyCost: float, inventionInstall: float}}>
     */
    public function buildDecryptorOptions(
        int $t2TypeId,
        int $solarSystemId,
        int $desiredSuccesses = 1,
        ?float $facilityTaxRate = null,
    ): array {
        $options = [];

        // "No decryptor" option first
        $noDecryptor = $this->calculateInventionCost(
            $t2TypeId,
            $solarSystemId,
            null,
            $desiredSuccesses,
            $facilityTaxRate,
        );

        if ($noDecryptor !== null) {
            $options[] = [
                'decryptorTypeId' => null,
                'decryptorName' => 'No Decryptor',
                'me' => $noDecryptor['me'],
                'te' => $noDecryptor['te'],
                'runs' => $noDecryptor['runs'],
                'probability' => $noDecryptor['effectiveProbability'],
                'costPerAttempt' => $noDecryptor['costPerAttempt'],
                'expectedAttempts' => $noDecryptor['expectedAttempts'],
                'totalCost' => $noDecryptor['totalCost'],
                'costBreakdown' => $noDecryptor['costBreakdown'],
            ];
        }

        // Each decryptor option
        foreach (self::DECRYPTORS as $decryptorTypeId => $decryptor) {
            $result = $this->calculateInventionCost(
                $t2TypeId,
                $solarSystemId,
                $decryptorTypeId,
                $desiredSuccesses,
                $facilityTaxRate,
            );

            if ($result !== null) {
                $options[] = [
                    'decryptorTypeId' => $decryptorTypeId,
                    'decryptorName' => $decryptor['name'],
                    'me' => $result['me'],
                    'te' => $result['te'],
                    'runs' => $result['runs'],
                    'probability' => $result['effectiveProbability'],
                    'costPerAttempt' => $result['costPerAttempt'],
                    'expectedAttempts' => $result['expectedAttempts'],
                    'totalCost' => $result['totalCost'],
                    'costBreakdown' => $result['costBreakdown'],
                ];
            }
        }

        return $options;
    }

    /**
     * Resolve the solar system for a project from its root step's structure config.
     */
    private function resolveSolarSystemForProject(IndustryProject $project): int
    {
        // Try the root step (depth 0) structure config
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0) {
                $config = $step->getStructureConfig();
                if ($config !== null && $config->getSolarSystemId() !== null) {
                    return $config->getSolarSystemId();
                }
            }
        }

        return EveConstants::PERIMETER_SOLAR_SYSTEM_ID;
    }

    /**
     * Resolve the facility tax rate for a project from its root step's structure config.
     */
    private function resolveFacilityTaxRate(IndustryProject $project): ?float
    {
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0) {
                return $step->getStructureConfig()?->getFacilityTaxRate();
            }
        }

        return null;
    }

    /**
     * Calculate the Estimated Item Value (EIV) for a blueprint.
     * EIV = sum of (adjusted_price x quantity) for each manufacturing output.
     */
    private function calculateBlueprintEiv(int $blueprintTypeId): float
    {
        $products = $this->activityProductRepository->findBy([
            'typeId' => $blueprintTypeId,
            'activityId' => IndustryActivityType::Manufacturing->value,
        ]);

        $eiv = 0.0;
        foreach ($products as $product) {
            $adjustedPrice = $this->esiCostIndexService->getAdjustedPrice($product->getProductTypeId());
            if ($adjustedPrice !== null) {
                $eiv += $adjustedPrice * $product->getQuantity();
            }
        }

        return $eiv;
    }

    private function resolveTypeName(int $typeId): string
    {
        return $this->typeNameResolver->resolve($typeId);
    }
}
