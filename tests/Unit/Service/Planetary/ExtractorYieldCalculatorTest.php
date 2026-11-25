<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Sde\DgmAttributeType;
use App\Repository\Sde\DgmAttributeTypeRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-1381')]
#[CoversClass(ExtractorYieldCalculator::class)]
final class ExtractorYieldCalculatorTest extends TestCase
{
    private const int DECAY_ATTRIBUTE_ID = 1683;
    private const int NOISE_ATTRIBUTE_ID = 1687;
    private const float DELTA = 0.001;

    public function testFirstCyclesOfAThirtyMinuteProgramFollowTheCcpFormula(): void
    {
        $yields = $this->calculator(0.012, 0.8)->cycleYields(6965, 1800, 95);

        self::assertNotNull($yields);
        self::assertCount(95, $yields);
        self::assertEqualsWithDelta(24261.6594, $yields[0], self::DELTA);
        self::assertEqualsWithDelta(20481.1009, $yields[1], self::DELTA);
        self::assertEqualsWithDelta(789365.5893, array_sum($yields), self::DELTA);
    }

    public function testASingleHourCycleYieldsItsOwnFigure(): void
    {
        $yields = $this->calculator(0.012, 0.8)->cycleYields(1000, 3600, 1);

        self::assertNotNull($yields);
        self::assertEqualsWithDelta(6337.1720, $yields[0], self::DELTA);
    }

    public function testWithoutDecayNorNoiseEveryCycleYieldsBarWidthTimesTheBaseQuantity(): void
    {
        $yields = $this->calculator(0.0, 0.0)->cycleYields(6965, 1800, 95);

        self::assertNotNull($yields);
        self::assertEqualsWithDelta(1323350.0, array_sum($yields), self::DELTA);
    }

    public function testProgramDailyAverageDividesTheProgramYieldByItsDurationInDays(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $average = $this->calculator(0.012, 0.8)->programDailyAverage(6965, 1800, $install, $install->modify('+171000 seconds'));

        self::assertEqualsWithDelta(398837.3504, $average, self::DELTA);
    }

    public function testProgramDailyAverageOfADayLongProgramEqualsItsYield(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $average = $this->calculator(0.012, 0.8)->programDailyAverage(4400, 1800, $install, $install->modify('+24 hours'));

        self::assertEqualsWithDelta(313874.1191, $average, self::DELTA);
    }

    public function testProgramShorterThanOneCycleYieldsNothingButIsKnown(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $average = $this->calculator(0.012, 0.8)->programDailyAverage(4400, 1800, $install, $install->modify('+10 minutes'));

        self::assertSame(0.0, $average);
    }

    public function testProgramWithoutDurationIsUnknown(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        self::assertNull($this->calculator(0.012, 0.8)->programDailyAverage(4400, 1800, $install, $install));
    }

    public function testProgramWithoutInstallOrExpiryTimeIsUnknown(): void
    {
        $moment = new \DateTimeImmutable('2026-11-24 12:00:00');
        $calculator = $this->calculator(0.012, 0.8);

        self::assertNull($calculator->programDailyAverage(4400, 1800, null, $moment));
        self::assertNull($calculator->programDailyAverage(4400, 1800, $moment, null));
    }

    public function testMissingDecayAttributeMakesTheYieldUnknown(): void
    {
        $repository = $this->createStub(DgmAttributeTypeRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (mixed $attributeId): ?DgmAttributeType => $attributeId === self::NOISE_ATTRIBUTE_ID ? $this->attribute($attributeId, 0.8) : null,
        );

        self::assertNull((new ExtractorYieldCalculator($repository))->cycleYields(6965, 1800, 95));
    }

    public function testAttributeWithoutDefaultValueMakesTheYieldUnknown(): void
    {
        $repository = $this->createStub(DgmAttributeTypeRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (mixed $attributeId): DgmAttributeType => $this->attribute($attributeId, $attributeId === self::DECAY_ATTRIBUTE_ID ? null : 0.8),
        );

        self::assertNull((new ExtractorYieldCalculator($repository))->cycleYields(6965, 1800, 95));
    }

    private function calculator(float $decayFactor, float $noiseFactor): ExtractorYieldCalculator
    {
        $repository = $this->createStub(DgmAttributeTypeRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (mixed $attributeId): ?DgmAttributeType => match ($attributeId) {
                self::DECAY_ATTRIBUTE_ID => $this->attribute(self::DECAY_ATTRIBUTE_ID, $decayFactor),
                self::NOISE_ATTRIBUTE_ID => $this->attribute(self::NOISE_ATTRIBUTE_ID, $noiseFactor),
                default => null,
            },
        );

        return new ExtractorYieldCalculator($repository);
    }

    private function attribute(int $attributeId, ?float $defaultValue): DgmAttributeType
    {
        return (new DgmAttributeType())->setAttributeId($attributeId)->setDefaultValue($defaultValue);
    }
}
