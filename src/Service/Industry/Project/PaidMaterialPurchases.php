<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PaidMaterialPurchases
{
    public int $quantity;
    public float $totalPrice;

    /**
     * @param list<PaidTransactionUnits> $transactions
     */
    public function __construct(public array $transactions)
    {
        $this->quantity = array_sum(array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $transactions));
        $this->totalPrice = array_sum(array_map(static fn (PaidTransactionUnits $units): float => $units->totalPrice(), $transactions));
    }

    public function averageUnitPrice(): float
    {
        return $this->totalPrice / $this->quantity;
    }

    /**
     * @return list<PaidTransactionUnits>
     */
    public function allocate(int $units): array
    {
        $allocation = [];
        $left = $units;
        foreach ($this->transactions as $available) {
            if ($left <= 0) {
                break;
            }

            $taken = min($left, $available->quantity);
            $allocation[] = new PaidTransactionUnits($available->transaction, $taken);
            $left -= $taken;
        }

        return $allocation;
    }
}
