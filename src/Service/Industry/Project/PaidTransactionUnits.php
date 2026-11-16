<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;

final readonly class PaidTransactionUnits
{
    public function __construct(
        public CachedWalletTransaction $transaction,
        public int $quantity,
    ) {
    }

    public function totalPrice(): float
    {
        return $this->transaction->getUnitPrice() * $this->quantity;
    }
}
