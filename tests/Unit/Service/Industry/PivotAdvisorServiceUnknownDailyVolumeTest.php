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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PivotAdvisorService::class)]
#[Group('issue-136')]
final class PivotAdvisorServiceUnknownDailyVolumeTest extends TestCase
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
    private const int UNPRICED_ISOTOPES = 16273;

    private const int CANDIDATE_X = 22456;
    private const int CANDIDATE_W = 22462;
    private const int CANDIDATE_Y = 22470;

    private const float TRITANIUM_PRICE = 5.0;
    private const float COMPONENT_TWO_PRICE = 50.0;
    private const float SOURCE_SELL_PRICE = 4000.0;

    private const array TYPE_NAMES = [
        self::SOURCE_PRODUCT => 'Source Ship',
        self::TRITANIUM => 'Tritanium',
        self::COMPONENT_TWO => 'Component Two',
        self::UNPRICED_ISOTOPES => 'Unpriced Isotopes',
        self::CANDIDATE_X => 'Candidate X',
        self::CANDIDATE_W => 'Candidate W',
        self::CANDIDATE_Y => 'Candidate Y',
    ];

    public function testCandidateWithoutCachedDailyVolumeHasUnknownDailyVolumeAndScoreButKeepsItsKnownMargin(): void
    {
        $result = $this->analyzeCandidatesXWAndY(dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_W => 9.0]);

        $this->assertSame(
            [
                'marginPercent' => 261.09,
                'profitPerUnit' => 671.0,
                'dailyVolume' => null,
                'score' => null,
                'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_DAILY_VOLUME,
                'missingPriceTypeIds' => [],
            ],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_X, $result), 'marginPercent', 'profitPerUnit', 'dailyVolume', 'score', 'unknownReason', 'missingPriceTypeIds'),
        );
    }

    public function testCandidateWithACachedDailyVolumeOfZeroKeepsAKnownZeroVolumeAndScoreWithoutUnknownReason(): void
    {
        $result = $this->analyzeCandidatesXWAndY(dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_X => 0.0, self::CANDIDATE_W => 9.0]);

        $this->assertSame(
            ['dailyVolume' => 0.0, 'score' => 0.0, 'unknownReason' => null],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_X, $result), 'dailyVolume', 'score', 'unknownReason'),
        );
    }

    public function testCandidateWithAnUnknownDailyVolumeComesAfterEveryCandidateWithAKnownScore(): void
    {
        $result = $this->analyzeCandidatesXWAndY(dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_W => 9.0]);

        $this->assertSame([self::CANDIDATE_W, self::CANDIDATE_X], array_column($result['candidates'], 'typeId'));
    }

    public function testUnknownMaterialPriceStaysTheReasonOfACandidateThatAlsoLacksADailyVolume(): void
    {
        $result = $this->analyzeCandidatesXWAndY(dailyVolumes: [self::SOURCE_PRODUCT => 25.0], unpricedCandidateY: true);

        $this->assertSame(
            ['dailyVolume' => null, 'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE],
            $this->onlyKeys($this->candidateOf(self::CANDIDATE_Y, $result), 'dailyVolume', 'unknownReason'),
        );
    }

    public function testSourceProductWithoutCachedDailyVolumeHasUnknownDailyVolumeWithTheReasonAndKeepsItsMargin(): void
    {
        $result = $this->analyzeCandidatesXWAndY(dailyVolumes: [self::CANDIDATE_X => 99.0, self::CANDIDATE_W => 9.0]);

        $this->assertSame(
            ['marginPercent' => 823.38, 'dailyVolume' => null, 'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_DAILY_VOLUME],
            $this->onlyKeys($result['sourceProduct'], 'marginPercent', 'dailyVolume', 'unknownReason'),
        );
    }

    public function testSourceProductWithACachedDailyVolumeKeepsItWithoutUnknownReason(): void
    {
        $result = $this->analyzeCandidatesXWAndY(dailyVolumes: [self::SOURCE_PRODUCT => 25.0, self::CANDIDATE_X => 99.0, self::CANDIDATE_W => 9.0]);

        $this->assertSame(
            ['dailyVolume' => 25.0, 'unknownReason' => null],
            $this->onlyKeys($result['sourceProduct'], 'dailyVolume', 'unknownReason'),
        );
    }

    public function testSourceProductWithOnlyRawMaterialsAndNoCachedDailyVolumeHasUnknownDailyVolumeWithTheReason(): void
    {
        $result = $this->analyzePivot(
            sourceMaterials: [['materialTypeId' => self::TRITANIUM, 'quantity' => 20]],
            candidates: [],
            jitaPrices: [self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE, self::TRITANIUM => self::TRITANIUM_PRICE],
            dailyVolumes: [],
        );

        $this->assertSame(
            ['dailyVolume' => null, 'unknownReason' => BatchProfitScannerService::UNKNOWN_REASON_MISSING_DAILY_VOLUME],
            $this->onlyKeys($result['sourceProduct'], 'dailyVolume', 'unknownReason'),
        );
    }

    private function analyzeCandidatesXWAndY(array $dailyVolumes, bool $unpricedCandidateY = false): array
    {
        $candidates = [
            self::CANDIDATE_X => [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 4],
            ],
            self::CANDIDATE_W => [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 3],
            ],
        ];
        $jitaPrices = [
            self::SOURCE_PRODUCT => self::SOURCE_SELL_PRICE,
            self::TRITANIUM => self::TRITANIUM_PRICE,
            self::COMPONENT_TWO => self::COMPONENT_TWO_PRICE,
            self::CANDIDATE_X => 1000.0,
            self::CANDIDATE_W => 900.0,
        ];
        if ($unpricedCandidateY) {
            $candidates[self::CANDIDATE_Y] = [
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 10],
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 2],
                ['materialTypeId' => self::UNPRICED_ISOTOPES, 'quantity' => 2],
            ];
            $jitaPrices[self::UNPRICED_ISOTOPES] = null;
            $jitaPrices[self::CANDIDATE_Y] = 1500.0;
        }

        return $this->analyzePivot(
            sourceMaterials: [
                ['materialTypeId' => self::COMPONENT_TWO, 'quantity' => 6],
                ['materialTypeId' => self::TRITANIUM, 'quantity' => 20],
            ],
            candidates: $candidates,
            jitaPrices: $jitaPrices,
            dailyVolumes: $dailyVolumes,
        );
    }

    private function analyzePivot(array $sourceMaterials, array $candidates, array $jitaPrices, array $dailyVolumes): array
    {
        $sourceBlueprint = $this->blueprintProducing(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT);
        $componentTwoBlueprint = $this->blueprintProducing(self::COMPONENT_TWO_BLUEPRINT, self::COMPONENT_TWO);

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                $activityId !== 1 => null,
                $typeId === self::SOURCE_PRODUCT => $sourceBlueprint,
                $typeId === self::COMPONENT_TWO => $componentTwoBlueprint,
                default => null,
            },
        );

        $candidateMaterialsByBlueprint = [];
        $candidateProducts = [];
        foreach ($candidates as $candidateTypeId => $materials) {
            $candidateMaterialsByBlueprint[$candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET] = $materials;
            $candidateProducts[] = ['blueprintTypeId' => $candidateTypeId + self::BLUEPRINT_TYPE_ID_OFFSET, 'productTypeId' => $candidateTypeId, 'outputPerRun' => 1, 'activityId' => 1];
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
        $jitaMarketService->method('getPricesWithFallback')->willReturn($jitaPrices);
        $jitaMarketService->method('getCachedDailyVolumes')->willReturn($dailyVolumes);

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
            self::RUNS,
            self::SOLAR_SYSTEM_ID,
            new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE),
            $this->userWithId(),
            self::SOLAR_SYSTEM_ID,
        );
    }

    private function blueprintProducing(int $blueprintTypeId, int $productTypeId): IndustryActivityProduct
    {
        $blueprint = new IndustryActivityProduct();
        $blueprint->setTypeId($blueprintTypeId);
        $blueprint->setProductTypeId($productTypeId);
        $blueprint->setQuantity(1);
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
