<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Decryptor;
use App\Industry\Domain\InventionAttempts;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventionAttempts::class)]
#[Group('issue-1205')]
final class InventionAttemptsTest extends TestCase
{
    private const int RUNS_TO_INVENT = 200;
    private const int BASE_RUNS = 10;
    private const float BASE_PROBABILITY = 0.34;

    #[DataProvider('attemptsProvider')]
    public function testAttemptsAreTheRunsToInventOverTheChanceTimesTheRunsOfTheInventedBlueprintCopyRoundedUp(
        int $runsToInvent,
        int $baseRuns,
        float $baseProbability,
        ?Decryptor $decryptor,
        int $expectedAttempts,
    ): void {
        $attempts = InventionAttempts::needed(new Runs($runsToInvent), $baseRuns, $baseProbability, $decryptor);

        $this->assertSame($expectedAttempts, $attempts);
    }

    /**
     * @return iterable<string, array{int, int, float, ?Decryptor, int}>
     */
    public static function attemptsProvider(): iterable
    {
        yield 'no decryptor: ceil(200 / (0.34 x 10)) = 59' => [self::RUNS_TO_INVENT, self::BASE_RUNS, self::BASE_PROBABILITY, null, 59];
        yield 'Attainment x1.8 +4 runs: ceil(200 / (0.612 x 14)) = 24' => [self::RUNS_TO_INVENT, self::BASE_RUNS, self::BASE_PROBABILITY, self::attainment(), 24];
        yield 'Augmentation x0.6 +9 runs: ceil(200 / (0.204 x 19)) = 52' => [self::RUNS_TO_INVENT, self::BASE_RUNS, self::BASE_PROBABILITY, self::augmentation(), 52];
        yield 'no decryptor, Hail L: ceil(15 / (0.4 x 4)) = 10' => [15, 4, 0.4, null, 10];
        yield 'Attainment, Prototype Ammunition L: ceil(50 / (0.9 x 6)) = 10' => [50, 2, 0.5, self::attainment(), 10];
        yield 'exact ratio adds no extra attempt: 29 / (0.29 x 100) = 1' => [29, 100, 0.29, null, 1];
    }

    public function testChanceCappedAtOneIsUsedBeforeDividing(): void
    {
        $attempts = InventionAttempts::needed(new Runs(self::RUNS_TO_INVENT), self::BASE_RUNS, 0.6, self::optimizedAttainment());

        $this->assertSame(17, $attempts);
    }

    private static function attainment(): Decryptor
    {
        return new Decryptor(34202, new Multiplier(1.8), 4, -1, 4);
    }

    private static function augmentation(): Decryptor
    {
        return new Decryptor(34203, new Multiplier(0.6), 9, -2, 2);
    }

    private static function optimizedAttainment(): Decryptor
    {
        return new Decryptor(34207, new Multiplier(1.9), 2, 1, -2);
    }
}
