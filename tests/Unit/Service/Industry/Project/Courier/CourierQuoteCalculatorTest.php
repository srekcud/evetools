<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Courier;

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
    private const int JUMPS_JITA_TO_WORKSHOP = 9;

    private CourierQuoteCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CourierQuoteCalculator();
    }

    public function testExampleTritaniumAndZydrineArePricedByVolumeAndJumps(): void
    {
        $quote = $this->calculator->quote(
            [
                ['typeId' => self::TRITANIUM, 'quantity' => 10_000, 'totalPrice' => 41_000.0],
                ['typeId' => self::ZYDRINE, 'quantity' => 100, 'totalPrice' => 139_000.0],
            ],
            [self::TRITANIUM => 0.01, self::ZYDRINE => 0.01],
            true,
            self::JUMPS_JITA_TO_WORKSHOP,
        );

        self::assertSame(
            [101.0, 180_000, 136_350, 9, 'ready', []],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    public function testExampleBulkyPurchaseIsPricedByVolumeAndJumps(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::SLEIPNIR_HULL, 'quantity' => 4, 'totalPrice' => 22_632_500.0]],
            [self::SLEIPNIR_HULL => 238.375],
            true,
            self::JUMPS_JITA_TO_WORKSHOP,
        );

        self::assertSame(
            [953.5, 22_632_500, 1_287_225, 9, 'ready'],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus],
        );
    }

    public function testExampleUnknownRouteKeepsVolumeAndCollateralWithoutReward(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::SLEIPNIR_HULL, 'quantity' => 4, 'totalPrice' => 22_632_500.0]],
            [self::SLEIPNIR_HULL => 238.375],
            true,
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
            self::JUMPS_JITA_TO_WORKSHOP,
        );

        self::assertSame(
            [null, 1_251, null, 9, 'volume_unknown', [self::MORPHITE]],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->jumps, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }

    public function testWithoutPurchasesEverythingIsUnknownEvenWhenOnlyAnEmptyPurchaseExists(): void
    {
        $quote = $this->calculator->quote(
            [['typeId' => self::TRITANIUM, 'quantity' => 0, 'totalPrice' => 0.0]],
            [self::TRITANIUM => 0.01],
            true,
            self::JUMPS_JITA_TO_WORKSHOP,
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
            null,
        );

        self::assertSame(
            [null, 129_500, null, 'volume_unknown', [self::MORPHITE]],
            [$quote->volumeCubicMeters, $quote->collateral, $quote->reward, $quote->quoteStatus, $quote->missingVolumeTypeIds],
        );
    }
}
