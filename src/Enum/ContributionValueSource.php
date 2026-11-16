<?php

declare(strict_types=1);

namespace App\Enum;

enum ContributionValueSource: string
{
    case WalletPurchase = 'wallet_purchase';
    case JitaPrice = 'jita_price';
    case LaunchPrice = 'launch_price';
    case Donation = 'donation';

    public function drawsOnUnclaimedStock(): bool
    {
        return self::LaunchPrice === $this || self::Donation === $this;
    }
}
