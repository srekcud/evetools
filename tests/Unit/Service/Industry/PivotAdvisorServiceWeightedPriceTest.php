<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\FacilityTaxResolver;
use App\Service\Industry\InventionService;
use App\Service\Industry\PivotAdvisorService;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Service\Industry\Double\SellQuoteCallbacks;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PivotAdvisorService::class)]
#[Group('issue-538')]
final class PivotAdvisorServiceWeightedPriceTest extends TestCase
{
    use CreatesUserWithId;

    private const int SOLAR_SYSTEM_ID = 30002510;
    private const float BROKER_FEE_RATE = 0.036;
    private const float SALES_TAX_RATE = 0.036;
    private const float JOB_INSTALL_COST_PER_RUN = 100.0;
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1000;
    private const int MANUFACTURING_ACTIVITY_ID = 1;
    private const int REACTION_ACTIVITY_ID = 11;
    private const int MANUFACTURING_MATERIAL_EFFICIENCY = 10;
    private const int REACTION_MATERIAL_EFFICIENCY = 0;

    private const int SOURCE_PRODUCT = 11379;
    private const int SOURCE_BLUEPRINT = 11380;
    private const int COMPONENT_M = 11399;
    private const int COMPONENT_M_BLUEPRINT = 99001;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;

    private const int CANDIDATE_WEIGHTED = 22456;
    private const int CANDIDATE_PARTIAL_MATERIAL = 22458;
    private const int CANDIDATE_PARTIAL_SALE = 22460;
    private const int CANDIDATE_WITHOUT_SALE_PRICE = 22462;
    private const int CANDIDATE_WITHOUT_MATERIAL_PRICE = 22464;
    private const int CANDIDATE_REACTION = 22466;

    private const array TYPE_NAMES = [
        self::SOURCE_PRODUCT => 'Source Ship',
        self::COMPONENT_M => 'Component M',
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::CANDIDATE_WEIGHTED => 'Candidate Weighted',
        self::CANDIDATE_PARTIAL_MATERIAL => 'Candidate Partial Material',
        self::CANDIDATE_PARTIAL_SALE => 'Candidate Partial Sale',
        self::CANDIDATE_WITHOUT_SALE_PRICE => 'Candidate Without Sale Price',
        self::CANDIDATE_WITHOUT_MATERIAL_PRICE => 'Candidate Without Material Price',
        self::CANDIDATE_REACTION => 'Candidate Reaction',
    ];

    private const array DAILY_VOLUMES = [
        self::SOURCE_PRODUCT => 25.0,
        self::CANDIDATE_WEIGHTED => 40.0,
        self::CANDIDATE_PARTIAL_MATERIAL => 15.0,
        self::CANDIDATE_PARTIAL_SALE => 60.0,
        self::CANDIDATE_WITHOUT_SALE_PRICE => 70.0,
        self::CANDIDATE_WITHOUT_MATERIAL_PRICE => 80.0,
        self::CANDIDATE_REACTION => 90.0,
    ];

    private const array COMPONENT_M_SELL_BOOK = [[5.0, 100], [6.0, 100], [7.0, 100]];

    private const array SELL_BOOKS = [
        self::COMPONENT_M => self::COMPONENT_M_SELL_BOOK,
        self::TRITANIUM => [[2.0, 100000]],
        self::SOURCE_PRODUCT => [[3000.0, 5]],
        self::CANDIDATE_WEIGHTED => [[10.0, 100], [12.0, 100]],
        self::CANDIDATE_PARTIAL_MATERIAL => [[10000.0, 5]],
        self::CANDIDATE_PARTIAL_SALE => [[20.0, 100], [24.0, 100]],
        self::CANDIDATE_WITHOUT_MATERIAL_PRICE => [[800.0, 5]],
        self::CANDIDATE_REACTION => [[500.0, 5]],
    ];

    private const array SOURCE_MATERIALS = [
        ['materialTypeId' => self::COMPONENT_M, 'quantity' => 222],
        ['materialTypeId' => self::TRITANIUM, 'quantity' => 100],
    ];

