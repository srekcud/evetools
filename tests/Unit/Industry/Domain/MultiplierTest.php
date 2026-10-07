<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Multiplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Multiplier::class)]
final class MultiplierTest extends TestCase
{
    public function testNeutralMultiplierIsOne(): void
    {
        $this->assertSame(1.0, Multiplier::one()->value);
    }

    public function testKeepsItsValue(): void
    {
        $this->assertSame(0.9736, (new Multiplier(0.9736))->value);
    }

    public function testStructureAndRigBonusesMultiplyInsteadOfAdding(): void
    {
        // Spec R1/R4, Hail L root job: Raitaru ×0.99, T2 rig 2.4 % × 2.1 nullsec = ×0.9496.
        $structureBonus = new Multiplier(0.99);
        $rigBonus = new Multiplier(0.9496);

        $materialModifier = $structureBonus->times($rigBonus);

        $this->assertEqualsWithDelta(0.940104, $materialModifier->value, 1e-12);
    }

    public function testComposingWithTheNeutralMultiplierKeepsTheValue(): void
    {
        $this->assertSame(0.99, (new Multiplier(0.99))->times(Multiplier::one())->value);
    }

    #[DataProvider('invalidMultiplierProvider')]
    public function testRefusesANonPositiveOrNonFiniteValue(float $invalidValue): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Multiplier($invalidValue);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidMultiplierProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
    }
}
