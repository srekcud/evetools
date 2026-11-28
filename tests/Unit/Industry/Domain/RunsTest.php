<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Runs::class)]
final class RunsTest extends TestCase
{
    public function testOneRunIsTheSmallestValidNumberOfRuns(): void
    {
        $this->assertSame(1, (new Runs(1))->value);
    }

    public function testKeepsTheNumberOfRunsOfAJob(): void
    {
        $this->assertSame(25, (new Runs(25))->value);
    }

    #[DataProvider('runsBelowOneProvider')]
    public function testRefusesFewerThanOneRun(int $invalidRuns): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Runs($invalidRuns);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function runsBelowOneProvider(): iterable
    {
        yield 'zero run' => [0];
        yield 'negative runs' => [-1];
    }

    #[Group('issue-1450')]
    #[DataProvider('totalRunsUnderCapProvider')]
    public function testSplitsTotalRunsEvenlyUnderTheCapWithTheRemainderOnTheFirstParts(int $totalRuns, int $capRuns, array $expectedRunsPerPart): void
    {
        $parts = Runs::splitUnderCap(new Runs($totalRuns), new Runs($capRuns));

        $this->assertSame($expectedRunsPerPart, array_map(static fn (Runs $part): int => $part->value, $parts));
        $this->assertSame($totalRuns, array_sum($expectedRunsPerPart));
    }

    public static function totalRunsUnderCapProvider(): iterable
    {
        yield 'total below the cap stays one part' => [7, 10, [7]];
        yield 'total equal to the cap stays one part' => [10, 10, [10]];
        yield '28 runs under a cap of 10' => [28, 10, [10, 9, 9]];
        yield '77 runs under a cap of 10' => [77, 10, [10, 10, 10, 10, 10, 9, 9, 9]];
        yield 'total equal to a multiple of the cap' => [20, 10, [10, 10]];
        yield 'cap of 1 run' => [3, 1, [1, 1, 1]];
        yield 'one run above the cap rounds the part count up' => [21, 10, [7, 7, 7]];
    }

    #[Group('issue-1450')]
    public function testRefusesToSplitZeroRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Runs::splitUnderCap(new Runs(0), new Runs(10));
    }
}
