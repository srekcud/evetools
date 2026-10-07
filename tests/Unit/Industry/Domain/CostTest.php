<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Spec R10 (D5), glossary "Coût inconnu": an amount is either known or unknown, never 0 by default.
 * An unknown cost lists what is missing (typeId without adjusted price, absent cost index).
 */
#[CoversClass(Cost::class)]
#[CoversClass(MissingData::class)]
final class CostTest extends TestCase
{
    public function testKnownCostExposesItsAmount(): void
    {
        $cost = Cost::known(new Isk(190000.0));

        $this->assertTrue($cost->isKnown());
        $this->assertSame(190000.0, $cost->amount()->amount);
        $this->assertSame([], $cost->missingData);
    }

    public function testUnknownCostListsTheMissingData(): void
    {
        $cost = Cost::unknown(MissingData::adjustedPrice(35));

        $this->assertFalse($cost->isKnown());
        $this->assertEquals([MissingData::adjustedPrice(35)], $cost->missingData);
        $this->assertSame(35, $cost->missingData[0]->typeId);
    }

    public function testMissingCostIndexIsNotAttachedToATypeId(): void
    {
        $this->assertNull(MissingData::costIndex()->typeId);
        $this->assertNotEquals(MissingData::costIndex(), MissingData::adjustedPrice(35));
    }

    public function testUnknownCostHasNoAmount(): void
    {
        // Reading the amount of an unknown cost is a programming error, never a plausible 0.
        $this->expectException(\LogicException::class);

        Cost::unknown(MissingData::costIndex())->amount();
    }

    public function testSumOfKnownCostsIsKnown(): void
    {
        $total = Cost::known(new Isk(50000.0))->plus(Cost::known(new Isk(140000.0)));

        $this->assertTrue($total->isKnown());
        $this->assertSame(190000.0, $total->amount()->amount);
    }

    public function testSumContainingAnUnknownCostIsUnknown(): void
    {
        $total = Cost::known(new Isk(100000.0))->plus(Cost::unknown(MissingData::costIndex()));

        $this->assertFalse($total->isKnown());
        $this->assertEquals([MissingData::costIndex()], $total->missingData);
    }

    public function testSumIsUnknownWhicheverSideIsUnknown(): void
    {
        $total = Cost::unknown(MissingData::adjustedPrice(35))->plus(Cost::known(new Isk(100000.0)));

        $this->assertFalse($total->isKnown());
        $this->assertEquals([MissingData::adjustedPrice(35)], $total->missingData);
    }

    public function testSumOfUnknownCostsListsEachMissingDataOnce(): void
    {
        $total = Cost::unknown(MissingData::adjustedPrice(35), MissingData::adjustedPrice(36))
            ->plus(Cost::unknown(MissingData::adjustedPrice(35), MissingData::costIndex()));

        $this->assertEquals(
            [MissingData::adjustedPrice(35), MissingData::adjustedPrice(36), MissingData::costIndex()],
            $total->missingData,
        );
    }
}
