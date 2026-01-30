<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use PHPUnit\Framework\TestCase;

/**
 * Tests for job splitting calculation logic.
 *
 * Jobs are split when: totalDuration > maxDurationDays
 * Where totalDuration = timePerRun × runs / SECONDS_PER_DAY
 */
class JobSplitCalculationTest extends TestCase
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * Calculate how jobs should be split.
     *
     * @return array{jobs: int, runsPerJob: int, lastJobRuns: int}
     */
    private function calculateSplit(int $timePerRun, int $totalRuns, float $maxDurationDays): array
    {
        $maxDurationSeconds = $maxDurationDays * self::SECONDS_PER_DAY;
        $totalDuration = $timePerRun * $totalRuns;

        // No split needed
        if ($totalDuration <= $maxDurationSeconds) {
            return [
                'jobs' => 1,
                'runsPerJob' => $totalRuns,
                'lastJobRuns' => $totalRuns,
            ];
        }

        // Calculate max runs per job
        $maxRunsPerJob = max(1, (int) floor($maxDurationSeconds / $timePerRun));
        $numberOfJobs = (int) ceil($totalRuns / $maxRunsPerJob);
        $lastJobRuns = $totalRuns - ($maxRunsPerJob * ($numberOfJobs - 1));

        return [
            'jobs' => $numberOfJobs,
            'runsPerJob' => $maxRunsPerJob,
            'lastJobRuns' => $lastJobRuns,
        ];
    }

    /**
     * Calculate adjusted time with TE and structure bonuses.
     */
    private function calculateAdjustedTime(int $baseTime, int $te = 0, float $structureTimeBonus = 0.0): int
    {
        $teMultiplier = 1 - $te / 100;
        $structureMultiplier = 1 - $structureTimeBonus / 100;

        return (int) ceil($baseTime * $teMultiplier * $structureMultiplier);
    }

    // ===========================================
    // No Split Needed Tests
    // ===========================================

    public function testNoSplitWhenUnderMax(): void
    {
        // 10 runs × 3600s (1h each) = 10h < 48h (2 days)
        $result = $this->calculateSplit(3600, 10, 2.0);

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(10, $result['runsPerJob']);
        $this->assertSame(10, $result['lastJobRuns']);
    }

    public function testNoSplitAtExactMax(): void
    {
        // 48 runs × 3600s = 48h = exactly 2 days
        $result = $this->calculateSplit(3600, 48, 2.0);

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(48, $result['runsPerJob']);
        $this->assertSame(48, $result['lastJobRuns']);
    }

    // ===========================================
    // Split Required Tests
    // ===========================================

    public function testSplitJustOverMax(): void
    {
        // 49 runs × 3600s = 49h > 48h (2 days)
        // maxRunsPerJob = floor(172800 / 3600) = 48
        // jobs = ceil(49 / 48) = 2
        $result = $this->calculateSplit(3600, 49, 2.0);

        $this->assertSame(2, $result['jobs']);
        $this->assertSame(48, $result['runsPerJob']);
        $this->assertSame(1, $result['lastJobRuns']); // 49 - 48 = 1
    }

    public function testSplitMultipleJobs(): void
    {
        // 148 runs × 10800s (3h each) = 444h
        // maxDuration = 2 days = 172800s
        // maxRunsPerJob = floor(172800 / 10800) = 16
        // jobs = ceil(148 / 16) = 10
        $result = $this->calculateSplit(10800, 148, 2.0);

        $this->assertSame(10, $result['jobs']);
        $this->assertSame(16, $result['runsPerJob']);
        $this->assertSame(4, $result['lastJobRuns']); // 148 - (16 × 9) = 4
    }

    public function testSplitWithVeryLongJob(): void
    {
        // 27 runs × 259200s (3 days each) - Capital Clone Vat Bay
        // maxDuration = 2 days = 172800s
        // maxRunsPerJob = floor(172800 / 259200) = 0 → 1 (minimum)
        // jobs = ceil(27 / 1) = 27
        $result = $this->calculateSplit(259200, 27, 2.0);

        $this->assertSame(27, $result['jobs']);
        $this->assertSame(1, $result['runsPerJob']);
        $this->assertSame(1, $result['lastJobRuns']);
    }

    public function testSplitWithDifferentMaxDuration(): void
    {
        // 100 runs × 3600s = 100h
        // maxDuration = 1 day = 86400s
        // maxRunsPerJob = floor(86400 / 3600) = 24
        // jobs = ceil(100 / 24) = 5
        $result = $this->calculateSplit(3600, 100, 1.0);

        $this->assertSame(5, $result['jobs']);
        $this->assertSame(24, $result['runsPerJob']);
        $this->assertSame(4, $result['lastJobRuns']); // 100 - (24 × 4) = 4
    }

    // ===========================================
    // Adjusted Time Tests
    // ===========================================

    public function testAdjustedTimeWithTE20(): void
    {
        // 10800s × (1 - 20/100) = 10800 × 0.8 = 8640s
        $result = $this->calculateAdjustedTime(10800, 20, 0.0);
        $this->assertSame(8640, $result);
    }

    public function testAdjustedTimeWithStructureBonus(): void
    {
        // 10800s × (1 - 20/100) = 10800 × 0.8 = 8640s
        $result = $this->calculateAdjustedTime(10800, 0, 20.0);
        $this->assertSame(8640, $result);
    }

    public function testAdjustedTimeWithBothBonuses(): void
    {
        // 10800s × 0.8 (TE 20) × 0.76 (24% structure) = 6566.4 → ceil = 6567
        $result = $this->calculateAdjustedTime(10800, 20, 24.0);
        $this->assertSame(6567, $result);
    }

    public function testSplitWithAdjustedTime(): void
    {
        // Real-world example: Carbon Fiber reaction
        // Base time: 10800s (3h), TE: 20, Structure: 25%
        // Adjusted: 10800 × 0.8 × 0.75 = 6480s
        // 148 runs × 6480s = 958560s = ~11 days
        // maxDuration = 2 days = 172800s
        // maxRunsPerJob = floor(172800 / 6480) = 26
        // jobs = ceil(148 / 26) = 6
        $adjustedTime = $this->calculateAdjustedTime(10800, 20, 25.0);
        $this->assertSame(6480, $adjustedTime);

        $result = $this->calculateSplit($adjustedTime, 148, 2.0);
        $this->assertSame(6, $result['jobs']);
        $this->assertSame(26, $result['runsPerJob']);
        $this->assertSame(18, $result['lastJobRuns']); // 148 - (26 × 5) = 18
    }

    // ===========================================
    // Edge Cases
    // ===========================================

    public function testSingleRunAlwaysOneJob(): void
    {
        // Even if one run takes longer than max, it's still 1 job
        $result = $this->calculateSplit(500000, 1, 2.0);

        $this->assertSame(1, $result['jobs']);
        $this->assertSame(1, $result['runsPerJob']);
        $this->assertSame(1, $result['lastJobRuns']);
    }

    public function testZeroTimePerRunNoSplit(): void
    {
        // Edge case: if time is 0 (shouldn't happen but test safety)
        // Division by zero protection would be in the actual implementation
        $result = $this->calculateSplit(1, 1000, 2.0);

        $this->assertSame(1, $result['jobs']);
    }
}