    private const array CANDIDATES = [
        self::CANDIDATE_WEIGHTED => [
            'outputPerRun' => 150,
            'activityId' => self::MANUFACTURING_ACTIVITY_ID,
            'materials' => [['materialTypeId' => self::COMPONENT_M, 'quantity' => 166]],
        ],
        self::CANDIDATE_PARTIAL_MATERIAL => [
            'outputPerRun' => 1,
            'activityId' => self::MANUFACTURING_ACTIVITY_ID,
            'materials' => [['materialTypeId' => self::COMPONENT_M, 'quantity' => 444]],
        ],
        self::CANDIDATE_PARTIAL_SALE => [
            'outputPerRun' => 300,
            'activityId' => self::MANUFACTURING_ACTIVITY_ID,
            'materials' => [['materialTypeId' => self::COMPONENT_M, 'quantity' => 10]],
        ],
        self::CANDIDATE_WITHOUT_SALE_PRICE => [
            'outputPerRun' => 1,
            'activityId' => self::MANUFACTURING_ACTIVITY_ID,
            'materials' => [['materialTypeId' => self::COMPONENT_M, 'quantity' => 10]],
        ],
        self::CANDIDATE_WITHOUT_MATERIAL_PRICE => [
            'outputPerRun' => 1,
            'activityId' => self::MANUFACTURING_ACTIVITY_ID,
            'materials' => [
                ['materialTypeId' => self::COMPONENT_M, 'quantity' => 10],
                ['materialTypeId' => self::PYERITE, 'quantity' => 50],
            ],
        ],
    ];

