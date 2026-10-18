<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ShoppingList\ParseListInput;
use App\ApiResource\ShoppingList\ParseResultResource;
use App\ApiResource\ShoppingList\ShoppingListItemResource;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\MarketService;
use App\Service\ItemParserService;
use App\State\Processor\ShoppingList\ParseProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(ParseProcessor::class)]
final class ParseProcessorPricingTest extends TestCase
{
    private const int NITROGEN_ISOTOPES = 17888;
    private const int HEAVY_WATER = 16272;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const float TRANSPORT_COST_PER_M3 = 1200.0;
    private const int MARKET_STRUCTURE_ID = 1_000_000_000_001;
    private const string PRICE_ERROR = 'Unable to fetch market prices. Please try again later.';
    private const array TYPE_NAMES = [
        self::NITROGEN_ISOTOPES => 'Nitrogen Isotopes',
        self::HEAVY_WATER => 'Heavy Water',
        self::STRONTIUM_CLATHRATES => 'Strontium Clathrates',
    ];

    /** @var array<int, ?float> */
    private array $volumes = [];

    /** @var array<int, float> */
    private array $jitaPrices = [];

    /** @var array<int, float> */
    private array $structurePrices = [];

    private bool $marketUnavailable = false;

    public function testKnownVolumeChargesTheImportAndChoosesJitaWithImport(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => 0.5];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];
        $expectedVolume = 0.5;
        $expectedTotalVolume = 50.0;
        $expectedImportCost = 60000.0;
        $expectedJitaWithImport = 110000.0;
        $expectedJitaTotal = 50000.0;

        $result = $this->parse('100x Nitrogen Isotopes');

        $isotopes = $this->itemOf($result, self::NITROGEN_ISOTOPES);
        self::assertSame(100, $isotopes->quantity);
        self::assertSame($expectedVolume, $isotopes->volume);
        self::assertSame($expectedTotalVolume, $isotopes->totalVolume);
        self::assertSame($expectedImportCost, $isotopes->importCost);
        self::assertSame($expectedJitaWithImport, $isotopes->jitaWithImport);
        self::assertSame('jita', $isotopes->bestLocation);
        self::assertSame($expectedJitaWithImport, $isotopes->bestTotal);
        self::assertSame($expectedTotalVolume, $result->totals['volume']);
        self::assertSame($expectedImportCost, $result->totals['import']);
        self::assertSame($expectedJitaWithImport, $result->totals['jitaWithImport']);
        self::assertSame($expectedJitaTotal, $result->totals['jita']);
        self::assertSame($expectedJitaWithImport, $result->totals['best']);
        self::assertSame([], $result->missingVolumeTypeIds);
    }

    public function testNullVolumeLeavesTheImportUnknownAndNoBestLocationWithoutStructurePrice(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $expectedBestTotal = 0.0;

        $result = $this->parse('100x Nitrogen Isotopes');

        $this->assertIsotopesImportIsUnknownWithJitaTotalOnly($result);
        self::assertSame($expectedBestTotal, $result->totals['best']);
    }

    public function testTypeAbsentFromInvTypesLeavesTheImportUnknownLikeANullVolume(): void
    {
        $this->volumes = [];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $result = $this->parse('100x Nitrogen Isotopes');

        $this->assertIsotopesImportIsUnknownWithJitaTotalOnly($result);
    }

    public function testNullVolumeChoosesTheStructureNeverJitaWithoutImport(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 400.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 450.0];
        $expectedStructureTotal = 45000.0;
        $expectedJitaTotal = 40000.0;

        $result = $this->parse('100x Nitrogen Isotopes');

        $isotopes = $this->itemOf($result, self::NITROGEN_ISOTOPES);
        self::assertNull($isotopes->jitaWithImport);
        self::assertSame($expectedStructureTotal, $isotopes->structureTotal);
        self::assertSame('structure', $isotopes->bestLocation);
        self::assertSame($expectedStructureTotal, $isotopes->bestTotal);
        self::assertSame($expectedJitaTotal, $result->totals['jita']);
        self::assertSame($expectedStructureTotal, $result->totals['structure']);
        self::assertSame($expectedStructureTotal, $result->totals['best']);
        self::assertNull($result->totals['jitaWithImport']);
        self::assertSame([self::NITROGEN_ISOTOPES], $result->missingVolumeTypeIds);
    }

    public function testNullVolumeOfOneTypeLeavesTheImportTotalsUnknownAndKeepsTheOtherLine(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null, self::HEAVY_WATER => 2.0];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::HEAVY_WATER => 1000.0];
        $expectedHeavyWaterVolume = 2.0;
        $expectedHeavyWaterTotalVolume = 20.0;
        $expectedHeavyWaterImportCost = 24000.0;
        $expectedHeavyWaterJitaWithImport = 34000.0;
        $expectedJitaTotal = 60000.0;

        $result = $this->parse("100x Nitrogen Isotopes\n10x Heavy Water");

        $heavyWater = $this->itemOf($result, self::HEAVY_WATER);
        self::assertSame(10, $heavyWater->quantity);
        self::assertSame($expectedHeavyWaterVolume, $heavyWater->volume);
        self::assertSame($expectedHeavyWaterTotalVolume, $heavyWater->totalVolume);
        self::assertSame($expectedHeavyWaterImportCost, $heavyWater->importCost);
        self::assertSame($expectedHeavyWaterJitaWithImport, $heavyWater->jitaWithImport);
        self::assertSame('jita', $heavyWater->bestLocation);
        self::assertSame($expectedHeavyWaterJitaWithImport, $heavyWater->bestTotal);
        $isotopes = $this->itemOf($result, self::NITROGEN_ISOTOPES);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->importCost);
        self::assertNull($result->totals['volume']);
        self::assertNull($result->totals['import']);
        self::assertNull($result->totals['jitaWithImport']);
        self::assertSame($expectedJitaTotal, $result->totals['jita']);
        self::assertSame($expectedHeavyWaterJitaWithImport, $result->totals['best']);
        self::assertSame([self::NITROGEN_ISOTOPES], $result->missingVolumeTypeIds);
    }

    public function testZeroVolumeFromTheSdeStaysKnownWithAFreeImport(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => 0.0];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];
        $expectedVolume = 0.0;
        $expectedImportCost = 0.0;
        $expectedJitaWithImport = 50000.0;

        $result = $this->parse('100x Nitrogen Isotopes');

        $isotopes = $this->itemOf($result, self::NITROGEN_ISOTOPES);
        self::assertSame($expectedVolume, $isotopes->volume);
        self::assertSame($expectedVolume, $isotopes->totalVolume);
        self::assertSame($expectedImportCost, $isotopes->importCost);
        self::assertSame($expectedJitaWithImport, $isotopes->jitaWithImport);
        self::assertSame($expectedVolume, $result->totals['volume']);
        self::assertSame($expectedImportCost, $result->totals['import']);
        self::assertSame($expectedJitaWithImport, $result->totals['jitaWithImport']);
        self::assertSame([], $result->missingVolumeTypeIds);
    }

    public function testMissingVolumeTypeIdsAreListedInAscendingOrder(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null, self::HEAVY_WATER => 2.0];
        $this->jitaPrices = [
            self::NITROGEN_ISOTOPES => 500.0,
            self::HEAVY_WATER => 1000.0,
            self::STRONTIUM_CLATHRATES => 800.0,
        ];
        $expectedMissingVolumeTypeIds = [self::STRONTIUM_CLATHRATES, self::NITROGEN_ISOTOPES];

        $result = $this->parse("100x Nitrogen Isotopes\n10x Heavy Water\n7x Strontium Clathrates");

        self::assertSame($expectedMissingVolumeTypeIds, $result->missingVolumeTypeIds);
    }

    public function testATypeListedTwiceIsReportedOnce(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];
        $expectedMissingVolumeTypeIds = [self::NITROGEN_ISOTOPES];

        $result = $this->parse("100x Nitrogen  Isotopes\n50x Nitrogen Isotopes");

        self::assertSame($expectedMissingVolumeTypeIds, $result->missingVolumeTypeIds);
    }

    public function testNoItemFoundGivesZeroTotalsAndNoMissingVolumeTypeIds(): void
    {
        $expectedTotals = [
            'jita' => 0.0,
            'import' => 0.0,
            'jitaWithImport' => 0.0,
            'structure' => 0.0,
            'best' => 0.0,
            'volume' => 0.0,
        ];

        $result = $this->parse('25x Unknown Widget');

        self::assertSame([], $result->items);
        self::assertSame(['Unknown Widget'], $result->notFound);
        self::assertSame($expectedTotals, $result->totals);
        self::assertSame([], $result->missingVolumeTypeIds);
    }

    public function testPriceErrorWithNullVolumeLeavesTheVolumeAndImportUnknown(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null, self::HEAVY_WATER => 2.0];
        $this->marketUnavailable = true;
        $expectedHeavyWaterTotalVolume = 20.0;
        $expectedHeavyWaterImportCost = 24000.0;

        $result = $this->parse("100x Nitrogen Isotopes\n10x Heavy Water");

        self::assertSame(self::PRICE_ERROR, $result->priceError);
        $isotopes = $this->itemOf($result, self::NITROGEN_ISOTOPES);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->totalVolume);
        self::assertNull($isotopes->importCost);
        self::assertNull($isotopes->jitaWithImport);
        self::assertNull($isotopes->bestLocation);
        $heavyWater = $this->itemOf($result, self::HEAVY_WATER);
        self::assertSame($expectedHeavyWaterTotalVolume, $heavyWater->totalVolume);
        self::assertSame($expectedHeavyWaterImportCost, $heavyWater->importCost);
        self::assertNull($result->totals['volume']);
        self::assertNull($result->totals['import']);
        self::assertNull($result->totals['jitaWithImport']);
        self::assertSame([self::NITROGEN_ISOTOPES], $result->missingVolumeTypeIds);
    }

    private function assertIsotopesImportIsUnknownWithJitaTotalOnly(ParseResultResource $result): void
    {
        $expectedJitaTotal = 50000.0;
        $isotopes = $this->itemOf($result, self::NITROGEN_ISOTOPES);
        self::assertSame(100, $isotopes->quantity);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->totalVolume);
        self::assertNull($isotopes->importCost);
        self::assertNull($isotopes->jitaWithImport);
        self::assertSame($expectedJitaTotal, $isotopes->jitaTotal);
        self::assertNull($isotopes->bestLocation);
        self::assertNull($isotopes->bestTotal);
        self::assertNull($result->totals['volume']);
        self::assertNull($result->totals['import']);
        self::assertNull($result->totals['jitaWithImport']);
        self::assertSame($expectedJitaTotal, $result->totals['jita']);
        self::assertSame([self::NITROGEN_ISOTOPES], $result->missingVolumeTypeIds);
    }

    private function parse(string $text): ParseResultResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByNames')->willReturnCallback(static function (array $names): array {
            $typesByName = [];
            foreach ($names as $name) {
                $typeId = array_search($name, self::TYPE_NAMES, true);
                if ($typeId !== false) {
                    $typesByName[$name] = (new InvType())->setTypeId($typeId)->setTypeName($name)->setPublished(true);
                }
            }

            return $typesByName;
        });
        $invTypeRepository->method('findBy')->willReturnCallback(function (array $criteria): array {
            $types = [];
            foreach ($criteria['typeId'] as $typeId) {
                if (array_key_exists($typeId, $this->volumes)) {
                    $types[] = (new InvType())->setTypeId($typeId)->setTypeName(self::TYPE_NAMES[$typeId])->setVolume($this->volumes[$typeId]);
                }
            }

            return $types;
        });

        $marketService = $this->createStub(MarketService::class);
        if ($this->marketUnavailable) {
            $marketService->method('comparePrices')->willThrowException(new \RuntimeException('ESI unavailable'));
        } else {
            $marketService->method('comparePrices')->willReturnCallback(fn (array $typeIds): array => [
                'jita' => array_intersect_key($this->jitaPrices, array_flip($typeIds)),
                'jitaFromCache' => true,
                'jitaLastSync' => null,
                'structure' => array_intersect_key($this->structurePrices, array_flip($typeIds)),
                'structureId' => self::MARKET_STRUCTURE_ID,
                'structureName' => 'Market hub',
                'structureAccessible' => true,
                'structureFromCache' => true,
                'structureLastSync' => null,
            ]);
        }

        $processor = new ParseProcessor(
            $security,
            $invTypeRepository,
            $marketService,
            new ItemParserService($invTypeRepository),
            new NullLogger(),
        );

        $input = new ParseListInput();
        $input->text = $text;
        $input->transportCost = self::TRANSPORT_COST_PER_M3;

        return $processor->process($input, new Post());
    }

    private function itemOf(ParseResultResource $result, int $typeId): ShoppingListItemResource
    {
        foreach ($result->items as $item) {
            if ($item->typeId === $typeId) {
                return $item;
            }
        }

        self::fail(sprintf('Type %d is not in the shopping list.', $typeId));
    }
}
