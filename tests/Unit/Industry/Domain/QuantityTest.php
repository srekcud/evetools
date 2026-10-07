<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Quantity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Quantity::class)]
final class QuantityTest extends TestCase
{
    public function testZeroUnitsIsAValidQuantity(): void
    {
        $this->assertSame(0, (new Quantity(0))->value);
    }

    public function testKeepsANumberOfUnits(): void
    {
        $this->assertSame(10125, (new Quantity(10125))->value);
    }

    public function testRefusesANegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Quantity(-1);
    }
}