    public function testCandidateMaterialCostAndSalePriceAreWeightedOnTheQuantitiesOfOneRun(): void
    {
        $result = $this->analyzeMainScenario();

        $this->assertSame(
            ['marginPercent' => 64.98, 'profitPerUnit' => 3.9, 'estimatedProfit' => 584.8],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_WEIGHTED, $result), 'marginPercent', 'profitPerUnit', 'estimatedProfit'),
        );
    }

    public function testCandidateMissingQuantityIsPricedOnTheMissingQuantityItself(): void
    {
        $result = $this->analyzeMainScenario();

        $this->assertSame(
            [
                'coveragePercent' => 0.0,
                'missingComponents' => [['typeId' => self::COMPONENT_M, 'typeName' => 'Component M', 'quantity' => 150, 'cost' => 800.0]],
                'additionalCost' => 800.0,
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_WEIGHTED, $result), 'coveragePercent', 'missingComponents', 'additionalCost'),
        );
    }

    public function testSameMaterialAtTwoQuantitiesGetsTwoDistinctPricesFromASingleOrderBookRead(): void
    {
        $result = $this->analyzeMainScenario(bestOrderReadAllowed: false);

        $this->assertSame(
            [self::CANDIDATE_WEIGHTED => 800.0, self::CANDIDATE_PARTIAL_MATERIAL => 2400.0],
            [
                self::CANDIDATE_WEIGHTED => $this->candidateOf(self::CANDIDATE_WEIGHTED, $result)['additionalCost'],
                self::CANDIDATE_PARTIAL_MATERIAL => $this->candidateOf(self::CANDIDATE_PARTIAL_MATERIAL, $result)['additionalCost'],
            ],
        );
    }

    public function testPartialMaterialPriceKeepsTheCandidateCostsAndMarksTheLowestCoverage(): void
    {
        $result = $this->analyzeMainScenario();

        $candidate = $this->candidateOf(self::CANDIDATE_PARTIAL_MATERIAL, $result);
        $this->assertSame(
            [
                'marginPercent' => 271.2,
                'profitPerUnit' => 6780.0,
                'missingComponents' => [['typeId' => self::COMPONENT_M, 'typeName' => 'Component M', 'quantity' => 400, 'cost' => 2400.0]],
                'additionalCost' => 2400.0,
                'estimatedProfit' => 6780.0,
                'unknownReason' => null,
                'missingPriceTypeIds' => [],
            ],
            $this->onlyKeys($candidate, 'marginPercent', 'profitPerUnit', 'estimatedProfit', 'missingComponents', 'additionalCost', 'unknownReason', 'missingPriceTypeIds'),
        );
        $this->assertSame(75.0, $this->priceSourcesOf($candidate)['materials']['coveragePercent']);
    }

    public function testPartialSalePriceKeepsTheCandidateMarginAndMarksTheCoverageOfTheSale(): void
    {
        $result = $this->analyzeMainScenario();

        $candidate = $this->candidateOf(self::CANDIDATE_PARTIAL_SALE, $result);
        $this->assertSame(
            ['marginPercent' => 4124.0, 'profitPerUnit' => 19.93, 'estimatedProfit' => 5979.8],
            $this->onlyKeys($candidate, 'marginPercent', 'profitPerUnit', 'estimatedProfit'),
        );
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 66.7], $this->priceSourcesOf($candidate)['sale']);
    }

    public function testCandidateWithoutAnyOrderBookForItsProductIsDiscarded(): void
    {
        $result = $this->analyzeMainScenario();

        $this->assertEqualsCanonicalizing(
            [self::CANDIDATE_WEIGHTED, self::CANDIDATE_PARTIAL_MATERIAL, self::CANDIDATE_PARTIAL_SALE, self::CANDIDATE_WITHOUT_MATERIAL_PRICE],
            array_column($result['candidates'], 'typeId'),
        );
    }

    public function testMaterialWithoutAnyOrderBookLeavesTheCandidateCostUnknownWithTheMissingMaterialPriceReason(): void
    {
        $result = $this->analyzeMainScenario();

        $this->assertSame(
            [
                'marginPercent' => null,
                'profitPerUnit' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::PYERITE],
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_WITHOUT_MATERIAL_PRICE, $result), 'marginPercent', 'profitPerUnit', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testSourceProductIsPricedOnItsOwnWeightedQuantities(): void
    {
        $result = $this->analyzeMainScenario();

        $this->assertSame(
            ['marginPercent' => 101.74, 'sellPrice' => 3000.0, 'unknownReason' => null, 'missingPriceTypeIds' => []],
            $this->onlyKeys($result['sourceProduct'], 'sellPrice', 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testPriceSourcesOfACandidateAndOfTheSourceProductLabelTheMaterialsTheSaleAndTheOneRunAssumptions(): void
    {
        $result = $this->analyzeMainScenario();

        $this->assertSame(
            [
                'materials' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
                'sale' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
                'assumptions' => ['runs' => 1, 'materialEfficiency' => self::MANUFACTURING_MATERIAL_EFFICIENCY],
            ],
            $this->priceSourcesOf($this->candidateOf(self::CANDIDATE_WEIGHTED, $result)),
        );
        $this->assertSame(
            [
                'materials' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
                'sale' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
                'assumptions' => ['runs' => 1, 'materialEfficiency' => self::MANUFACTURING_MATERIAL_EFFICIENCY],
            ],
            $this->priceSourcesOf($result['sourceProduct']),
        );
    }

    public function testSourceProductWithoutAnyOrderBookHasAnUnknownSalePriceMarginAndSource(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: self::CANDIDATES,
            sellBooks: array_diff_key(self::SELL_BOOKS, [self::SOURCE_PRODUCT => true]),
        );

        $this->assertArrayHasKey('sellPrice', $result['sourceProduct']);
        $this->assertNull($result['sourceProduct']['sellPrice']);
        $this->assertArrayHasKey('marginPercent', $result['sourceProduct']);
        $this->assertNull($result['sourceProduct']['marginPercent']);
        $this->assertArrayHasKey('sale', $this->priceSourcesOf($result['sourceProduct']));
        $this->assertNull($this->priceSourcesOf($result['sourceProduct'])['sale']);
    }

    public function testStockNeedIsPricedOnTheNeededQuantityAndTheMissingQuantityOnTheMissingOne(): void
    {
        $result = $this->analyzeReactionCandidateNeedingThreeHundredComponents(SellQuoteCallbacks::weighedFrom(self::SELL_BOOKS));

        $this->assertSame(
            [
                'coveragePercent' => 33.33,
                'missingComponents' => [['typeId' => self::COMPONENT_M, 'typeName' => 'Component M', 'quantity' => 200, 'cost' => 1100.0]],
                'additionalCost' => 1100.0,
                'unknownReason' => null,
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_REACTION, $result), 'coveragePercent', 'missingComponents', 'additionalCost', 'unknownReason'),
        );
        $this->assertSame(
            ['runs' => 1, 'materialEfficiency' => self::REACTION_MATERIAL_EFFICIENCY],
            $this->priceSourcesOf($this->candidateOf(self::CANDIDATE_REACTION, $result))['assumptions'],
        );
    }

    public function testMissingQuantityWithoutAnyQuoteLeavesItsCostUnknownWhileTheNeedStaysPriced(): void
    {
        $result = $this->analyzeReactionCandidateNeedingThreeHundredComponents(
            SellQuoteCallbacks::unavailableFor(SellQuoteCallbacks::weighedFrom(self::SELL_BOOKS), self::COMPONENT_M, 200),
        );

        $candidate = $this->candidateOf(self::CANDIDATE_REACTION, $result);
        $this->assertSame(33.33, $candidate['coveragePercent']);
        $this->assertSame(
            [['typeId' => self::COMPONENT_M, 'typeName' => 'Component M', 'quantity' => 200, 'cost' => null]],
            $candidate['missingComponents'],
        );
    }

    public function testComponentWithoutAnyOrderBookLeavesTheCoverageTheAdditionalCostAndTheMissingCostUnknown(): void
    {
        $result = $this->analyzeReactionCandidateNeedingThreeHundredComponents(
            SellQuoteCallbacks::weighedFrom(array_diff_key(self::SELL_BOOKS, [self::COMPONENT_M => true])),
        );

        $this->assertSame(
            [
                'marginPercent' => null,
                'coveragePercent' => null,
                'missingComponents' => [['typeId' => self::COMPONENT_M, 'typeName' => 'Component M', 'quantity' => 200, 'cost' => null]],
                'additionalCost' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::COMPONENT_M],
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_REACTION, $result), 'marginPercent', 'coveragePercent', 'missingComponents', 'additionalCost', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testSourceWithoutBuildableMaterialIsPricedFromASingleOrderBookRead(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [['materialTypeId' => self::TRITANIUM, 'quantity' => 100]],
            candidates: [],
            sellBooks: [self::TRITANIUM => [[2.0, 100000]], self::SOURCE_PRODUCT => [[10.0, 100], [12.0, 100]]],
            sourceOutputPerRun: 150,
            bestOrderReadAllowed: false,
        );

        $this->assertSame(
            ['marginPercent' => 430.29, 'sellPrice' => 10.67, 'unknownReason' => null],
            $this->onlyKeys($result['sourceProduct'], 'sellPrice', 'marginPercent', 'unknownReason'),
        );
        $this->assertSame(
            [
                'materials' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
                'sale' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null],
                'assumptions' => ['runs' => 1, 'materialEfficiency' => self::MANUFACTURING_MATERIAL_EFFICIENCY],
            ],
            $this->priceSourcesOf($result['sourceProduct']),
        );
    }

    public function testSourceWithoutCandidateKeepsAPartialMaterialPriceFromASingleOrderBookRead(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [['materialTypeId' => self::COMPONENT_M, 'quantity' => 444]],
            candidates: [],
            sellBooks: [self::COMPONENT_M => self::COMPONENT_M_SELL_BOOK, self::SOURCE_PRODUCT => [[10.0, 100], [12.0, 100]]],
            sourceOutputPerRun: 150,
            bestOrderReadAllowed: false,
        );

        $this->assertSame(
            ['marginPercent' => -40.61, 'sellPrice' => 10.67, 'unknownReason' => null, 'missingPriceTypeIds' => []],
            $this->onlyKeys($result['sourceProduct'], 'sellPrice', 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
        $this->assertSame(75.0, $this->priceSourcesOf($result['sourceProduct'])['materials']['coveragePercent']);
    }

    public function testQuotesWithoutCoverageAreUnknownPricesForTheMaterialsAndTheSale(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: self::CANDIDATES,
            sellBooks: [],
            quotes: SellQuoteCallbacks::answering(['weightedPrice' => 10.0, 'ordersUsed' => 1]),
        );

        $this->assertSame([], $result['candidates']);
        $this->assertSame(
            [
                'marginPercent' => null,
                'sellPrice' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::TRITANIUM, self::COMPONENT_M],
            ],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'sellPrice', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testSourceWithoutBlueprintReadsNoOrderBookAndHasNoPriceSources(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: [],
            sellBooks: self::SELL_BOOKS,
            orderBookReads: 0,
            sourceHasBlueprint: false,
        );

        $this->assertArrayNotHasKey('priceSources', $result['sourceProduct']);
        $this->assertSame([], $result['candidates']);
    }

    private function analyzeMainScenario(bool $bestOrderReadAllowed = true): array
    {
        return $this->analyzePivot(
            sourceMaterials: self::SOURCE_MATERIALS,
            candidates: self::CANDIDATES,
            sellBooks: self::SELL_BOOKS,
            bestOrderReadAllowed: $bestOrderReadAllowed,
        );
    }

    private function analyzeReactionCandidateNeedingThreeHundredComponents(\Closure $quotes): array
    {
        return $this->analyzePivot(
            sourceMaterials: [['materialTypeId' => self::COMPONENT_M, 'quantity' => 10]],
            candidates: [
                self::CANDIDATE_REACTION => [
                    'outputPerRun' => 1,
                    'activityId' => self::REACTION_ACTIVITY_ID,
                    'materials' => [['materialTypeId' => self::COMPONENT_M, 'quantity' => 150]],
                ],
            ],
            sellBooks: [],
            stockByTypeId: [self::COMPONENT_M => 100],
            runs: 2,
            quotes: $quotes,
        );
    }

    private function analyzePivot(
        array $sourceMaterials,
        array $candidates,
        array $sellBooks,
        array $stockByTypeId = [],
        int $runs = 1,
        int $sourceOutputPerRun = 1,
        ?\Closure $quotes = null,
        int $orderBookReads = 1,
        bool $sourceHasBlueprint = true,
        bool $bestOrderReadAllowed = true,
    ): array {
        $sourceBlueprint = $this->blueprintProducing(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, $sourceOutputPerRun);
        $componentBlueprint = $this->blueprintProducing(self::COMPONENT_M_BLUEPRINT, self::COMPONENT_M, 1);

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                self::MANUFACTURING_ACTIVITY_ID !== $activityId => null,
                $sourceHasBlueprint && self::SOURCE_PRODUCT === $typeId => $sourceBlueprint,
                self::COMPONENT_M === $typeId => $componentBlueprint,
                default => null,
            },
        );

        $candidateMaterialsByBlueprint = [];
        $candidateProducts = [];
        foreach ($candidates as $candidateTypeId => $candidate) {
            $blueprintTypeId = $candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET;
            $candidateMaterialsByBlueprint[$blueprintTypeId] = $candidate['materials'];
            $candidateProducts[] = ['blueprintTypeId' => $blueprintTypeId, 'productTypeId' => $candidateTypeId, 'outputPerRun' => $candidate['outputPerRun'], 'activityId' => $candidate['activityId']];
        }

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds): array => $blueprintTypeIds === [self::SOURCE_BLUEPRINT]
                ? [self::SOURCE_BLUEPRINT => $sourceMaterials]
                : $candidateMaterialsByBlueprint,
        );
        $materialRepository->method('findProductsUsingMaterials')->willReturn($candidateProducts);

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturn($stockByTypeId);

        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->expects($this->exactly($orderBookReads))->method('getWeightedSellQuotesWithFallback')
            ->willReturnCallback($quotes ?? SellQuoteCallbacks::weighedFrom($sellBooks));
        $jitaMarketService->expects($bestOrderReadAllowed ? $this->any() : $this->never())->method('getPricesWithFallback');
        $jitaMarketService->method('getCachedDailyVolumes')->willReturn(self::DAILY_VOLUMES);

        $esiCostIndexService = $this->createStub(EsiCostIndexService::class);
        $esiCostIndexService->method('estimateJobInstallCost')->willReturn(
            ['cost' => self::JOB_INSTALL_COST_PER_RUN, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []],
        );

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturn(self::TYPE_NAMES);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
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
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
        );

        return $service->analyze(
            self::SOURCE_PRODUCT,
            $runs,
            self::SOLAR_SYSTEM_ID,
            new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE),
            $this->userWithId(),
            self::SOLAR_SYSTEM_ID,
        );
    }

    private function blueprintProducing(int $blueprintTypeId, int $productTypeId, int $outputPerRun): IndustryActivityProduct
    {
        $blueprint = new IndustryActivityProduct();
        $blueprint->setTypeId($blueprintTypeId);
        $blueprint->setProductTypeId($productTypeId);
        $blueprint->setQuantity($outputPerRun);
        $blueprint->setActivityId(self::MANUFACTURING_ACTIVITY_ID);

        return $blueprint;
    }

    private function onlyKeys(array $values, string ...$keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }

    private function priceSourcesOf(array $row): array
    {
        $this->assertArrayHasKey('priceSources', $row);

        return $row['priceSources'];
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
