<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Application\Delivery;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Delivery::class)]
#[Group('issue-1288')]
final class DeliveryTest extends TestCase
{
    private const int TYPE_ID = 16633;
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int MARKET_SYSTEM_ID = 30_001_000;
    private const int WORKSHOP_SYSTEM_ID = 30_002_187;
    private const float VOLUME_PER_UNIT = 0.25;
    private const int QUANTITY = 400;
    private const float BRIDGE_ISK_PER_CUBIC_METER = 12.0;

    public function testSameSystemCostsNothingAndTheCostIsKnownEvenWithoutAHub(): void
    {
        $cost = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, self::VOLUME_PER_UNIT, self::WORKSHOP_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID, null);

        self::assertTrue($cost->isKnown());
        self::assertSame(0.0, $cost->amount()->amount);
    }

    public function testBetweenTwoSystemsTheBridgeChargesTheTotalCubicMetersAtTheBridgeRate(): void
    {
        $cost = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, self::VOLUME_PER_UNIT, self::MARKET_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID, $this->hub());

        self::assertTrue($cost->isKnown());
        self::assertSame(1_200.0, $cost->amount()->amount);
    }

    public function testWithoutAHubBetweenTwoSystemsTheCostIsUnknownWithTheCourierHubMissing(): void
    {
        $cost = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, self::VOLUME_PER_UNIT, self::MARKET_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID, null);

        self::assertEquals(Cost::unknown(MissingData::courierHub()), $cost);
    }

    public function testAMissingVolumeOrSystemMakesTheCostUnknownWithEachMissingData(): void
    {
        $withoutVolume = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, null, self::MARKET_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID, $this->hub());
        $withoutDestination = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, self::VOLUME_PER_UNIT, self::MARKET_SYSTEM_ID, null, $this->hub());
        $withoutOrigin = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, self::VOLUME_PER_UNIT, null, self::WORKSHOP_SYSTEM_ID, null);
        $withoutVolumeAndDestination = Delivery::bridgeCostOf(self::TYPE_ID, self::QUANTITY, null, self::MARKET_SYSTEM_ID, null, $this->hub());

        self::assertEquals(Cost::unknown(MissingData::itemVolume(self::TYPE_ID)), $withoutVolume);
        self::assertEquals(Cost::unknown(MissingData::jumpRoute()), $withoutDestination);
        self::assertEquals(Cost::unknown(MissingData::jumpRoute()), $withoutOrigin);
        self::assertEquals(Cost::unknown(MissingData::itemVolume(self::TYPE_ID), MissingData::jumpRoute()), $withoutVolumeAndDestination);
    }

    private function hub(): CourierHub
    {
        return new CourierHub(self::HUB_SYSTEM_ID, 800.0, self::BRIDGE_ISK_PER_CUBIC_METER);
    }
}
