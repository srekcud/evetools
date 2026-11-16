<?php

declare(strict_types=1);

namespace App\Enum;

enum ContributionValueSource: string
{
    case WalletPurchase = 'wallet_purchase';
    case JitaPrice = 'jita_price';
}
