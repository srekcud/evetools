<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

class WalletReadingResource
{
    public string $characterName;

    public string $status;

    public ?string $readAt = null;

    public ?string $nextReadAt = null;
}
