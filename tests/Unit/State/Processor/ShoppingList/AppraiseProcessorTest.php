<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ShoppingList\AppraiseInput;
use App\ApiResource\ShoppingList\AppraisalItemResource;
use App\ApiResource\ShoppingList\AppraisalResultResource;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ItemParserService;
use App\Service\JitaMarketService;
use App\State\Processor\ShoppingList\AppraiseProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(AppraiseProcessor::class)]
final class AppraiseProcessorTest extends TestCase
{
    private const int NITROGEN_ISOTOPES = 17888;
    private const int HEAVY_WATER = 16272;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const string PRICE_ERROR = 'Unable to fetch market prices. Please try again later.';
    private const array TYPE_NAMES = [
        self::NITROGEN_ISOTOPES => 'Nitrogen Isotopes',
        self::HEAVY_WATER => 'Heavy Water',
        self::STRONTIUM_CLATHRATES => 'Strontium Clathrates',
    ];
    private const array SELL_PRICES = [self::NITROGEN_ISOTOPES => 500.0, self::HEAVY_WATER => 1000.0, self::STRONTIUM_CLATHRATES => 800.0];
    private const array BUY_PRICES = [self::NITROGEN_ISOTOPES => 450.0, self::HEAVY_WATER => 900.0, self::STRONTIUM_CLATHRATES => 760.0];
    private const array WEIGHTED_SELL_PRICES = [
        self::NITROGEN_ISOTOPES => ['weightedPrice' => 510.0, 'coverage' => 1.0, 'ordersUsed' => 2],
        self::HEAVY_WATER => ['weightedPrice' => 1020.0, 'coverage' => 0.8, 'ordersUsed' => 3],
        self::STRONTIUM_CLATHRATES => ['weightedPrice' => 815.0, 'coverage' => 0.7, 'ordersUsed' => 5],
    ];
    private const array WEIGHTED_BUY_PRICES = [
        self::NITROGEN_ISOTOPES => ['weightedPrice' => 430.0, 'coverage' => 0.9, 'ordersUsed' => 4],
        self::HEAVY_WATER => ['weightedPrice' => 880.0, 'coverage' => 1.0, 'ordersUsed' => 1],
        self::STRONTIUM_CLATHRATES => ['weightedPrice' => 745.0, 'coverage' => 0.6, 'ordersUsed' => 6],
    ];

    /** @var array<int, ?float> */
    private array $volumes = [];

    private bool $marketUnavailable = false;

    public function testKnownVolumeGivesTheItemAndTotalVolume(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => 0.5];
        $expectedVolume = 0.5;
        $expectedTotalVolume = 50.0;

        $appraisal = $this->appraise('100x Nitrogen Isotopes');

