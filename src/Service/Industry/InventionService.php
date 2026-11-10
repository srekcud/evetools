<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Enum\IndustryActivityType;
use App\Industry\Application\ProductionStructure;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;

/**
 * Calculates BPC copy costs, invention costs, and decryptor comparisons
 * for T2 manufacturing projects.
 */
class InventionService
{
    // Base invention output (no decryptor)
    public const BASE_INVENTION_ME = 2;
    public const BASE_INVENTION_TE = 4;

    public const UNKNOWN_REASON_MISSING_PROBABILITY = 'missing_invention_probability';
    public const UNKNOWN_REASON_MISSING_MARKET_PRICE = 'missing_market_price';

    public const NO_DECRYPTOR_NAME = 'No Decryptor';

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
        34204 => ['name' => 'Parity Decryptor', 'probabilityMultiplier' => 1.5, 'meModifier' => 1, 'teModifier' => -2, 'runModifier' => 3],
        34205 => ['name' => 'Process Decryptor', 'probabilityMultiplier' => 1.1, 'meModifier' => 3, 'teModifier' => 6, 'runModifier' => 0],
        34206 => ['name' => 'Symmetry Decryptor', 'probabilityMultiplier' => 1.0, 'meModifier' => 1, 'teModifier' => 8, 'runModifier' => 2],
        34207 => ['name' => 'Optimized Attainment Decryptor', 'probabilityMultiplier' => 1.9, 'meModifier' => 1, 'teModifier' => -2, 'runModifier' => 2],
        34208 => ['name' => 'Optimized Augmentation Decryptor', 'probabilityMultiplier' => 0.9, 'meModifier' => 2, 'teModifier' => 0, 'runModifier' => 7],
    ];

    public function __construct(
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryActivityMaterialRepository $activityMaterialRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
    ) {
    }

    /**
     * Job install cost for copying a blueprint, on the manufacturing materials of the blueprint (spec R6).
     *
     * @return array{cost: ?float, unknownReason: ?string, missingAdjustedPriceTypeIds: list<int>}
     */
    public function estimateCopyJobCost(
        int $blueprintTypeId,
        int $runs,
        int $solarSystemId,
        ?float $facilityTaxPercent = null,
        ?ProductionStructure $structure = null,
    ): array {
        return $this->esiCostIndexService->estimateJobInstallCost(
            $this->activityMaterialRepository->findMaterialsForBlueprints([$blueprintTypeId], [IndustryActivityType::Manufacturing->value])[$blueprintTypeId] ?? [],
            $runs,
            $solarSystemId,
            'copying',
            $facilityTaxPercent,
            $structure,
        );
    }

    /**
     * Check if a product type is T2 (one of its manufacturing blueprints is produced via invention).
     */
    public function isT2(int $productTypeId): bool
    {
        return isset($this->activityProductRepository->findT2ProductTypeIds([$productTypeId])[$productTypeId]);
    }

    /**
     * Identify T2 products from a batch list: manufactured products with an invented manufacturing blueprint.
     * Reaction candidates are never T2.
     *
     * @param list<array{blueprintTypeId: int, productTypeId: int, outputPerRun: int, activityId: int}> $products
     * @return array<int, true> Set of T2 product type IDs
     */
    public function identifyT2Products(array $products): array
    {
        $manufacturedProductTypeIds = [];
        foreach ($products as $product) {
            if ($product['activityId'] === IndustryActivityType::Manufacturing->value) {
                $manufacturedProductTypeIds[] = $product['productTypeId'];
            }
        }

        return $this->activityProductRepository->findT2ProductTypeIds($manufacturedProductTypeIds);
    }

    /**
     * Find the invention chain for a T2 item: which T1 blueprint invents into it.
     *
     * Flow:
     * 1. Find the T2 blueprint that manufactures this T2 item
     * 2. Find the T1 blueprint that invents into that T2 blueprint
     * 3. Get invention materials and probability
     *
     * The probability is null when the SDE does not carry it: the invention chain still exists,
     * but its success rate (and therefore its cost) is unknown.
     *
     * @return array{t1BlueprintTypeId: int, t2BlueprintTypeId: int, probability: ?float, baseRuns: int, materials: list<array{typeId: int, typeName: string|null, quantity: int}>}|null
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
        $baseProbability = $inventionProduct->getProbability();
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
                'typeName' => ($typeNames[$matTypeId] ?? null)?->getTypeName(),
                'quantity' => $material->getQuantity(),
            ];
        }

        return [
            't1BlueprintTypeId' => $t1BlueprintTypeId,
            't2BlueprintTypeId' => $t2BlueprintTypeId,
            'probability' => $baseProbability,
            'baseRuns' => $baseRuns,
            'materials' => $materials,
        ];
    }

    /**
     * Calculate the full invention cost for producing T2 BPCs.
     *
     * When the SDE carries no success probability, the probability-derived fields
     * (probabilities, expected attempts, total cost) are null and unknownReason says why.
     *
     * @return array{
     *     baseProbability: ?float,
     *     effectiveProbability: ?float,
     *     expectedAttempts: ?int,
     *     me: int,
     *     te: int,
     *     runs: int,
     *     costPerAttempt: ?float,
     *     totalCost: ?float,
     *     costBreakdown: array{datacores: ?float, decryptor: ?float, copyCost: ?float, inventionInstall: ?float},
     *     datacores: list<array{typeId: int, typeName: string|null, quantity: int, unitPrice: ?float, totalPrice: ?float}>,
     *     decryptorName: ?string,
     *     missingPriceTypeIds: list<int>,
     *     missingAdjustedPriceTypeIds: list<int>,
     *     unknownReason: ?string
     * }|null
     */
    public function calculateInventionCost(
        int $t2TypeId,
        int $solarSystemId,
        ?int $decryptorTypeId = null,
        int $desiredSuccesses = 1,
        ?float $facilityTaxPercent = null,
        ?ProductionStructure $structure = null,
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
        $pricedTypeIds = array_map(fn (array $m) => $m['typeId'], $inventionData['materials']);
        $isDecryptorUsed = $decryptorTypeId !== null && isset(self::DECRYPTORS[$decryptorTypeId]);

        if ($isDecryptorUsed) {
            $decryptor = self::DECRYPTORS[$decryptorTypeId];
            $probabilityMultiplier = $decryptor['probabilityMultiplier'];
            $meModifier = $decryptor['meModifier'];
            $teModifier = $decryptor['teModifier'];
            $runModifier = $decryptor['runModifier'];
            $decryptorName = $decryptor['name'];
            $pricedTypeIds[] = $decryptorTypeId;
        }

        $me = self::BASE_INVENTION_ME + $meModifier;
        $te = self::BASE_INVENTION_TE + $teModifier;
        $runs = $baseRuns + $runModifier;

        // Cost per attempt: datacores + decryptor + T1 BPC copy cost + invention install
        $marketPrices = $this->jitaMarketService->getPricesWithFallback($pricedTypeIds);
        $missingPriceTypeIds = [];

        $decryptorPrice = 0.0;
        if ($isDecryptorUsed) {
            $decryptorPrice = $marketPrices[$decryptorTypeId] ?? null;
            if ($decryptorPrice === null) {
                $missingPriceTypeIds[] = $decryptorTypeId;
            }
        }

        $datacores = [];
        $datacoreTotalCost = 0.0;
        foreach ($inventionData['materials'] as $material) {
            $unitPrice = $marketPrices[$material['typeId']] ?? null;
            $totalPrice = null;
            if ($unitPrice === null) {
                $missingPriceTypeIds[] = $material['typeId'];
            } else {
                $totalPrice = $unitPrice * $material['quantity'];
                $datacoreTotalCost += $totalPrice;
            }
            $datacores[] = [
                'typeId' => $material['typeId'],
                'typeName' => $material['typeName'],
                'quantity' => $material['quantity'],
                'unitPrice' => $unitPrice,
                'totalPrice' => $totalPrice,
            ];
        }

        $copyEstimate = $this->estimateCopyJobCost($t1BlueprintTypeId, 1, $solarSystemId, $facilityTaxPercent, $structure);
        $copyCost = $copyEstimate['cost'];

        $inventionInstallEstimate = $this->esiCostIndexService->estimateJobInstallCost(
            $this->activityMaterialRepository->findMaterialsForBlueprints([$t2BlueprintTypeId], [IndustryActivityType::Manufacturing->value])[$t2BlueprintTypeId] ?? [],
            1,
            $solarSystemId,
            'invention',
            $facilityTaxPercent,
            $structure,
        );
        $inventionInstallCost = $inventionInstallEstimate['cost'];

        $isDatacoreCostUnknown = in_array(null, array_column($datacores, 'unitPrice'), true);
        $datacoreCost = $isDatacoreCostUnknown ? null : $datacoreTotalCost;
        $costPerAttempt = $missingPriceTypeIds === [] && $inventionInstallCost !== null && $copyCost !== null
            ? $datacoreTotalCost + $decryptorPrice + $copyCost + $inventionInstallCost
            : null;

        $effectiveProbability = null;
        $expectedAttempts = null;
        $totalCost = null;
        if ($baseProbability !== null) {
            $effectiveProbability = $baseProbability * $probabilityMultiplier;
            $expectedAttempts = (int) ceil($desiredSuccesses / $effectiveProbability);
            $totalCost = $costPerAttempt === null ? null : $expectedAttempts * $costPerAttempt;
        }

        $missingPriceTypeIds = array_unique($missingPriceTypeIds);
        sort($missingPriceTypeIds);
        $missingAdjustedPriceTypeIds = array_values(array_unique([...$inventionInstallEstimate['missingAdjustedPriceTypeIds'], ...$copyEstimate['missingAdjustedPriceTypeIds']]));
        sort($missingAdjustedPriceTypeIds);

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
                'datacores' => $datacoreCost,
                'decryptor' => $decryptorPrice,
                'copyCost' => $copyCost,
                'inventionInstall' => $inventionInstallCost,
            ],
            'datacores' => $datacores,
            'decryptorName' => $decryptorName,
            'missingPriceTypeIds' => $missingPriceTypeIds,
            'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds,
            'unknownReason' => match (true) {
                $baseProbability === null => self::UNKNOWN_REASON_MISSING_PROBABILITY,
                $missingPriceTypeIds !== [] => self::UNKNOWN_REASON_MISSING_MARKET_PRICE,
                default => $inventionInstallEstimate['unknownReason'] ?? $copyEstimate['unknownReason'],
            },
        ];
    }

    /**
     * @return array{cost: ?float, unknownReason: ?string}
     */
    public function expectedCostPerRun(int $t2TypeId, int $solarSystemId, ?float $facilityTaxPercent = null, ?ProductionStructure $structure = null): array
    {
        $invention = $this->calculateInventionCost($t2TypeId, $solarSystemId, null, 1, $facilityTaxPercent, $structure);
        if ($invention === null) {
            return ['cost' => null, 'unknownReason' => self::UNKNOWN_REASON_MISSING_PROBABILITY];
        }

        if ($invention['costPerAttempt'] === null || $invention['effectiveProbability'] === null) {
            return ['cost' => null, 'unknownReason' => $invention['unknownReason']];
        }

        return ['cost' => $invention['costPerAttempt'] / ($invention['effectiveProbability'] * $invention['runs']), 'unknownReason' => null];
    }
}
