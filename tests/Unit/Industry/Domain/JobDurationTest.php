<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\JobDuration;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R7 (D7): duration = round(runs × base time × (1 − TE/100) × skill × structure time × rig time modifiers).
 *
 * One single round on the whole job, never a ceil per run multiplied by the runs. No implants.
 * The time modifier is the product skills × structure × rig, composed by the caller like the material modifier.
 */
#[CoversClass(JobDuration::class)]
final class JobDurationTest extends TestCase
{
    #[DataProvider('manufacturingJobProvider')]
    public function testManufacturingJobDurationIsRoundedOnceOnTheWholeJob(
        int $baseTimePerRunSeconds,
        int $runs,
        int $teLevel,
        float $timeModifier,
        int $expectedSeconds,
    ): void {
        $duration = JobDuration::forManufacturingJob(
            $baseTimePerRunSeconds,
            new Runs($runs),
            new TimeEfficiency($teLevel),
            new Multiplier($timeModifier),
        );

        $this->assertSame($expectedSeconds, $duration->seconds);
    }

    /**
     * @return iterable<string, array{int, int, int, float, int}>
     */
    public static function manufacturingJobProvider(): iterable
    {
        yield '4.6 base 102 s, 10 runs, TE 0, structure ×0.85: round(867.0) = 867, not ceil(86.7) × 10 = 870' => [102, 10, 0, 0.85, 867];
        yield 'no bonus: runs × base time' => [102, 10, 0, 1.0, 1020];
        yield 'TE 20 only: 10 × 600 × 0.8 = 4 800' => [600, 10, 20, 1.0, 4800];

        // 10 × 600 × 0.8 (TE 20) × 0.6137 (skills all V, two science skills) × 0.85 (Raitaru) × 0.496 (T2 time rig, nullsec)
        // = 1 241.93 -> 1 242. A ceil per run would give 1 250, a round per run 1 240.
        yield 'TE 20, skills, Raitaru and T2 time rig in nullsec: round(1 241.93) = 1 242' => [600, 10, 20, 0.6137 * 0.85 * 0.496, 1242];

        yield 'rounds down below half: round(2.4) = 2' => [3, 1, 20, 1.0, 2];
    }

    #[DataProvider('reactionJobProvider')]
    public function testReactionJobDurationIgnoresTimeEfficiency(
        int $baseTimePerRunSeconds,
        int $runs,
        float $timeModifier,
        int $expectedSeconds,
    ): void {
        // A reaction formula has no TE; its signature does not accept one.
        $duration = JobDuration::forReactionJob($baseTimePerRunSeconds, new Runs($runs), new Multiplier($timeModifier));

        $this->assertSame($expectedSeconds, $duration->seconds);
    }

    /**
     * @return iterable<string, array{int, int, float, int}>
     */
    public static function reactionJobProvider(): iterable
    {
        // 10 × 10 800 × 0.8 (Reactions V) × 0.75 (Tatara) × 0.736 (T2 time rig 24 % × 1.1 nullsec) = 47 692.8 -> 47 693.
        yield 'Tatara, T2 time rig in nullsec, Reactions V: round(47 692.8) = 47 693' => [10800, 10, 0.8 * 0.75 * 0.736, 47693];
        yield 'Athanor has no time bonus (#71): runs × base time' => [10800, 10, 1.0, 108000];
    }

    public function testRefusesANegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JobDuration(-1);
    }

    public function testRefusesANegativeBaseTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JobDuration::forReactionJob(-1, new Runs(1), Multiplier::one());
    }

    public function testRefusesANegativeBaseTimeEvenWhenTheJobRoundsToZero(): void
    {
        // round(1 × −1 × 1 × 0.000001) = 0: the base time itself is checked, not only the resulting duration.
        $this->expectException(\InvalidArgumentException::class);

        JobDuration::forManufacturingJob(-1, new Runs(1), new TimeEfficiency(0), new Multiplier(0.000001));
    }

    public function testZeroDurationIsAccepted(): void
    {
        $this->assertSame(0, (new JobDuration(0))->seconds);
    }

    public function testZeroBaseTimeGivesAZeroDuration(): void
    {
        $duration = JobDuration::forManufacturingJob(0, new Runs(10), new TimeEfficiency(0), Multiplier::one());

        $this->assertSame(0, $duration->seconds);
    }
}
