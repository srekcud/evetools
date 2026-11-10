<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Industry\Application\Decryptors;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Repository\CachedStructureRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Industry\Application\InventionSkillLevels;
use App\Service\Industry\InventorSkills;
use App\Service\Industry\ProfitMarginService;
use App\Service\Industry\PublicContractPriceService;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use App\Tests\Unit\Service\CreatesJitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ProfitMarginService::class)]
#[Group('issue-538')]
final class ProfitMarginServiceCommonPriceRuleTest extends TestCase
{
    use CreatesJitaMarketService;

    private const int RIFTER = 587;
    private const int TRITANIUM = 34;

    private const int MANUFACTURING_SYSTEM_ID = 30000142;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const int JITA_STATION = 60003760;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;
    private const int BASE_QUANTITY_NEEDING_150_AFTER_ME10 = 166;
    private const int BASE_QUANTITY_NEEDING_300_AFTER_ME10 = 333;
    private const int BASE_QUANTITY_NEEDING_400_AFTER_ME10 = 444;
    private const int ME10 = 10;

    private const array TRITANIUM_SELL_BOOK = [
        ['price' => 5.0, 'volume' => 100],
        ['price' => 6.0, 'volume' => 100],
        ['price' => 7.0, 'volume' => 100],
    ];
    private const array RIFTER_SELL_BOOK = [
        ['price' => 10.0, 'volume' => 100],
        ['price' => 12.0, 'volume' => 100],
    ];

    private const float TOLERANCE = 1e-6;

    private ArrayAdapter $marketCache;
    private array $requestedTypeIds = [];

    protected function setUp(): void
    {
        $this->requestedTypeIds = [];
        $this->marketCache = new ArrayAdapter();
        $item = $this->marketCache->getItem('jita_market_prices');
        $item->set([self::TRITANIUM => self::TRITANIUM_SELL_BOOK, self::RIFTER => self::RIFTER_SELL_BOOK]);
        $this->marketCache->save($item);
    }

    public function testOneRunAtMe10PricesMaterialsAndTheJitaSaleOnTheWeightedPriceOfTheSameBookAsTheScan(): void
    {
        $analysis = $this->analyze(self::BASE_QUANTITY_NEEDING_150_AFTER_ME10, 150);

        $this->assertEqualsWithDelta(800.0 / 150.0, $analysis['materials'][0]['unitPrice'], self::TOLERANCE);
        $this->assertEqualsWithDelta(1600.0 / 150.0, $analysis['sellPrices']['jitaSell'], self::TOLERANCE);
        $this->assertEqualsWithDelta(1600.0, $analysis['margins']['jitaSell']['revenue'], self::TOLERANCE);
        $this->assertSame([], $analysis['warnings']);
        $this->assertSame([], $analysis['missingData']);
    }

    public function testMaterialNeededIn300UnitsIsPricedAtTheWeightedPriceOfTheBook(): void
    {
        $analysis = $this->analyze(self::BASE_QUANTITY_NEEDING_300_AFTER_ME10, 150);

        $this->assertEqualsWithDelta(6.0, $analysis['materials'][0]['unitPrice'], self::TOLERANCE);
        $this->assertSame([], $analysis['warnings']);
    }

    public function testMaterialNeededIn400UnitsIsAPartialPriceWith75PercentCoverage(): void
    {
        $analysis = $this->analyze(self::BASE_QUANTITY_NEEDING_400_AFTER_ME10, 100);

        $this->assertEqualsWithDelta(6.0, $analysis['materials'][0]['unitPrice'], self::TOLERANCE);
        $this->assertSame(
            [['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 75.0]],
            $analysis['warnings'],
        );
        $this->assertArrayHasKey('priceSources', $analysis);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 75.0], $analysis['priceSources']['materials']);
    }

