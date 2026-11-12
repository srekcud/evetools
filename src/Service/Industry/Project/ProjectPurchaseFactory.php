<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectPurchase;

final class ProjectPurchaseFactory
{
    private const string WALLET_SOURCE = 'esi_wallet';

    public static function fromTransaction(CachedWalletTransaction $transaction, ?int $quantity): IndustryProjectPurchase
    {
        $purchasedQuantity = $quantity ?? $transaction->getQuantity();

        return (new IndustryProjectPurchase())
            ->setTransaction($transaction)
            ->setTypeId($transaction->getTypeId())
            ->setQuantity($purchasedQuantity)
            ->setUnitPrice($transaction->getUnitPrice())
            ->setTotalPrice($transaction->getUnitPrice() * $purchasedQuantity)
            ->setSource(self::WALLET_SOURCE);
    }
}
