<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Application\JitaPurchase;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(JitaPurchase::class)]
#[Group('issue-1286')]
final class JitaPurchaseImportCostTest extends TestCase
{
    private const int HOBGOBLIN_II = 2_456;
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int WORKSHOP_SYSTEM_ID = 30_002_187;

    public function testTenUnitsOfTwoAndAHalfCubicMetersGoThroughTheHubAndTheBridgeToAnotherWorkshop(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, 2.5, self::WORKSHOP_SYSTEM_ID, $this->hub());

        self::assertSame(31_250.0, $this->amountOf($cost));
    }

    public function testWorkshopInTheHubSystemPaysNoBridge(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, 2.5, self::HUB_SYSTEM_ID, $this->hub());

        self::assertSame(30_000.0, $this->amountOf($cost));
    }

    public function testWorkshopInJitaHasAKnownImportOfZero(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, 2.5, self::JITA_SYSTEM_ID, $this->hub());

        self::assertSame(0.0, $this->amountOf($cost));
    }

    public function testMissingSdeVolumeLeavesTheImportUnknownBecauseOfTheItemVolume(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, null, self::WORKSHOP_SYSTEM_ID, $this->hub());

        self::assertNull($this->amountOf($cost));
        self::assertEquals([MissingData::itemVolume(self::HOBGOBLIN_II)], $cost->missingData);
    }

    public function testWithoutHubTheImportIsUnknownBecauseOfTheCourierHub(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, 2.5, self::WORKSHOP_SYSTEM_ID, null);

        self::assertNull($this->amountOf($cost));
        self::assertEquals([MissingData::courierHub()], $cost->missingData);
    }

    public function testWithoutWorkshopTheImportIsUnknownBecauseOfTheJumpRoute(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, 2.5, null, $this->hub());

        self::assertNull($this->amountOf($cost));
        self::assertEquals([MissingData::jumpRoute()], $cost->missingData);
    }

    public function testWithoutHubNorVolumeBothCausesAreListed(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, null, self::WORKSHOP_SYSTEM_ID, null);

        self::assertNull($this->amountOf($cost));
        self::assertEqualsCanonicalizing([MissingData::courierHub(), MissingData::itemVolume(self::HOBGOBLIN_II)], $cost->missingData);
    }

    public function testWithoutHubNorWorkshopNorVolumeEveryCauseIsListed(): void
    {
        $cost = JitaPurchase::importCostOf(self::HOBGOBLIN_II, 10, null, null, null);

        self::assertEqualsCanonicalizing(
            [MissingData::courierHub(), MissingData::jumpRoute(), MissingData::itemVolume(self::HOBGOBLIN_II)],
            $cost->missingData,
        );
    }

    private function hub(): CourierHub
    {
        return new CourierHub(self::HUB_SYSTEM_ID, 1_200.0, 50.0);
    }

    private function amountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? $cost->amount()->amount : null;
    }
}
