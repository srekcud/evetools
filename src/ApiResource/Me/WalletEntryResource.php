<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

class WalletEntryResource
{
    public string $characterId;

    public string $characterName;

    public bool $isMain = false;

    /** Null when the balance could not be read from ESI. */
    public ?float $balance = null;
}
