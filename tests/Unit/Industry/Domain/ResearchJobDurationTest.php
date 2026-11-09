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
use PHPUnit\Framework\TestCase;

/**
 * Spec #475 R5, R6: duration of an ME or TE research job.
 *
 * round(base time / 6000 × level time (rank 1) × (1 − 5 % × research skill) × (1 − 3 % × Advanced Industry) × structure)
 */
#[CoversClass(ResearchJobDuration::class)]
#[CoversClass(ResearchLevels::class)]
#[CoversClass(MissingData::class)]
final class ResearchJobDurationTest extends TestCase
{
    private const int RANK_ONE_BASE_TIME = 6_000;

    /**
     * @return iterable<string, array{ActivityKind, int, int, int, int, float, int}>
     */
    public static function knownDurationProvider(): iterable
    {
        yield 'ME level 1, rank 1, no skill' => [ActivityKind::ResearchMaterial, 6_000, 1, 0, 0, 1.0, 105];
        yield 'ME level 10, Metallurgy V, Advanced Industry V' => [ActivityKind::ResearchMaterial, 6_000, 10, 5, 5, 1.0, 163_200];
        yield 'TE level 3, rank 2, Research IV' => [ActivityKind::ResearchTime, 12_000, 3, 4, 0, 1.0, 952];
        yield 'ME level 2, structure time bonus' => [ActivityKind::ResearchMaterial, 6_000, 2, 0, 0, 0.8, 200];
        yield 'one single rounding: 595 × 1.5 × 0.9' => [ActivityKind::ResearchMaterial, 9_000, 3, 2, 0, 1.0, 803];
        yield 'half a second rounds up: 595 × 1.5' => [ActivityKind::ResearchMaterial, 9_000, 3, 0, 0, 1.0, 893];
        yield 'zero base time gives a zero duration' => [ActivityKind::ResearchTime, 0, 5, 3, 3, 1.0, 0];
    }

    #[DataProvider('knownDurationProvider')]
    public function testDurationIsTheLevelTimeScaledByRankSkillsAndStructure(
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

    public function testEachLevelOfTheRankOneTableIsUsed(): void
    {
        $expectedSecondsByLevel = [1 => 105, 2 => 250, 3 => 595, 4 => 1_414, 5 => 3_360, 6 => 8_000, 7 => 19_000, 8 => 45_255, 9 => 107_700, 10 => 256_000];

        $secondsByLevel = [];
        foreach (array_keys($expectedSecondsByLevel) as $level) {
            $secondsByLevel[$level] = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::RANK_ONE_BASE_TIME, $level, 0, 0, Multiplier::one())->jobDuration()->seconds;
        }

        $this->assertSame($expectedSecondsByLevel, $secondsByLevel);
    }

    public function testMaterialAndTimeResearchShareTheSameLevelCurve(): void
    {
        $material = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::RANK_ONE_BASE_TIME, 5, 5, 0, Multiplier::one());
        $time = ResearchJobDuration::of(ActivityKind::ResearchTime, self::RANK_ONE_BASE_TIME, 5, 5, 0, Multiplier::one());

        $this->assertSame(2_520, $material->jobDuration()->seconds);
        $this->assertSame(2_520, $time->jobDuration()->seconds);
    }

    #[DataProvider('missingSkillProvider')]
    public function testMissingSkillMakesTheDurationUnknownNeverALevelZeroGuess(
        ActivityKind $activity,
        ?int $researchSkillLevel,
        ?int $advancedIndustryLevel,
        int $expectedMissingSkillTypeId,
    ): void {
        $duration = ResearchJobDuration::of($activity, self::RANK_ONE_BASE_TIME, 4, $researchSkillLevel, $advancedIndustryLevel, Multiplier::one());

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
        $duration = ResearchJobDuration::of(ActivityKind::ResearchTime, self::RANK_ONE_BASE_TIME, 4, null, null, Multiplier::one());

        $this->assertEquals(
            [MissingData::researchSkill(CachedCharacterSkill::SKILL_RESEARCH), MissingData::researchSkill(CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY)],
            $duration->missingData,
        );
    }

    public function testUnknownDurationHasNoJobDuration(): void
    {
        $duration = ResearchJobDuration::of(ActivityKind::ResearchTime, self::RANK_ONE_BASE_TIME, 4, null, 5, Multiplier::one());

        $this->expectException(\LogicException::class);

        $duration->jobDuration();
    }

    public function testSkillLevelZeroIsARealLevelNotAMissingSkill(): void
    {
        $duration = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::RANK_ONE_BASE_TIME, 1, 0, 0, Multiplier::one());

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

        ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::RANK_ONE_BASE_TIME, $targetLevel, $researchSkillLevel, $advancedIndustryLevel, Multiplier::one());
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

        $duration = ResearchJobDuration::of(ActivityKind::ResearchMaterial, self::RANK_ONE_BASE_TIME, $targetLevel, 0, 0, Multiplier::one());

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

        ResearchJobDuration::of(ActivityKind::Manufacturing, self::RANK_ONE_BASE_TIME, 1, 0, 0, Multiplier::one());
    }
}
