<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Service\Industry\Project\PaidMaterialPurchases;
use App\Service\Industry\Project\PaidTransactionUnits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaidMaterialPurchases::class)]
#[Group('issue-1009')]
final class PaidMaterialPurchasesTest extends TestCase
{
    public function testAllocationTakesTheTransactionsInOrderAndSplitsTheLastOne(): void
    {
        $older = $this->buy(60, 5.0);
        $newer = $this->buy(100, 6.0);
        $paid = new PaidMaterialPurchases([new PaidTransactionUnits($older, 60), new PaidTransactionUnits($newer, 100)]);

        $allocation = $paid->allocate(100);

        self::assertSame([[$older, 60], [$newer, 40]], array_map(static fn (PaidTransactionUnits $units): array => [$units->transaction, $units->quantity], $allocation));
    }

    public function testAllocationOfMoreThanTheAvailableUnitsReturnsAllOfThem(): void
    {
        $only = $this->buy(60, 5.0);
        $paid = new PaidMaterialPurchases([new PaidTransactionUnits($only, 60)]);

        self::assertSame([60], array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $paid->allocate(100)));
    }

    public function testAllocationWithinTheFirstTransactionStopsThere(): void
    {
        $paid = new PaidMaterialPurchases([new PaidTransactionUnits($this->buy(200, 5.0), 200), new PaidTransactionUnits($this->buy(50, 6.0), 50)]);

        self::assertSame([30], array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $paid->allocate(30)));
    }

    private function buy(int $quantity, float $unitPrice): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())->setTypeId(34)->setQuantity($quantity)->setUnitPrice($unitPrice)->setIsBuy(true)->setDate(new \DateTimeImmutable());
    }
}
