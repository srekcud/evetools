<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\MaterialEfficiency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialEfficiency::class)]
final class MaterialEfficiencyTest extends TestCase
{
    #[DataProvider('validMeLevelProvider')]
    public function testKeepsAMeLevelBetweenZeroAndTen(int $meLevel): void
    {
        $this->assertSame($meLevel, (new MaterialEfficiency($meLevel))->value);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validMeLevelProvider(): iterable
    {
        yield 'ME 0 (reaction, unresearched blueprint)' => [0];
        yield 'ME 2 (invented T2 BPC)' => [2];
        yield 'ME 10 (fully researched)' => [10];
    }

    #[DataProvider('meLevelOutOfRangeProvider')]
    public function testRefusesAMeLevelOutsideZeroToTen(int $meLevel): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MaterialEfficiency($meLevel);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function meLevelOutOfRangeProvider(): iterable
    {
        yield 'below 0' => [-1];
        yield 'above 10' => [11];
    }
}
