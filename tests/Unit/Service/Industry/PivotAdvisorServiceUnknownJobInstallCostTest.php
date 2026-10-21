<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\InventionService;
use App\Service\Industry\PivotAdvisorService;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PivotAdvisorService::class)]
#[Group('issue-7')]
final class PivotAdvisorServiceUnknownJobInstallCostTest extends TestCase
{
    private const int SOLAR_SYSTEM_ID = 30002510;
    private const float BROKER_FEE_RATE = 0.036;
    private const float SALES_TAX_RATE = 0.036;
    private const int RUNS = 3;
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1000;
    private const int MANUFACTURING_ACTIVITY_ID = 1;
    private const int REACTION_ACTIVITY_ID = 11;

    private const float MANUFACTURING_COST_INDEX = 0.05;
    private const float REACTION_COST_INDEX = 0.08;

    private const int SOURCE_PRODUCT = 11379;
    private const int SOURCE_BLUEPRINT = 11380;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int COMPONENT_TWO = 11399;
    private const int COMPONENT_TWO_BLUEPRINT = 99001;
    private const int UNPRICED_ISOTOPES = 16273;

    private const int CANDIDATE_X = 22456;
    private const int CANDIDATE_W = 22462;
    private const int CANDIDATE_Y = 22470;
    private const int CANDIDATE_V = 22490;
    private const int REACTION_CANDIDATE_R = 22530;

    private const array ADJUSTED_PRICES = [
        self::TRITANIUM => 4.0,
        self::PYERITE => 9.0,
        self::COMPONENT_TWO => 45.0,
        self::UNPRICED_ISOTOPES => 900.0,
    ];

    private const array JITA_PRICES = [
        self::SOURCE_PRODUCT => 4000.0,
        self::TRITANIUM => 5.0,
        self::PYERITE => 11.0,
        self::MEXALLON => 40.0,
        self::COMPONENT_TWO => 50.0,
        self::UNPRICED_ISOTOPES => null,
        self::CANDIDATE_X => 1000.0,
        self::CANDIDATE_W => 1200.0,
        self::CANDIDATE_Y => 1500.0,
        self::CANDIDATE_V => 2500.0,
        self::REACTION_CANDIDATE_R => 3000.0,
    ];

    private const array DAILY_VOLUMES = [
        self::SOURCE_PRODUCT => 25.0,
        self::CANDIDATE_X => 99.0,
        self::CANDIDATE_W => 9.0,
        self::CANDIDATE_Y => 999.0,
        self::CANDIDATE_V => 999.0,
        self::REACTION_CANDIDATE_R => 499.0,
    ];

    private const array TYPE_NAMES = [
        self::SOURCE_PRODUCT => 'Source Ship',
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::MEXALLON => 'Mexallon',
        self::COMPONENT_TWO => 'Component Two',
        self::UNPRICED_ISOTOPES => 'Unpriced Isotopes',
        self::CANDIDATE_X => 'Candidate X',
        self::CANDIDATE_W => 'Candidate W',
        self::CANDIDATE_Y => 'Candidate Y',
        self::CANDIDATE_V => 'Candidate V',
        self::REACTION_CANDIDATE_R => 'Reaction Candidate R',
    ];

    private const array GROUP_NAMES = [
        self::SOURCE_PRODUCT => 'Destroyer',
        self::CANDIDATE_X => 'Cruiser',
        self::CANDIDATE_W => 'Frigate',
        self::CANDIDATE_Y => 'Battleship',
        self::CANDIDATE_V => 'Hauler',
        self::REACTION_CANDIDATE_R => 'Composite',
    ];

    private const array SOURCE_MATERIALS = [
        ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
        ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
    ];

    private const array CANDIDATE_X_MATERIALS = [
        ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
        ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
    ];

