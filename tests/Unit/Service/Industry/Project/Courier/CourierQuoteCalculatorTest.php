<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

use App\Industry\Application\CourierHub;
use App\Service\Industry\Project\Courier\CourierQuoteCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourierQuoteCalculator::class)]
#[Group('issue-351')]
final class CourierQuoteCalculatorTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int ZYDRINE = 39;
    private const int SLEIPNIR_HULL = 22_442;
    private const int MEGACYTE = 40;
    private const int MORPHITE = 11_399;
    private const int JUMPS_JITA_TO_HUB = 4;
    private const int HUB_SYSTEM_ID = 30_000_772;

    private CourierQuoteCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CourierQuoteCalculator();
    }

    public function testExampleTritaniumAndZydrineArePricedByVolumeAndTheRateFromJita(): void
    {
        $quote = $this->calculator->quote(
            [
                ['typeId' => self::TRITANIUM, 'quantity' => 10_000, 'totalPrice' => 41_000.0],
                ['typeId' => self::ZYDRINE, 'quantity' => 100, 'totalPrice' => 139_000.0],
            ],
            [self::TRITANIUM => 0.01, self::ZYDRINE => 0.01],
            true,
            $this->hub(),
            self::JUMPS_JITA_TO_HUB,
        );

        self::assertSame(
            [101.0, 180_000, 121_200, 4, 'ready', []],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    public function testExampleBulkyPurchaseIsPricedByVolumeAndTheRateFromJita(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::SLEIPNIR_HULL, 'quantity' => 4, 'totalPrice' => 22_632_500.0]],
            [self::SLEIPNIR_HULL => 238.375],
            true,
            $this->hub(),
            self::JUMPS_JITA_TO_HUB,
        );

        self::assertSame(
            [953.5, 22_632_500, 1_144_200, 4, 'ready'],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus],
        );
    }

    public function testExampleUnknownRouteKeepsVolumeAndCollateralWithoutReward(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::SLEIPNIR_HULL, 'quantity' => 4, 'totalPrice' => 22_632_500.0]],
            [self::SLEIPNIR_HULL => 238.375],
            true,
            $this->hub(),
            null,
        );

        self::assertSame(
            [953.5, 22_632_500, null, null, 'route_unknown'],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus],
        );
    }

    public function testExampleTypeWithoutSdeVolumeLeavesVolumeAndRewardUnknownAndListsTheType(): void
    {
        $quote = $this->calculator->quote(
            [
                ['typeId' => self::MEGACYTE, 'quantity' => 300, 'totalPrice' => 1_000.2],
                ['typeId' => self::MORPHITE, 'quantity' => 7, 'totalPrice' => 250.05],
            ],
            [self::MEGACYTE => 0.01],
            true,
            $this->hub(),
            self::JUMPS_JITA_TO_HUB,
        );

        self::assertSame(
            [null, 1_251, null, 4, 'volume_unknown', [self::MORPHITE]],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    public function testExampleTwentyFiveCubicMetersToAHubFourJumpsFromJitaGiveTheRewardOfTheRateFromJita(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::TRITANIUM, 'quantity' => 2_500, 'totalPrice' => 10_250.0]],
            [self::TRITANIUM => 0.01],
            true,
            $this->hub(),
            self::JUMPS_JITA_TO_HUB,
        );

        self::assertSame(
            [25.0, 10_250, 30_000, 4, 'ready', []],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    public function testRewardIgnoresTheBridgeRateAndIsRoundedToTheIsk(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::SLEIPNIR_HULL, 'quantity' => 7, 'totalPrice' => 39_600_000.0]],
            [self::SLEIPNIR_HULL => 0.25],
            true,
            new CourierHub(self::HUB_SYSTEM_ID, 1_234.5, 500.0),
            self::JUMPS_JITA_TO_HUB,
        );

        self::assertSame([1.75, 2_160, 'ready'], [$quote->volumeCubicMeters, $quote->reward, $quote->quoteStatus]);
    }

    public function testWithoutPurchasesEverythingIsUnknownEvenWhenOnlyAnEmptyPurchaseExists(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::TRITANIUM, 'quantity' => 0, 'totalPrice' => 0.0]],
            [self::TRITANIUM => 0.01],
            true,
            $this->hub(),
            self::JUMPS_JITA_TO_HUB,
        );

        self::assertSame(
            [null, null, null, 'no_purchases', []],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    public function testWithoutFavoriteManufacturingSystemTheRewardIsUnknownAsWorkshopUnknown(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::ZYDRINE, 'quantity' => 200, 'totalPrice' => 278_000.0]],
            [self::ZYDRINE => 0.01],
            false,
            $this->hub(),
            null,
        );

        self::assertSame(
            [2.0, 278_000, null, null, 'workshop_unknown'],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus],
        );
    }

    public function testUnknownVolumeOutranksUnknownWorkshopAndUnknownRoute(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::MORPHITE, 'quantity' => 7, 'totalPrice' => 129_500.0]],
            [],
            false,
            $this->hub(),
            null,
        );

        self::assertSame(
            [null, 129_500, null, 'volume_unknown', [self::MORPHITE]],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    private function hub(): CourierHub
    {
        return new CourierHub(self::HUB_SYSTEM_ID, 1_200.0, 50.0);
    }
}
