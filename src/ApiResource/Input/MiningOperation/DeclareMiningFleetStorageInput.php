<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

class DeclareMiningFleetStorageInput
{
    public ?string $delivery = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $quantities = null;
}
