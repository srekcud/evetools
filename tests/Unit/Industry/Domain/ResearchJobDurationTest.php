<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Entity\CachedCharacterSkill;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ResearchJobDuration;
use App\Industry\Domain\ResearchLevels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchJobDuration::class)]
#[CoversClass(ResearchLevels::class)]
#[CoversClass(MissingData::class)]
#[Group('issue-475')]
final class ResearchJobDurationTest extends TestCase
{
    private const int SDE_BASE_TIME = 2_100;

    /**
     * @return iterable<string, array{ActivityKind, int, int, int, int, float, int}>
     */
    public static function knownDurationProvider(): iterable
    {
        yield 'ME level 1, base time 6000, no skill' => [ActivityKind::ResearchMaterial, 6_000, 1, 0, 0, 1.0, 6_000];
        yield 'ME level 10, base time 6000, Metallurgy V, Advanced Industry V' => [ActivityKind::ResearchMaterial, 6_000, 10, 5, 5, 1.0, 9_325_714];
        yield 'TE level 3, base time 12000, Research IV' => [ActivityKind::ResearchTime, 12_000, 3, 4, 0, 1.0, 54_400];
        yield 'ME level 2, structure time bonus' => [ActivityKind::ResearchMaterial, 6_000, 2, 0, 0, 0.8, 11_429];
        yield 'one single rounding: 100 × 17/3 × 0.95' => [ActivityKind::ResearchMaterial, 100, 3, 1, 0, 1.0, 538];
        yield 'half a second rounds up: 5 × 0.5' => [ActivityKind::ResearchMaterial, 5, 1, 0, 0, 0.5, 3];
        yield 'zero base time gives a zero duration' => [ActivityKind::ResearchTime, 0, 5, 3, 3, 1.0, 0];
        yield 'Rifter ME 0 to 1, no skill' => [ActivityKind::ResearchMaterial, 2_100, 1, 0, 0, 1.0, 2_100];
        yield 'base time 210, ME 0 to 1, no skill' => [ActivityKind::ResearchMaterial, 210, 1, 0, 0, 1.0, 210];
        yield 'Rifter ME 4 to 5, Metallurgy V, Advanced Industry IV' => [ActivityKind::ResearchMaterial, 2_100, 5, 5, 4, 1.0, 44_352];
        yield 'Rifter ME 9 to 10, Metallurgy V, Advanced Industry V' => [ActivityKind::ResearchMaterial, 2_100, 10, 5, 5, 1.0, 3_264_000];
        yield 'Rifter TE 4 to 6, Research IV, Advanced Industry III' => [ActivityKind::ResearchTime, 2_100, 3, 4, 3, 1.0, 8_663];
        yield 'Rifter TE 18 to 20, no skill' => [ActivityKind::ResearchTime, 2_100, 10, 0, 0, 1.0, 5_120_000];
    }

    #[DataProvider('knownDurationProvider')]
    public function testDurationIsTheSdeBaseTimeScaledByTheLevelMultiplierSkillsAndStructure(
        ActivityKind $activity,
        int $baseTimeSeconds,
        int $targetLevel,
        int $researchSkillLevel,
        int $advancedIndustryLevel,
        float $structureTimeModifier,
        int $expectedSeconds,
    ): void {
        $duration = ResearchJobDuration::of($activity, $baseTimeSeconds, $targetLevel, $researchSkillLevel, $advancedIndustryLevel, new Multiplier($structureTimeModifier));

        $this->assertTrue($duration->isKnown());
        $this->assertSame($expectedSeconds, $duration->jobDuration()->seconds);
    }

    public function testEachLevelOfTheResearchTableIsUsed(): void
    {
        $expectedSecondsByLevel = [1 => 2_100, 2 => 5_000, 3 => 11_900, 4 => 28_280, 5 => 67_200, 6 => 160_000, 7 => 380_000, 8 => 905_100, 9 => 2_154_000, 10 => 5_120_000];

        $secondsByLevel = [];
        foreach (array_keys($expectedSecondsByLevel) as $level) {
            $secondsByLevel[$level] = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::SDE_BASE_TIME, $level, 0, 0, Multiplier::one())->jobDuration()->seconds;
        }