    private const array CANDIDATE_W_MATERIALS = [
        ['materialTypeId' => self::TRITANIUM, 'quantity' => 12],
        ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 3],
    ];

    private const array CANDIDATE_X_FIGURED = [
        'typeId' => self::CANDIDATE_X,
        'typeName' => 'Candidate X',
        'groupName' => 'Cruiser',
        'marginPercent' => 262.5,
        'profitPerUnit' => 672.0,
        'dailyVolume' => 99.0,
        'coveragePercent' => 25.0,
        'missingComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 3, 'cost' => 150.0]],
        'additionalCost' => 150.0,
        'estimatedProfit' => 2016.0,
        'score' => 13125.0,
        'unknownReason' => null,
        'missingPriceTypeIds' => [],
    ];

    private const array CANDIDATE_W_FIGURED = [
        'typeId' => self::CANDIDATE_W,
        'typeName' => 'Candidate W',
        'groupName' => 'Frigate',
        'marginPercent' => 420.01,
        'profitPerUnit' => 899.45,
        'dailyVolume' => 9.0,
        'coveragePercent' => 33.33,
        'missingComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 2, 'cost' => 100.0]],
        'additionalCost' => 100.0,
        'estimatedProfit' => 2698.35,
        'score' => 14000.31,
        'unknownReason' => null,
        'missingPriceTypeIds' => [],
    ];

    public function testCandidatesWithAKnownCostIndexAndAdjustedPricesAreFiguredWithTheEstimatedJobInstallCost(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [
                self::CANDIDATE_X => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_X_MATERIALS],
                self::CANDIDATE_W => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_W_MATERIALS],
            ],
            costIndexByActivity: ['manufacturing' => self::MANUFACTURING_COST_INDEX, 'reaction' => self::REACTION_COST_INDEX],
        );

        $this->assertSame([self::CANDIDATE_W_FIGURED, self::CANDIDATE_X_FIGURED], $result['candidates']);
    }

    public function testSourceProductWithAKnownCostIndexAndAdjustedPricesHasAMarginWithTheEstimatedJobInstallCost(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [
                self::CANDIDATE_X => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_X_MATERIALS],
            ],
            costIndexByActivity: ['manufacturing' => self::MANUFACTURING_COST_INDEX, 'reaction' => self::REACTION_COST_INDEX],
        );

        $this->assertSame(
            ['marginPercent' => 810.92, 'unknownReason' => null, 'missingPriceTypeIds' => []],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testCandidatesInASystemWithoutManufacturingCostIndexHaveAnUnknownCostWithTheMissingCostIndexReasonAndAFiguredCoverage(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [
                self::CANDIDATE_X => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_X_MATERIALS],
                self::CANDIDATE_W => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_W_MATERIALS],
            ],
            costIndexByActivity: ['reaction' => self::REACTION_COST_INDEX],
        );

        $this->assertSame([
            [
                ...self::CANDIDATE_X_FIGURED,
                'marginPercent' => null,
                'profitPerUnit' => null,
                'estimatedProfit' => null,
                'score' => null,
                'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX,
            ],
            [
                ...self::CANDIDATE_W_FIGURED,
                'marginPercent' => null,
                'profitPerUnit' => null,
                'estimatedProfit' => null,
                'score' => null,
                'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX,
            ],
        ], $result['candidates']);
    }

    public function testSourceProductInASystemWithoutManufacturingCostIndexHasAnUnknownMarginWithTheMissingCostIndexReason(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [
                self::CANDIDATE_X => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_X_MATERIALS],
            ],
            costIndexByActivity: ['reaction' => self::REACTION_COST_INDEX],
        );

        $this->assertSame([
            'typeId' => self::SOURCE_PRODUCT,
            'typeName' => 'Source Ship',
            'groupName' => 'Destroyer',
            'marginPercent' => null,
            'sellPrice' => 4000.0,
            'dailyVolume' => 25.0,
            'keyComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'inStock' => 1]],
            'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX,
            'missingPriceTypeIds' => [],
        ], $result['sourceProduct']);
    }

    public function testCandidateWithAMaterialWithoutAdjustedPriceHasAnUnknownCostWithTheMissingAdjustedPriceReasonAndNoMissingMarketPrice(): void
    {
        $result = $this->analyzeCandidatesXWAndVWhoseMexallonHasNoAdjustedPrice();

        $this->assertSame([
            'typeId' => self::CANDIDATE_V,
            'typeName' => 'Candidate V',
            'groupName' => 'Hauler',
            'marginPercent' => null,
            'profitPerUnit' => null,
            'dailyVolume' => 999.0,
            'coveragePercent' => 25.0,
            'missingComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 3, 'cost' => 150.0]],
            'additionalCost' => 150.0,
            'estimatedProfit' => null,
            'score' => null,
            'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_ADJUSTED_PRICE,
            'missingPriceTypeIds' => [],
        ], $this->candidateOf(self::CANDIDATE_V, $result));
    }

    public function testCandidateWithAMaterialWithoutAdjustedPriceIsRankedAfterTheFiguredCandidatesItWouldLeadAtAZeroJobInstallCost(): void
    {
        $result = $this->analyzeCandidatesXWAndVWhoseMexallonHasNoAdjustedPrice();

        $this->assertSame([self::CANDIDATE_W, self::CANDIDATE_X, self::CANDIDATE_V], array_column($result['candidates'], 'typeId'));
        $this->assertSame([14000.31, 13125.0, null], array_column($result['candidates'], 'score'));
        $this->assertSame(
            ['marginPercent' => 810.92, 'unknownReason' => null],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'unknownReason'),
        );
    }

    public function testMissingMaterialPriceReasonTakesPriorityOverAMissingCostIndex(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [...self::SOURCE_MATERIALS, ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 1]],
            candidates: [
                self::CANDIDATE_Y => [self::MANUFACTURING_ACTIVITY_ID, [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 2],
                    ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 2],
                ]],
            ],
            costIndexByActivity: ['reaction' => self::REACTION_COST_INDEX],
        );

        $this->assertSame(
            [
                'marginPercent' => null,
                'score' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::UNPRICED_ISOTOPES],
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_Y, $result), 'marginPercent', 'score', 'unknownReason', 'missingPriceTypeIds'),
        );
        $this->assertSame(
            [
                'marginPercent' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::UNPRICED_ISOTOPES],
            ],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testReactionCandidateWithoutReactionCostIndexHasAnUnknownCostWhileManufacturingCandidatesAreFigured(): void
    {
        $result = $this->analyzeManufacturingCandidatesAndAReactionCandidateWithoutReactionCostIndex();

        $this->assertSame([
            self::CANDIDATE_W_FIGURED,
            self::CANDIDATE_X_FIGURED,
            [
                'typeId' => self::REACTION_CANDIDATE_R,
                'typeName' => 'Reaction Candidate R',
                'groupName' => 'Composite',
                'marginPercent' => null,
                'profitPerUnit' => null,
                'dailyVolume' => 499.0,
                'coveragePercent' => 50.0,
                'missingComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 1, 'cost' => 50.0]],
                'additionalCost' => 50.0,
                'estimatedProfit' => null,
                'score' => null,
                'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX,
                'missingPriceTypeIds' => [],
            ],
        ], $result['candidates']);
    }

    public function testSourceProductOfManufacturingStaysFiguredWhenOnlyTheReactionCostIndexIsMissing(): void
    {
        $result = $this->analyzeManufacturingCandidatesAndAReactionCandidateWithoutReactionCostIndex();

        $this->assertSame(
            ['marginPercent' => 810.92, 'unknownReason' => null],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'unknownReason'),
        );
    }

    public function testSourceProductWithoutBuildableMaterialHasAMarginWithTheEstimatedJobInstallCost(): void
    {
        $result = $this->analyzeSourceProductBuiltFromRawMaterialsOnly(
            ['manufacturing' => self::MANUFACTURING_COST_INDEX, 'reaction' => self::REACTION_COST_INDEX],
        );

        $this->assertSame(
            ['marginPercent' => 250.0, 'unknownReason' => null, 'missingPriceTypeIds' => []],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
        $this->assertSame([], $result['candidates']);
    }

    public function testSourceProductWithoutBuildableMaterialInASystemWithoutManufacturingCostIndexHasAnUnknownMarginWithTheMissingCostIndexReason(): void
    {
        $result = $this->analyzeSourceProductBuiltFromRawMaterialsOnly(['reaction' => self::REACTION_COST_INDEX]);

        $this->assertSame(
            ['marginPercent' => null, 'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX, 'missingPriceTypeIds' => []],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    private function analyzeCandidatesXWAndVWhoseMexallonHasNoAdjustedPrice(): array
    {
        return $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [
                self::CANDIDATE_V => [self::MANUFACTURING_ACTIVITY_ID, [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                    ['materialTypeId' => self::MEXALLON, 'quantity' => 5],
                ]],
                self::CANDIDATE_X => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_X_MATERIALS],
                self::CANDIDATE_W => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_W_MATERIALS],
            ],
            costIndexByActivity: ['manufacturing' => self::MANUFACTURING_COST_INDEX, 'reaction' => self::REACTION_COST_INDEX],
        );
    }

    private function analyzeManufacturingCandidatesAndAReactionCandidateWithoutReactionCostIndex(): array
    {
        return $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [
                self::REACTION_CANDIDATE_R => [self::REACTION_ACTIVITY_ID, [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 2],
                ]],
                self::CANDIDATE_X => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_X_MATERIALS],
                self::CANDIDATE_W => [self::MANUFACTURING_ACTIVITY_ID, self::CANDIDATE_W_MATERIALS],
            ],
            costIndexByActivity: ['manufacturing' => self::MANUFACTURING_COST_INDEX],
        );
    }

    private function analyzeSourceProductBuiltFromRawMaterialsOnly(array $costIndexByActivity): array
    {
        return $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
                ['materialTypeId' => self::PYERITE, 'quantity' => 8],
            ],
            candidates: [],
            costIndexByActivity: $costIndexByActivity,
            jitaPrices: [self::SOURCE_PRODUCT => 700.0] + self::JITA_PRICES,
        );
    }

    private function analyzePivot(
        array $sourceMaterials,
        array $candidates,
        array $costIndexByActivity,
        array $jitaPrices = self::JITA_PRICES,
    ): array {
        $sourceBlueprint = $this->blueprintProducing(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT);
        $componentTwoBlueprint = $this->blueprintProducing(self::COMPONENT_TWO_BLUEPRINT, self::COMPONENT_TWO);

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                $activityId !== self::MANUFACTURING_ACTIVITY_ID => null,
                $typeId === self::SOURCE_PRODUCT => $sourceBlueprint,
                $typeId === self::COMPONENT_TWO => $componentTwoBlueprint,
                default => null,
            },
        );

        $candidateMaterialsByBlueprint = [];
        $candidateProducts = [];
        foreach ($candidates as $candidateTypeId => [$activityId, $materials]) {
            $candidateMaterialsByBlueprint[$candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET] = $materials;
            $candidateProducts[] = ['blueprintTypeId' => $candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET, 'productTypeId' => $candidateTypeId, 'outputPerRun' => 1, 'activityId' => $activityId];
        }

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds): array => $blueprintTypeIds === [self::SOURCE_BLUEPRINT]
                ? [self::SOURCE_BLUEPRINT => $sourceMaterials]
                : $candidateMaterialsByBlueprint,
        );
        $materialRepository->method('findProductsUsingMaterials')->willReturn($candidateProducts);

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([self::COMPONENT_TWO => 1]);

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturn($jitaPrices);
        $jitaMarketService->method('getCachedDailyVolumes')->willReturn(self::DAILY_VOLUMES);

        $esiCostIndexService = $this->createStub(EsiCostIndexService::class);
        $esiCostIndexService->method('estimateJobInstallCost')->willReturnCallback(
            static fn (array $materials, int $runs, int $solarSystemId, string $activity, ?float $facilityTaxRate = null): array => self::estimatedJobInstallCost(
                $materials,
                $runs,
                $solarSystemId === self::SOLAR_SYSTEM_ID ? ($costIndexByActivity[$activity] ?? null) : null,
            ),
        );

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturn(self::TYPE_NAMES);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn(array_map(
            static fn (int $typeId, string $groupName): array => ['type_id' => $typeId, 'group_name' => $groupName],
            array_keys(self::GROUP_NAMES),
            self::GROUP_NAMES,
        ));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('identifyT2Products')->willReturn([]);

        $service = new PivotAdvisorService(
            $materialRepository,
            $productRepository,
            $assetRepository,
            $jitaMarketService,
            $esiCostIndexService,
            $typeNameResolver,
            $entityManager,
            $inventionService,
        );

        return $service->analyze(
            self::SOURCE_PRODUCT,
            self::RUNS,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE_RATE,
            self::SALES_TAX_RATE,
            $this->createStub(User::class),
        );
    }

    private static function estimatedJobInstallCost(array $materials, int $runs, ?float $costIndex): array
    {
        $missingAdjustedPriceTypeIds = array_values(array_unique(array_diff(
            array_column($materials, 'materialTypeId'),
            array_keys(self::ADJUSTED_PRICES),
        )));
        sort($missingAdjustedPriceTypeIds);

        if ($costIndex === null) {
            return ['cost' => null, 'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX, 'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds];
        }

        if ($missingAdjustedPriceTypeIds !== []) {
            return ['cost' => null, 'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_ADJUSTED_PRICE, 'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds];
        }

        $estimatedItemValue = 0.0;
        foreach ($materials as $material) {
            $estimatedItemValue += self::ADJUSTED_PRICES[$material['materialTypeId']] * $material['quantity'];
        }

        return ['cost' => $estimatedItemValue * $runs * $costIndex, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []];
    }

    private function blueprintProducing(int $blueprintTypeId, int $productTypeId): IndustryActivityProduct
    {
        $blueprint = new IndustryActivityProduct();
        $blueprint->setTypeId($blueprintTypeId);
        $blueprint->setProductTypeId($productTypeId);
        $blueprint->setQuantity(1);
        $blueprint->setActivityId(self::MANUFACTURING_ACTIVITY_ID);

        return $blueprint;
    }

    private function onlyKeys(array $values, string ...$keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }

    private function candidateOf(int $typeId, array $result): array
    {
        foreach ($result['candidates'] as $candidate) {
            if ($candidate['typeId'] === $typeId) {
                return $candidate;
            }
        }

        $this->fail("Candidate {$typeId} is missing from the pivot analysis");
    }
}
