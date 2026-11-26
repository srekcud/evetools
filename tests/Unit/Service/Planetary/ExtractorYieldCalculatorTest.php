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

    public function testYieldBetweenSumsTheCyclesEndingInsideTheWindow(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $yield = $this->calculator(0.012, 0.8)->yieldBetween(4400, 1800, $install, $install->modify('+48 hours'), $install->modify('+1 hour'), $install->modify('+3 hours'));

        self::assertEqualsWithDelta(32136.0578, $yield, self::DELTA);
    }

    public function testYieldBetweenIsCappedAtTheEndOfTheProgram(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $yield = $this->calculator(0.012, 0.8)->yieldBetween(4400, 1800, $install, $install->modify('+2 hours'), $install, $install->modify('+10 hours'));

        self::assertEqualsWithDelta(33609.8286, $yield, self::DELTA);
    }

    public function testYieldBetweenOfAWindowWithoutAnyCycleEndIsZeroAndKnown(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $yield = $this->calculator(0.012, 0.8)->yieldBetween(4400, 1800, $install, $install->modify('+48 hours'), $install->modify('+31 minutes'), $install->modify('+59 minutes'));

        self::assertSame(0.0, $yield);
    }

    public function testYieldBetweenIsUnknownWithoutInstallTimeExpiryTimeOrFactors(): void
    {
        $moment = new \DateTimeImmutable('2026-11-24 12:00:00');
        $later = $moment->modify('+24 hours');
        $calculator = $this->calculator(0.012, 0.8);

        self::assertNull($calculator->yieldBetween(4400, 1800, null, $later, $moment, $later));
        self::assertNull($calculator->yieldBetween(4400, 1800, $moment, null, $moment, $later));
        self::assertNull($calculator->yieldBetween(4400, 1800, $moment, $moment, $moment, $later));

        $withoutFactors = $this->createStub(DgmAttributeTypeRepository::class);
        self::assertNull((new ExtractorYieldCalculator($withoutFactors))->yieldBetween(4400, 1800, $moment, $later, $moment, $later));
    }

    #[Group('issue-1386')]
    public function testFortyEightHourProgramCurveHasOneYieldPerCycleTheirSumAndTheHourlyAverage(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $curve = $this->calculator(0.012, 0.8)->programCurve(4400, 1800, $install, $install->modify('+48 hours'));

        self::assertNotNull($curve);
        self::assertSame(['cycleSeconds', 'yields', 'totalQuantity', 'averagePerHour', 'suggestedDays'], array_keys($curve));
        self::assertSame(1800, $curve['cycleSeconds']);
        self::assertCount(96, $curve['yields']);
        self::assertEqualsWithDelta(8695.6522, $curve['yields'][0], self::DELTA);
        self::assertEqualsWithDelta(8494.2085, $curve['yields'][1], self::DELTA);
        self::assertEqualsWithDelta(3173.3981, $curve['yields'][95], self::DELTA);
        self::assertEqualsWithDelta(497764.1957, $curve['totalQuantity'], self::DELTA);
        self::assertEqualsWithDelta(array_sum($curve['yields']), $curve['totalQuantity'], self::DELTA);
        self::assertEqualsWithDelta(10370.0874, $curve['averagePerHour'], self::DELTA);
    }

    #[Group('issue-1386')]
    public function testProgramCurveYieldsOfTheLastSixHoursOfTheFirstDayMatchTheYieldBetweenOfTheSameWindow(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');
        $calculator = $this->calculator(0.012, 0.8);

        $curve = $calculator->programCurve(4400, 1800, $install, $install->modify('+48 hours'));

        self::assertNotNull($curve);
        self::assertEqualsWithDelta(57035.5843, array_sum(array_slice($curve['yields'], 36, 12)), self::DELTA);
        self::assertEqualsWithDelta(
            57035.5843,
            $calculator->yieldBetween(4400, 1800, $install, $install->modify('+48 hours'), $install->modify('+18 hours'), $install->modify('+24 hours')),
            self::DELTA,
        );
    }

    #[Group('issue-1386')]
    public function testProgramLastingOneCycleAndHalfYieldsItsSingleCycleOverTheRealDuration(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $curve = $this->calculator(0.012, 0.8)->programCurve(4400, 1800, $install, $install->modify('+3000 seconds'));

        self::assertNotNull($curve);
        self::assertCount(1, $curve['yields']);
        self::assertEqualsWithDelta(8695.6522, $curve['totalQuantity'], self::DELTA);
        self::assertEqualsWithDelta(10434.7826, $curve['averagePerHour'], self::DELTA);
    }

    #[Group('issue-1386')]
    public function testSuggestedDaysKeepsTheLastDayStillYieldingHalfOfTheFirstDay(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $curve = $this->calculator(0.012, 0.8)->programCurve(4400, 1800, $install, $install->modify('+48 hours'));

        self::assertNotNull($curve);
        self::assertSame(2, $curve['suggestedDays']);
    }

    #[Group('issue-1386')]
    public function testSuggestedDaysOfASlowlyDecayingExtractorStopsAtTheFirstDayUnderHalfOfTheFirstDay(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $curve = $this->calculator(0.001, 0.0)->programCurve(4400, 1800, $install, $install->modify('+48 hours'));

        self::assertNotNull($curve);
        self::assertSame(11, $curve['suggestedDays']);
    }

    #[Group('issue-1386')]
    public function testSuggestedDaysIsCappedAtFourteenDays(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $curve = $this->calculator(0.0003, 0.0)->programCurve(4400, 1800, $install, $install->modify('+48 hours'));

        self::assertNotNull($curve);
        self::assertSame(14, $curve['suggestedDays']);
    }

    #[Group('issue-1386')]
    public function testSuggestedDaysIsAtLeastOneWhenTheSecondDayYieldsLessThanHalfOfTheFirst(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');

        $curve = $this->calculator(0.5, 0.0)->programCurve(4400, 1800, $install, $install->modify('+48 hours'));

        self::assertNotNull($curve);
        self::assertSame(1, $curve['suggestedDays']);
    }

    #[Group('issue-1386')]
    public function testSuggestedDaysFollowsTheQuantityPerCycleCycleTimeAndFactorsNotTheProgramDuration(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');
        $calculator = $this->calculator(0.012, 0.8);

        $shortProgram = $calculator->programCurve(1000, 3600, $install, $install->modify('+5 hours'));
        $longProgram = $calculator->programCurve(1000, 3600, $install, $install->modify('+96 hours'));

        self::assertNotNull($shortProgram);
        self::assertNotNull($longProgram);
        self::assertCount(5, $shortProgram['yields']);
        self::assertEqualsWithDelta(20604.9015, $shortProgram['totalQuantity'], self::DELTA);
        self::assertEqualsWithDelta(4120.9803, $shortProgram['averagePerHour'], self::DELTA);
        self::assertSame(2, $shortProgram['suggestedDays']);
        self::assertSame(2, $longProgram['suggestedDays']);
    }

    #[Group('issue-1386')]
    public function testProgramCurveIsUnknownWithoutInstallTimeExpiryTimeDurationOrCycle(): void
    {
        $moment = new \DateTimeImmutable('2026-11-24 12:00:00');
        $later = $moment->modify('+24 hours');
        $calculator = $this->calculator(0.012, 0.8);

        self::assertNull($calculator->programCurve(4400, 1800, null, $later));
        self::assertNull($calculator->programCurve(4400, 1800, $moment, null));
        self::assertNull($calculator->programCurve(4400, 1800, $moment, $moment));
        self::assertNull($calculator->programCurve(4400, 1800, $later, $moment));
        self::assertNull($calculator->programCurve(4400, 0, $moment, $later));
        self::assertNull($calculator->programCurve(4400, -1800, $moment, $later));
    }

    #[Group('issue-1386')]
    public function testProgramCurveIsUnknownWithoutSdeFactors(): void
    {
        $moment = new \DateTimeImmutable('2026-11-24 12:00:00');
        $withoutFactors = $this->createStub(DgmAttributeTypeRepository::class);

        self::assertNull((new ExtractorYieldCalculator($withoutFactors))->programCurve(4400, 1800, $moment, $moment->modify('+24 hours')));
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
