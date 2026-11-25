<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PlanetaryPin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPin::class)]
#[Group('issue-1403')]
final class PlanetaryPinExtractorYieldTest extends TestCase
{
    private const int AQUEOUS_LIQUIDS = 91_001;

    public static function extractorsWithAYield(): iterable
    {
        yield 'a half hour cycle' => [4400, 1800, 8_800.0];
        yield 'a one hour cycle' => [1500, 3600, 1_500.0];
        yield 'a fifteen minute cycle' => [4400, 900, 17_600.0];
        yield 'a cycle that does not divide the hour' => [1000, 2400, 1_500.0];
    }

    #[DataProvider('extractorsWithAYield')]
    public function testTheYieldPerHourIsTheQuantityPerCycleOverTheCycleScaledToOneHour(int $quantityPerCycle, int $cycleSeconds, float $expectedPerHour): void
    {
        $pin = (new PlanetaryPin())
            ->setExtractorProductTypeId(self::AQUEOUS_LIQUIDS)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setExtractorCycleTime($cycleSeconds);

        $this->assertSame($expectedPerHour, $pin->getExtractorYieldPerHour());
    }

    public function testAPinThatIsNotAnExtractorHasNoYieldEvenWithAQuantityAndACycle(): void
    {
        $pin = (new PlanetaryPin())->setExtractorQtyPerCycle(4400)->setExtractorCycleTime(1800);

        $this->assertNull($pin->getExtractorYieldPerHour());
    }

    public function testAnExtractorWithoutQuantityPerCycleHasNoYield(): void
    {
        $pin = (new PlanetaryPin())->setExtractorProductTypeId(self::AQUEOUS_LIQUIDS)->setExtractorCycleTime(1800);

        $this->assertNull($pin->getExtractorYieldPerHour());
    }

    public function testAnExtractorWithoutCycleTimeHasNoYield(): void
    {
        $pin = (new PlanetaryPin())->setExtractorProductTypeId(self::AQUEOUS_LIQUIDS)->setExtractorQtyPerCycle(4400);

        $this->assertNull($pin->getExtractorYieldPerHour());
    }

    public static function unusableCycles(): iterable
    {
        yield 'a zero cycle' => [0];
        yield 'a negative cycle' => [-1800];
    }

    #[DataProvider('unusableCycles')]
    public function testAnExtractorWhoseCycleIsZeroOrNegativeHasNoYield(int $cycleSeconds): void
    {
        $pin = (new PlanetaryPin())
            ->setExtractorProductTypeId(self::AQUEOUS_LIQUIDS)
            ->setExtractorQtyPerCycle(4400)
            ->setExtractorCycleTime($cycleSeconds);

        $this->assertNull($pin->getExtractorYieldPerHour());
    }
}
