<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CourierHub::class)]
#[Group('issue-1286')]
final class CourierHubTest extends TestCase
{
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int WORKSHOP_SYSTEM_ID = 30_002_187;
    private const float COURIER_ISK_PER_CUBIC_METER = 1_200.0;
    private const float BRIDGE_ISK_PER_CUBIC_METER = 50.0;

    public function testSiteInTheHubSystemPaysOnlyTheRateFromJita(): void
    {
        $cost = $this->hub()->transportCostOf(100.0, self::HUB_SYSTEM_ID);

        self::assertSame(120_000.0, $this->amountOf($cost));
    }

    public function testSiteInAnotherSystemPaysTheRateFromJitaPlusTheBridgeRate(): void
    {
        $cost = $this->hub()->transportCostOf(100.0, self::WORKSHOP_SYSTEM_ID);

        self::assertSame(125_000.0, $this->amountOf($cost));
    }

    public function testSiteInJitaCostsNothingAndTheCostIsKnown(): void
    {
        $cost = $this->hub()->transportCostOf(100.0, self::JITA_SYSTEM_ID);

        self::assertSame(0.0, $this->amountOf($cost));
    }

    public function testUnknownSiteGivesAnUnknownCostWithTheMissingJumpRouteAndNeverZero(): void
    {
        $cost = $this->hub()->transportCostOf(100.0, null);

        self::assertFalse($cost->isKnown());
        self::assertEquals([MissingData::jumpRoute()], $cost->missingData);
    }

    public function testZeroBridgeRateLeavesOnlyTheRateFromJitaForAnotherSystem(): void
    {
        $cost = (new CourierHub(self::HUB_SYSTEM_ID, self::COURIER_ISK_PER_CUBIC_METER, 0.0))->transportCostOf(100.0, self::WORKSHOP_SYSTEM_ID);

        self::assertSame(120_000.0, $this->amountOf($cost));
    }

    public function testZeroVolumeCostsNothingEvenForAnotherSystem(): void
    {
        $cost = $this->hub()->transportCostOf(0.0, self::WORKSHOP_SYSTEM_ID);

        self::assertSame(0.0, $this->amountOf($cost));
    }

    public function testTheSiteInTheHubSystemIsComparedBySystemWhateverTheHubOfTheUser(): void
    {
        $otherHub = new CourierHub(self::WORKSHOP_SYSTEM_ID, 900.0, 25.0);

        self::assertSame(
            [45_000.0, 46_250.0],
            [
                $this->amountOf($otherHub->transportCostOf(50.0, self::WORKSHOP_SYSTEM_ID)),
                $this->amountOf($otherHub->transportCostOf(50.0, self::HUB_SYSTEM_ID)),
            ],
        );
    }

    private function hub(): CourierHub
    {
        return new CourierHub(self::HUB_SYSTEM_ID, self::COURIER_ISK_PER_CUBIC_METER, self::BRIDGE_ISK_PER_CUBIC_METER);
    }

    private function amountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? $cost->amount()->amount : null;
    }
}
