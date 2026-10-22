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
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PivotAdvisorService::class)]
#[Group('issue-8')]
final class PivotAdvisorServiceUnknownMaterialPriceTest extends TestCase
{
    use CreatesUserWithId;
    private const int SOLAR_SYSTEM_ID = 30002510;
    private const float BROKER_FEE_RATE = 0.036;
    private const float SALES_TAX_RATE = 0.036;
    private const float JOB_INSTALL_COST_PER_RUN = 12.0;
    private const int RUNS = 3;
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1000;

    private const int SOURCE_PRODUCT = 11379;
    private const int SOURCE_BLUEPRINT = 11380;
    private const int TRITANIUM = 34;
    private const int COMPONENT_TWO = 11399;
    private const int COMPONENT_TWO_BLUEPRINT = 99001;
    private const int COMPONENT_FOUR = 11400;
    private const int COMPONENT_FOUR_BLUEPRINT = 99002;
    private const int UNPRICED_ISOTOPES = 16273;
    private const int UNPRICED_GAS = 16275;

    private const int CANDIDATE_X = 22456;
    private const int CANDIDATE_W = 22462;
    private const int CANDIDATE_Y = 22470;
    private const int CANDIDATE_Z = 22480;
    private const int CANDIDATE_V = 22490;
    private const int CANDIDATE_U = 22500;
    private const int CANDIDATE_C1 = 22511;
    private const int CANDIDATE_C2 = 22512;
    private const int CANDIDATE_C3 = 22513;
    private const int CANDIDATE_C4 = 22514;
    private const int CANDIDATE_C5 = 22515;
    private const int CANDIDATE_C6 = 22516;
    private const int CANDIDATE_T = 22520;

    private const float TRITANIUM_PRICE = 5.0;
    private const float COMPONENT_TWO_PRICE = 50.0;
    private const float SOURCE_SELL_PRICE = 4000.0;

    private const array TYPE_NAMES = [
        self::SOURCE_PRODUCT => 'Source Ship',
        self::TRITANIUM => 'Tritanium',
        self::COMPONENT_TWO => 'Component Two',
        self::COMPONENT_FOUR => 'Component Four',
        self::UNPRICED_ISOTOPES => 'Unpriced Isotopes',
        self::UNPRICED_GAS => 'Unpriced Gas',
        self::CANDIDATE_X => 'Candidate X',
        self::CANDIDATE_W => 'Candidate W',
        self::CANDIDATE_Y => 'Candidate Y',
        self::CANDIDATE_Z => 'Candidate Z',
        self::CANDIDATE_V => 'Candidate V',
        self::CANDIDATE_U => 'Candidate U',
        self::CANDIDATE_T => 'Candidate T',
    ];

    private const array GROUP_NAMES = [
        self::SOURCE_PRODUCT => 'Destroyer',
        self::CANDIDATE_X => 'Cruiser',
        self::CANDIDATE_W => 'Frigate',
        self::CANDIDATE_Y => 'Battleship',
        self::CANDIDATE_Z => 'Industrial',
        self::CANDIDATE_V => 'Hauler',
        self::CANDIDATE_U => 'Carrier',
    ];

    public function testCandidateWithEveryMaterialPricedKeepsItsMarginScoreAndCoverageWithoutUnknownReason(): void
    {
        $result = $this->analyzeCandidatesXWAndY();

        $this->assertSame([
            'typeId' => self::CANDIDATE_X,
            'typeName' => 'Candidate X',
            'groupName' => 'Cruiser',
            'marginPercent' => 261.09,
            'profitPerUnit' => 671.0,
            'dailyVolume' => 99.0,
            'coveragePercent' => 25.0,
            'missingComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 3, 'cost' => 150.0]],
            'additionalCost' => 150.0,
            'estimatedProfit' => 2013.0,
            'score' => 13054.47,
            'unknownReason' => null,
            'missingPriceTypeIds' => [],
        ], $this->candidateOf(self::CANDIDATE_X, $result));
    }

