<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

class RecordSaleFromWalletTransactionInput
{
    public string $transactionId;

    public ?int $quantity = null;
}
