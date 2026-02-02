<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

class WalletEntryResource
{
    public string $characterId;

    public string $characterName;

    public bool $isMain = false;

    public float $balance;
}
