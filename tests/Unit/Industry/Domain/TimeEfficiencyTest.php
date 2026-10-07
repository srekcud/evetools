<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\TimeEfficiency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec §5: TE outside 0..20 or odd is refused at construction.
 */
#[CoversClass(TimeEfficiency::class)]
final class TimeEfficiencyTest extends TestCase
{
    #[DataProvider('validTeLevelProvider')]
    public function testKeepsAnEvenTeLevelBetweenZeroAndTwenty(int $teLevel): void
    {
        $this->assertSame($teLevel, (new TimeEfficiency($teLevel))->value);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validTeLevelProvider(): iterable
    {
        yield 'TE 0 (unresearched blueprint)' => [0];
        yield 'TE 4 (invented T2 BPC)' => [4];
        yield 'TE 20 (fully researched)' => [20];
    }

    #[DataProvider('invalidTeLevelProvider')]
    public function testRefusesATeLevelOutsideZeroToTwentyOrOdd(int $teLevel): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TimeEfficiency($teLevel);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidTeLevelProvider(): iterable
    {
        yield 'below 0' => [-2];
        yield 'above 20' => [22];
        yield 'odd' => [5];
    }
}