    public function testCandidateWithAnUnpricedMaterialHasUnknownMarginProfitAndScoreWithTheMissingMaterialPriceReason(): void
    {
        $result = $this->analyzeCandidatesXWAndY();

        $this->assertSame([
            'typeId' => self::CANDIDATE_Y,
            'typeName' => 'Candidate Y',
            'groupName' => 'Battleship',
            'marginPercent' => null,
            'profitPerUnit' => null,
            'dailyVolume' => 999.0,
            'coveragePercent' => 50.0,
            'missingComponents' => [['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 1, 'cost' => 50.0]],
            'additionalCost' => 50.0,
            'estimatedProfit' => null,
            'score' => null,
            'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
            'missingPriceTypeIds' => [self::UNPRICED_ISOTOPES],
        ], $this->candidateOf(self::CANDIDATE_Y, $result));
    }

    public function testCandidatesWithAnUnknownScoreComeAfterEveryPricedCandidateInAscendingTypeIdOrder(): void
    {
        $result = $this->analyzeCandidatesXWAndY();

        $this->assertSame([self::CANDIDATE_X, self::CANDIDATE_W, self::CANDIDATE_Y], array_column($result['candidates'], 'typeId'));
        $this->assertSame([self::SOURCE_PRODUCT, self::CANDIDATE_X, self::CANDIDATE_W, self::CANDIDATE_Y], $result['matrixProductIds']);
    }

    public function testSourceProductWithEveryMaterialPricedHasAKnownMarginWithoutUnknownReason(): void
    {
        $sourceProduct = $this->analyzeCandidatesXWAndY()['sourceProduct'];

        $this->assertSame(
            ['marginPercent' => 823.38, 'unknownReason' => null, 'missingPriceTypeIds' => []],
            $this->onlyKeys($sourceProduct, 'marginPercent', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testMaterialPricedAtZeroByTheMarketIsAKnownPrice(): void
    {
        $result = $this->analyzeCandidatesXWAndY(tritaniumPrice: 0.0);

        $this->assertSame(
            ['marginPercent' => 337.74, 'score' => 16886.79, 'unknownReason' => null, 'missingPriceTypeIds' => []],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_X, $result), 'marginPercent', 'score', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testCandidateWithAnUnpricedBuildableMaterialInPartialStockHasUnknownCoverageAndAdditionalCost(): void
    {
        $result = $this->analyzeCandidateZUsingUnpricedBuildableMaterial();

        $this->assertSame([
            [
                'typeId' => self::CANDIDATE_Z,
                'typeName' => 'Candidate Z',
                'groupName' => 'Industrial',
                'marginPercent' => null,
                'profitPerUnit' => null,
                'dailyVolume' => 49.0,
                'coveragePercent' => null,
                'missingComponents' => [
                    ['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 3, 'cost' => 150.0],
                    ['typeId' => self::COMPONENT_FOUR, 'typeName' => 'Component Four', 'quantity' => 3, 'cost' => null],
                ],
                'additionalCost' => null,
                'estimatedProfit' => null,
                'score' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::COMPONENT_FOUR],
            ],
        ], $result['candidates']);
    }

    public function testSourceProductWithAnUnpricedMaterialHasAnUnknownMarginWithTheMissingMaterialPriceReason(): void
    {
        $result = $this->analyzeCandidateZUsingUnpricedBuildableMaterial();

        $this->assertSame([
            'typeId' => self::SOURCE_PRODUCT,
            'typeName' => 'Source Ship',
            'groupName' => 'Destroyer',
            'marginPercent' => null,
            'sellPrice' => self::SOURCE_SELL_PRICE,
            'dailyVolume' => 25.0,
            'keyComponents' => [
                ['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'inStock' => 1],
                ['typeId' => self::COMPONENT_FOUR, 'typeName' => 'Component Four', 'inStock' => 2],
            ],
            'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
            'missingPriceTypeIds' => [self::COMPONENT_FOUR],
        ], $result['sourceProduct']);
    }

    public function testUnpricedMaterialListedTwiceAppearsOnceInSortedMissingPriceTypeIds(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_V => [
                    ['materialTypeId' => self::UNPRICED_GAS, 'quantity' => 3],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                    ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 2],
                    ['materialTypeId' => self::UNPRICED_GAS, 'quantity' => 1],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::UNPRICED_ISOTOPES => null,
                self::UNPRICED_GAS => null,
                self::CANDIDATE_V => 1800.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_V => 39.0],
        );

        $this->assertSame(
            [
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE,
                'missingPriceTypeIds' => [self::UNPRICED_ISOTOPES, self::UNPRICED_GAS],
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_V, $result), 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testCandidateWithAnUnknownScoreIsCutBeforeAnyPricedCandidateWhenMaxCandidatesIsExceeded(): void
    {
        $sharedMaterials = [
            ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
            ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
        ];

        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_U => [...$sharedMaterials, ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 2]],
                self::CANDIDATE_C1 => $sharedMaterials,
                self::CANDIDATE_C2 => $sharedMaterials,
                self::CANDIDATE_C3 => $sharedMaterials,
                self::CANDIDATE_C4 => $sharedMaterials,
                self::CANDIDATE_C5 => $sharedMaterials,
                self::CANDIDATE_C6 => $sharedMaterials,
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::UNPRICED_ISOTOPES => null,
                self::CANDIDATE_U => 2500.0,
                self::CANDIDATE_C1 => 1100.0,
                self::CANDIDATE_C2 => 1300.0,
                self::CANDIDATE_C3 => 1500.0,
                self::CANDIDATE_C4 => 1700.0,
                self::CANDIDATE_C5 => 1900.0,
                self::CANDIDATE_C6 => 2100.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [
                self::SOURCE_PRODUCT => 25.0,
                self::CANDIDATE_U => 999.0,
                self::CANDIDATE_C1 => 9.0,
                self::CANDIDATE_C2 => 99.0,
                self::CANDIDATE_C3 => 19.0,
                self::CANDIDATE_C4 => 49.0,
                self::CANDIDATE_C5 => 29.0,
                self::CANDIDATE_C6 => 0.0,
            ],
        );

        $this->assertSame(
            [self::CANDIDATE_C4, self::CANDIDATE_C5, self::CANDIDATE_C2, self::CANDIDATE_C3, self::CANDIDATE_C1, self::CANDIDATE_C6],
            array_column($result['candidates'], 'typeId'),
        );
        $this->assertSame(0.0, $this->candidateOf(self::CANDIDATE_C6, $result)['score']);
    }

    public static function inputOrdersOfTwoUnknownScoreCandidatesAndAPricedOne(): iterable
    {
        yield 'higher unknown type id first' => [[self::CANDIDATE_Z, self::CANDIDATE_T, self::CANDIDATE_Y]];
        yield 'lower unknown type id first' => [[self::CANDIDATE_Y, self::CANDIDATE_T, self::CANDIDATE_Z]];
    }

    #[DataProvider('inputOrdersOfTwoUnknownScoreCandidatesAndAPricedOne')]
    public function testTwoUnknownScoreCandidatesAreOrderedByAscendingTypeIdWhateverTheirInputOrder(array $candidateInputOrder): void
    {
        $materialsByCandidate = [
            self::CANDIDATE_Z => [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                ['materialTypeId' => self::UNPRICED_GAS, 'quantity' => 3],
            ],
            self::CANDIDATE_T => [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
            ],
            self::CANDIDATE_Y => [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 2],
                ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 2],
            ],
        ];

        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: array_combine(
                $candidateInputOrder,
                array_map(static fn (int $typeId): array => $materialsByCandidate[$typeId], $candidateInputOrder),
            ),
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::UNPRICED_ISOTOPES => null,
                self::UNPRICED_GAS => null,
                self::CANDIDATE_Z => 2000.0,
                self::CANDIDATE_T => 1100.0,
                self::CANDIDATE_Y => 1500.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_Z => 49.0, self::CANDIDATE_T => 19.0, self::CANDIDATE_Y => 999.0],
        );

        $this->assertSame([self::CANDIDATE_T, self::CANDIDATE_Y, self::CANDIDATE_Z], array_column($result['candidates'], 'typeId'));
    }

    public function testMissingComponentCostAdditionalCostProfitAndSourceSellPriceAreRoundedToTheCent(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::COMPONENT_FOUR, 'quantity' => 3],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_X => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                    ['materialTypeId' => self::COMPONENT_FOUR, 'quantity' => 5],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => 4000.456,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => 33.337,
                self::COMPONENT_FOUR => 12.3456,
                self::CANDIDATE_X => 1000.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1, self::COMPONENT_FOUR => 2],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_X => 99.0],
        );

        $this->assertSame(
            [
                'marginPercent' => 268.14,
                'profitPerUnit' => 675.92,
                'missingComponents' => [
                    ['typeId' => self::COMPONENT_TWO, 'typeName' => 'Component Two', 'quantity' => 3, 'cost' => 100.01],
                    ['typeId' => self::COMPONENT_FOUR, 'typeName' => 'Component Four', 'quantity' => 3, 'cost' => 37.04],
                ],
                'additionalCost' => 137.05,
                'estimatedProfit' => 2027.77,
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_X, $result), 'marginPercent', 'profitPerUnit', 'missingComponents', 'additionalCost', 'estimatedProfit'),
        );
        $this->assertSame(
            ['marginPercent' => 994.92, 'sellPrice' => 4000.46],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'sellPrice'),
        );
    }

    public function testBuildableMaterialWhoseStockEqualsTheNeedIsNotAMissingComponent(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_X => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::CANDIDATE_X => 1000.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 4],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_X => 99.0],
        );

        $this->assertSame(
            ['coveragePercent' => 100.0, 'missingComponents' => [], 'additionalCost' => 0.0],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_X, $result), 'coveragePercent', 'missingComponents', 'additionalCost'),
        );
    }

    public function testCandidateSoldAtZeroIsExcludedAndSourceProductSoldAtZeroHasNoMargin(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_W => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 3],
                ],
                self::CANDIDATE_X => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => 0.0,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::CANDIDATE_W => 0.0,
                self::CANDIDATE_X => 1000.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_W => 9.0, self::CANDIDATE_X => 99.0],
        );

        $this->assertSame([self::CANDIDATE_X], array_column($result['candidates'], 'typeId'));
        $this->assertSame(
            ['marginPercent' => null, 'sellPrice' => 0.0, 'unknownReason' => null],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'sellPrice', 'unknownReason'),
        );
    }

    public function testCandidateWithAZeroTotalCostIsExcludedAndSourceProductWithAZeroTotalCostHasNoMargin(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_X => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => 0.0,
                self::COMPONENT_TWO => 0.0,
                self::CANDIDATE_X => 1000.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_X => 99.0],
            jobInstallCostPerRun: 0.0,
        );

        $this->assertSame([], $result['candidates']);
        $this->assertSame(
            ['marginPercent' => null, 'sellPrice' => self::SOURCE_SELL_PRICE, 'unknownReason' => null],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'sellPrice', 'unknownReason'),
        );
    }

    public function testMarginAndProfitPerUnitCountEveryUnitOfAMultiUnitRun(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_X => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::CANDIDATE_X => 1000.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_X => 99.0],
            outputPerRun: 10,
        );

        $this->assertSame(
            ['marginPercent' => 3510.89, 'profitPerUnit' => 902.3, 'estimatedProfit' => 2706.9],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_X, $result), 'marginPercent', 'profitPerUnit', 'estimatedProfit'),
        );
        $this->assertSame(9133.83, $result['sourceProduct']['marginPercent']);
    }

    private function analyzeCandidatesXWAndY(float $tritaniumPrice = self::TRITANIUM_PRICE): array
    {
        return $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_Y => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 2],
                    ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 2],
                ],
                self::CANDIDATE_W => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 3],
                    ['materialTypeId' => self::UNPRICED_GAS, 'quantity' => 3],
                ],
                self::CANDIDATE_X => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => $tritaniumPrice,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::UNPRICED_ISOTOPES => null,
                self::UNPRICED_GAS => null,
                self::CANDIDATE_X => 1000.0,
                self::CANDIDATE_W => 1200.0,
                self::CANDIDATE_Y => 1500.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1],
            dailyVolumes: [
                self::SOURCE_PRODUCT => 25.0,
                self::CANDIDATE_X => 99.0,
                self::CANDIDATE_W => 9.0,
                self::CANDIDATE_Y => 999.0,
            ],
        );
    }

    private function analyzeCandidateZUsingUnpricedBuildableMaterial(): array
    {
        return $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::COMPONENT_FOUR, 'quantity' => 3],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: [
                self::CANDIDATE_Z => [
                    ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                    ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
                    ['materialTypeId' => self::COMPONENT_FOUR, 'quantity' => 5],
                ],
            ],
            jitaPrices: [
                self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
                self::TRITANIUM => self::TRITANIUM_PRICE,
                self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
                self::COMPONENT_FOUR => null,
                self::CANDIDATE_Z => 2000.0,
            ],
            stockByTypeId: [self::COMPONENT_TWO => 1, self::COMPONENT_FOUR => 2],
            dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_Z => 49.0],
        );
    }

    private function analyzePivot(
        array $sourceMaterials,
        array $candidates,
        array $jitaPrices,
        array $stockByTypeId,
        array $dailyVolumes,
        float $jobInstallCostPerRun = self::JOB_INSTALL_COST_PER_RUN,
        int $outputPerRun = 1,
    ): array {
        $sourceBlueprint = $this->blueprintProducing(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, $outputPerRun);
        $componentTwoBlueprint = $this->blueprintProducing(self::COMPONENT_TWO_BLUEPRINT, self::COMPONENT_TWO);
        $componentFourBlueprint = $this->blueprintProducing(self::COMPONENT_FOUR_BLUEPRINT, self::COMPONENT_FOUR);

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                $activityId !== 1 => null,
                $typeId === self::SOURCE_PRODUCT => $sourceBlueprint,
                $typeId === self::COMPONENT_TWO => $componentTwoBlueprint,
                $typeId === self::COMPONENT_FOUR => $componentFourBlueprint,
                default => null,
            },
        );

        $candidateMaterialsByBlueprint = [];
        $candidateProducts = [];
        foreach ($candidates as $candidateTypeId => $materials) {
            $candidateMaterialsByBlueprint[$candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET] = $materials;
            $candidateProducts[] = ['blueprintTypeId' => $candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET, 'productTypeId' => $candidateTypeId, 'outputPerRun' => $outputPerRun, 'activityId' => 1];
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

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturn($jitaPrices);
        $jitaMarketService->method('getCachedDailyVolumes')->willReturn($dailyVolumes);

        $esiCostIndexService = $this->createStub(EsiCostIndexService::class);
        $esiCostIndexService->method('estimateJobInstallCost')->willReturn(
            ['cost' => $jobInstallCostPerRun, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []],
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
            new FacilityTaxResolver(new InMemoryUserProductionStructures()),
        );

        return $service->analyze(
            self::SOURCE_PRODUCT,
            self::RUNS,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE_RATE,
            self::SALES_TAX_RATE,
            $this->userWithId(),
        );
    }

    private function blueprintProducing(int $blueprintTypeId, int $productTypeId, int $outputPerRun = 1): IndustryActivityProduct
    {
        $blueprint = new IndustryActivityProduct();
        $blueprint->setTypeId($blueprintTypeId);
        $blueprint->setProductTypeId($productTypeId);
        $blueprint->setQuantity($outputPerRun);
        $blueprint->setActivityId(1);

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