        $this->assertSame($expectedSecondsByLevel, $secondsByLevel);
    }

    public function testMaterialAndTimeResearchShareTheSameLevelCurve(): void
    {
        $material = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::SDE_BASE_TIME, 5, 5, 0, Multiplier::one());
        $time = ResearchJobDuration::of(ActivityKind::ResearchTime, self::SDE_BASE_TIME, 5, 5, 0, Multiplier::one());

        $this->assertSame(50_400, $material->jobDuration()->seconds);
        $this->assertSame(50_400, $time->jobDuration()->seconds);
    }

    #[DataProvider('missingSkillProvider')]
    public function testMissingSkillMakesTheDurationUnknownNeverALevelZeroGuess(
        ActivityKind $activity,
        ?int $researchSkillLevel,
        ?int $advancedIndustryLevel,
        int $expectedMissingSkillTypeId,
    ): void {
        $duration = ResearchJobDuration::of($activity, self::SDE_BASE_TIME, 4, $researchSkillLevel, $advancedIndustryLevel, Multiplier::one());

        $this->assertFalse($duration->isKnown());
        $this->assertEquals([MissingData::researchSkill($expectedMissingSkillTypeId)], $duration->missingData);
    }

    /**
     * @return iterable<string, array{ActivityKind, ?int, ?int, int}>
     */
    public static function missingSkillProvider(): iterable
    {
        yield 'Metallurgy missing' => [ActivityKind::ResearchMaterial, null, 5, CachedCharacterSkill::SKILL_METALLURGY];
        yield 'Research missing' => [ActivityKind::ResearchTime, null, 5, CachedCharacterSkill::SKILL_RESEARCH];
        yield 'Advanced Industry missing' => [ActivityKind::ResearchMaterial, 5, null, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY];
    }

    public function testBothSkillsMissingAreBothReported(): void
    {
        $duration = ResearchJobDuration::of(ActivityKind::ResearchTime, self::SDE_BASE_TIME, 4, null, null, Multiplier::one());

        $this->assertEquals(
            [MissingData::researchSkill(CachedCharacterSkill::SKILL_RESEARCH), MissingData::researchSkill(CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY)],
            $duration->missingData,
        );
    }

    public function testUnknownDurationHasNoJobDuration(): void
    {
        $duration = ResearchJobDuration::of(ActivityKind::ResearchTime, self::SDE_BASE_TIME, 4, null, 5, Multiplier::one());

        $this->expectException(\LogicException::class);

        $duration->jobDuration();
    }

    public function testUnknownFactoryKeepsTheMissingDataAndHasNoJobDuration(): void
    {
        $duration = ResearchJobDuration::unknown([MissingData::researcher(), MissingData::researchBaseTime(691)]);

        $this->assertFalse($duration->isKnown());
        $this->assertEquals([MissingData::researcher(), MissingData::researchBaseTime(691)], $duration->missingData);
    }

    public function testResearcherAndResearchBaseTimeMissingDataCarryTheirReasonAndTypeId(): void
    {
        $researcher = MissingData::researcher();
        $researchBaseTime = MissingData::researchBaseTime(691);

        $this->assertSame(['researcher', null, 'research_base_time', 691], [$researcher->reason, $researcher->typeId, $researchBaseTime->reason, $researchBaseTime->typeId]);
    }

    public function testSkillLevelZeroIsARealLevelNotAMissingSkill(): void
    {
        $duration = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::SDE_BASE_TIME, 1, 0, 0, Multiplier::one());

        $this->assertTrue($duration->isKnown());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'level 0' => [0, 0, 0];
        yield 'level 11' => [11, 0, 0];
        yield 'research skill 6' => [3, 6, 0];
        yield 'advanced industry -1' => [3, 0, -1];
    }

    #[DataProvider('invalidInputProvider')]
    public function testLevelOutsideOneToTenOrSkillOutsideZeroToFiveIsRefused(int $targetLevel, int $researchSkillLevel, int $advancedIndustryLevel): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::SDE_BASE_TIME, $targetLevel, $researchSkillLevel, $advancedIndustryLevel, Multiplier::one());
    }

    public function testNegativeBaseTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base time cannot be negative');

        ResearchJobDuration::of(ActivityKind::ResearchTime, -1, 1, 0, 0, Multiplier::one());
    }

    #[DataProvider('researchLevelBoundaryProvider')]
    public function testResearchLevelBoundsAreOneAndTen(int $targetLevel, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $duration = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::SDE_BASE_TIME, $targetLevel, 0, 0, Multiplier::one());

        $this->assertTrue($duration->isKnown());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function researchLevelBoundaryProvider(): iterable
    {
        yield 'level 0' => [0, false];
        yield 'level 1' => [1, true];
        yield 'level 10' => [10, true];
        yield 'level 11' => [11, false];
    }

    public function testLevelCostMultiplierIsTheLevelTimeOverTheLevelOneTime(): void
    {
        $this->assertEqualsWithDelta(1.0, ResearchLevels::costMultiplier(1), 1e-12);
        $this->assertEqualsWithDelta(250 / 105, ResearchLevels::costMultiplier(2), 1e-12);
        $this->assertEqualsWithDelta(256_000 / 105, ResearchLevels::costMultiplier(10), 1e-12);
    }

    public function testAnActivityThatIsNotResearchIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResearchJobDuration::of(ActivityKind::Manufacturing, self::SDE_BASE_TIME, 1, 0, 0, Multiplier::one());
    }
}
