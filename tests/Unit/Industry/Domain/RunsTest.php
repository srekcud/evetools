<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Runs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
}
