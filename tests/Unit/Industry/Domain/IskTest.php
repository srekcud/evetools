<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Isk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R11 (D1): a float amount >= 0, compared with a 1 ISK tolerance.
 */
#[CoversClass(Isk::class)]
final class IskTest extends TestCase
{
    public function testKeepsTheAmountWithoutRounding(): void
    {
        // Rounding to 2 decimals happens at display only.
        $this->assertSame(188500.125, (new Isk(188500.125))->amount);
    }

    public function testZeroIskIsAValidAmount(): void
    {
        $this->assertSame(0.0, (new Isk(0.0))->amount);
    }

    #[DataProvider('invalidAmountProvider')]
    public function testRefusesANegativeOrNonFiniteAmount(float $invalidAmount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Isk($invalidAmount);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidAmountProvider(): iterable
    {
        yield 'negative' => [-0.01];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[DataProvider('equalWithinOneIskProvider')]
    public function testAmountsWithinOneIskAreEqual(float $amount, float $otherAmount): void
    {
        $this->assertTrue((new Isk($amount))->equals(new Isk($otherAmount)));
        $this->assertTrue((new Isk($otherAmount))->equals(new Isk($amount)));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function equalWithinOneIskProvider(): iterable
    {
        yield 'identical' => [190000.0, 190000.0];
        yield 'less than 1 ISK apart' => [190000.0, 190000.99];
        yield 'exactly 1 ISK apart' => [190000.0, 190001.0];
    }

    #[DataProvider('differentByMoreThanOneIskProvider')]
    public function testAmountsMoreThanOneIskApartDiffer(float $amount, float $otherAmount): void
    {
        $this->assertFalse((new Isk($amount))->equals(new Isk($otherAmount)));
        $this->assertFalse((new Isk($otherAmount))->equals(new Isk($amount)));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function differentByMoreThanOneIskProvider(): iterable
    {
        yield 'just over 1 ISK apart' => [190000.0, 190001.01];
        yield 'install cost today vs expected (spec 4.5)' => [55000.0, 190000.0];
    }
}