        $isotopes = $this->itemOf($appraisal, self::NITROGEN_ISOTOPES);
        self::assertSame(100, $isotopes->quantity);
        self::assertSame($expectedVolume, $isotopes->volume);
        self::assertSame($expectedTotalVolume, $isotopes->totalVolume);
        self::assertSame($expectedTotalVolume, $appraisal->totals['volume']);
        self::assertSame([], $appraisal->missingVolumeTypeIds);
    }

    public function testNullVolumeLeavesTheItemAndTotalVolumeUnknownAndKeepsThePriceTotals(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null, self::HEAVY_WATER => 2.0];

        $appraisal = $this->appraise("100x Nitrogen Isotopes\n10x Heavy Water");

        $this->assertIsotopesVolumeIsUnknownAndPriceTotalsAreKept($appraisal);
    }

    public function testTypeAbsentFromInvTypesLeavesTheVolumeUnknownLikeANullVolume(): void
    {
        $this->volumes = [self::HEAVY_WATER => 2.0];

        $appraisal = $this->appraise("100x Nitrogen Isotopes\n10x Heavy Water");

        $this->assertIsotopesVolumeIsUnknownAndPriceTotalsAreKept($appraisal);
    }

    public function testMissingVolumeTypeIdsAreListedInAscendingOrder(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null, self::HEAVY_WATER => 2.0];
        $expectedMissingVolumeTypeIds = [self::STRONTIUM_CLATHRATES, self::NITROGEN_ISOTOPES];

        $appraisal = $this->appraise("100x Nitrogen Isotopes\n10x Heavy Water\n7x Strontium Clathrates");

        self::assertSame($expectedMissingVolumeTypeIds, $appraisal->missingVolumeTypeIds);
    }

    public function testATypeListedTwiceIsReportedOnce(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null];
        $expectedMissingVolumeTypeIds = [self::NITROGEN_ISOTOPES];

        $appraisal = $this->appraise("100x Nitrogen  Isotopes\n50x Nitrogen Isotopes");

        self::assertSame($expectedMissingVolumeTypeIds, $appraisal->missingVolumeTypeIds);
    }

    public function testNoItemFoundGivesZeroTotalsAndNoMissingVolumeTypeIds(): void
    {
        $expectedTotals = [
            'sellTotal' => 0.0,
            'buyTotal' => 0.0,
            'splitTotal' => 0.0,
            'sellTotalWeighted' => 0.0,
            'buyTotalWeighted' => 0.0,
            'splitTotalWeighted' => 0.0,
            'volume' => 0.0,
        ];

        $appraisal = $this->appraise('25x Unknown Widget');

        self::assertSame([], $appraisal->items);
        self::assertSame(['Unknown Widget'], $appraisal->notFound);
        self::assertSame($expectedTotals, $appraisal->totals);
        self::assertSame([], $appraisal->missingVolumeTypeIds);
    }

    public function testPriceErrorWithNullVolumeLeavesTheVolumeUnknown(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null, self::HEAVY_WATER => 2.0];
        $this->marketUnavailable = true;
        $expectedHeavyWaterTotalVolume = 20.0;

        $appraisal = $this->appraise("100x Nitrogen Isotopes\n10x Heavy Water");

        self::assertSame(self::PRICE_ERROR, $appraisal->priceError);
        $isotopes = $this->itemOf($appraisal, self::NITROGEN_ISOTOPES);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->totalVolume);
        self::assertSame($expectedHeavyWaterTotalVolume, $this->itemOf($appraisal, self::HEAVY_WATER)->totalVolume);
        self::assertNull($appraisal->totals['volume']);
        self::assertSame([self::NITROGEN_ISOTOPES], $appraisal->missingVolumeTypeIds);
    }

    private function assertIsotopesVolumeIsUnknownAndPriceTotalsAreKept(AppraisalResultResource $appraisal): void
    {
        $expectedHeavyWaterVolume = 2.0;
        $expectedHeavyWaterTotalVolume = 20.0;
        $expectedSellTotal = 60000.0;
        $expectedBuyTotal = 54000.0;
        $expectedSplitTotal = 57000.0;
        $expectedSellTotalWeighted = 61200.0;
        $expectedBuyTotalWeighted = 51800.0;
        $expectedSplitTotalWeighted = 56500.0;

        $isotopes = $this->itemOf($appraisal, self::NITROGEN_ISOTOPES);
        self::assertSame(100, $isotopes->quantity);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->totalVolume);
        $heavyWater = $this->itemOf($appraisal, self::HEAVY_WATER);
        self::assertSame(10, $heavyWater->quantity);
        self::assertSame($expectedHeavyWaterVolume, $heavyWater->volume);
        self::assertSame($expectedHeavyWaterTotalVolume, $heavyWater->totalVolume);
        self::assertNull($appraisal->totals['volume']);
        self::assertSame($expectedSellTotal, $appraisal->totals['sellTotal']);
        self::assertSame($expectedBuyTotal, $appraisal->totals['buyTotal']);
        self::assertSame($expectedSplitTotal, $appraisal->totals['splitTotal']);
        self::assertSame($expectedSellTotalWeighted, $appraisal->totals['sellTotalWeighted']);
        self::assertSame($expectedBuyTotalWeighted, $appraisal->totals['buyTotalWeighted']);
        self::assertSame($expectedSplitTotalWeighted, $appraisal->totals['splitTotalWeighted']);
        self::assertSame([self::NITROGEN_ISOTOPES], $appraisal->missingVolumeTypeIds);
    }

    private function appraise(string $text): AppraisalResultResource
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

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        if ($this->marketUnavailable) {
            $jitaMarketService->method('getPricesWithFallback')->willThrowException(new \RuntimeException('ESI unavailable'));
        } else {
            $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
                static fn (array $typeIds): array => array_intersect_key(self::SELL_PRICES, array_flip($typeIds)),
            );
            $jitaMarketService->method('getBuyPricesWithFallback')->willReturnCallback(
                static fn (array $typeIds): array => array_intersect_key(self::BUY_PRICES, array_flip($typeIds)),
            );
            $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturnCallback(
                static fn (array $typeQuantities): array => array_intersect_key(self::WEIGHTED_SELL_PRICES, $typeQuantities),
            );
            $jitaMarketService->method('getWeightedBuyPricesWithFallback')->willReturnCallback(
                static fn (array $typeQuantities): array => array_intersect_key(self::WEIGHTED_BUY_PRICES, $typeQuantities),
            );
        }
        $jitaMarketService->method('getAverageDailyVolumes')->willReturn([]);

        $processor = new AppraiseProcessor(
            $security,
            new ItemParserService($invTypeRepository),
            $jitaMarketService,
            $invTypeRepository,
            new NullLogger(),
        );

        $input = new AppraiseInput();
        $input->text = $text;

        return $processor->process($input, new Post());
    }

    private function itemOf(AppraisalResultResource $appraisal, int $typeId): AppraisalItemResource
    {
        foreach ($appraisal->items as $item) {
            if ($item->typeId === $typeId) {
                return $item;
            }
        }

        self::fail(sprintf('Type %d is not in the appraisal.', $typeId));
    }
}