    public function testProductSoldBy300UnitsPerRunIsAPartialSalePriceWith66Point7PercentCoverage(): void
    {
        $analysis = $this->analyze(self::BASE_QUANTITY_NEEDING_150_AFTER_ME10, 300);

        $this->assertEqualsWithDelta(11.0, $analysis['sellPrices']['jitaSell'], self::TOLERANCE);
        $this->assertSame(66.7, $analysis['sellPrices']['jitaSellCoveragePercent']);
        $this->assertArrayHasKey('priceSources', $analysis);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 66.7], $analysis['priceSources']['sale']['jitaSell']);
    }

    public function testProductAbsentFromTheCachedBookIsPricedByTheOnDemandEsiFallbackOnTheWholeOutputQuantity(): void
    {
        $item = $this->marketCache->getItem('jita_market_prices');
        $item->set([self::TRITANIUM => self::TRITANIUM_SELL_BOOK]);
        $this->marketCache->save($item);

        $analysis = $this->analyze(
            self::BASE_QUANTITY_NEEDING_150_AFTER_ME10,
            150,
            [self::RIFTER => [$this->esiSellOrder(10.0, 100), $this->esiSellOrder(12.0, 100)]],
        );

        $this->assertSame([self::RIFTER], $this->requestedTypeIds);
        $this->assertEqualsWithDelta(1600.0 / 150.0, $analysis['sellPrices']['jitaSell'], self::TOLERANCE);
        $this->assertNull($analysis['sellPrices']['jitaSellCoveragePercent']);
        $this->assertEqualsWithDelta(1600.0, $analysis['margins']['jitaSell']['revenue'], self::TOLERANCE);
    }

    public function testProductWithoutAnySellOrderOnEsiKeepsAnUnknownJitaSale(): void
    {
        $item = $this->marketCache->getItem('jita_market_prices');
        $item->set([self::TRITANIUM => self::TRITANIUM_SELL_BOOK]);
        $this->marketCache->save($item);

        $analysis = $this->analyze(self::BASE_QUANTITY_NEEDING_150_AFTER_ME10, 150, [self::RIFTER => []]);

        $this->assertNull($analysis['sellPrices']['jitaSell']);
        $this->assertNull($analysis['sellPrices']['jitaSellCoveragePercent']);
        $this->assertNull($analysis['margins']['jitaSell']);
    }

    private function analyze(int $baseTritaniumQuantity, int $outputPerRun, array $esiSellOrdersByTypeId = []): array
    {
        $jitaMarketService = $this->createJitaMarketService(
            new MockHttpClient(function (string $method, string $url) use ($esiSellOrdersByTypeId): MockResponse {
                $jsonHeaders = ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']];
                if (str_contains($url, '/history/')) {
                    return new MockResponse('[]', $jsonHeaders);
                }
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $typeId = (int) ($query['type_id'] ?? 0);
                $this->requestedTypeIds[] = $typeId;
                if (!array_key_exists($typeId, $esiSellOrdersByTypeId)) {
                    $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
                }

                return new MockResponse((string) json_encode($esiSellOrdersByTypeId[$typeId]), $jsonHeaders);
            }),
            'https://esi.evetech.net/latest',
            $this->marketCache,
        );

        return $this->profitMarginService($jitaMarketService, $baseTritaniumQuantity, $outputPerRun)->analyze(
            self::RIFTER,
            1,
            self::ME10,
            0,
            self::SELL_STRUCTURE_ID,
            self::MANUFACTURING_SYSTEM_ID,
            null,
            new SalesFees(0.0, 0.0),
            null,
            self::MANUFACTURING_SYSTEM_ID,
        );
    }

    private function profitMarginService(JitaMarketService $jitaMarketService, int $baseTritaniumQuantity, int $outputPerRun): ProfitMarginService
    {
        $decryptors = $this->createStub(Decryptors::class);
        $decryptors->method('all')->willReturn([]);

        $typeNames = [self::RIFTER => 'Rifter', self::TRITANIUM => 'Tritanium'];
        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findName')->willReturnCallback(static fn (int $typeId): ?string => $typeNames[$typeId] ?? null);
        $typeNameResolver->method('findNames')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($typeNames, array_flip($typeIds)),
        );

        $inventorSkills = $this->createStub(InventorSkills::class);
        $inventorSkills->method('levelsFor')->willReturn(InventionSkillLevels::none());

        $recipe = new Recipe(
            productTypeId: self::RIFTER,
            activity: ActivityKind::Manufacturing,
            outputPerRun: new OutputPerRun($outputPerRun),
            materials: [new RecipeMaterial(self::TRITANIUM, new Quantity($baseTritaniumQuantity))],
            maxProductionLimit: new Runs(self::BPO_MAX_PRODUCTION_LIMIT),
            baseTimeSeconds: 600,
        );

        return new ProfitMarginService(
            new ProductionCostCalculator(
                new InMemoryBlueprintCatalog($recipe),
                new InMemoryInventionCatalog(),
                new JitaMarketPrices($jitaMarketService),
                new InMemoryAdjustedPrices([self::TRITANIUM => 0.0]),
                new InMemorySystemCostIndices([self::MANUFACTURING_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.0]]),
                new InMemoryRigCategories(),
            ),
            new InMemoryUserProductionStructures(),
            $decryptors,
            $jitaMarketService,
            $this->createStub(StructureMarketService::class),
            $this->createStub(PublicContractPriceService::class),
            $typeNameResolver,
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(IndustryBlacklistService::class),
            $inventorSkills,
        );
    }

    private function esiSellOrder(float $price, int $volumeRemain): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'is_buy_order' => false, 'location_id' => self::JITA_STATION];
    }
}
