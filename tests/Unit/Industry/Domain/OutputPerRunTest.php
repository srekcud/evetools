<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\OutputPerRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputPerRun::class)]
final class OutputPerRunTest extends TestCase
{
    public function testOneUnitPerRunIsTheSmallestValidOutputPerRun(): void
    {
        $this->assertSame(1, (new OutputPerRun(1))->value);
    }

    public function testKeepsTwoUnitsPerRun(): void
    {
        $this->assertSame(2, (new OutputPerRun(2))->value);
    }

    public function testKeepsTheOutputPerRunOfAFuelBlockReaction(): void
    {
        $this->assertSame(10_000, (new OutputPerRun(10_000))->value);
    }

    #[DataProvider('outputPerRunBelowOneProvider')]
    public function testRefusesFewerThanOneUnitPerRun(int $invalidOutputPerRun, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new OutputPerRun($invalidOutputPerRun);
    }

    public static function outputPerRunBelowOneProvider(): iterable
    {
        yield 'zero unit per run' => [0, 'A recipe yields at least 1 unit per run, got 0.'];
        yield 'negative units per run' => [-1, 'A recipe yields at least 1 unit per run, got -1.'];
    }
}
