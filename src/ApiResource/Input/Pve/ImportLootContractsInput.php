<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

class ImportLootContractsInput
{
    /** @var array<array{contractId: int, price: float, description: string, date: string, characterId?: int}> */
    public array $contracts = [];

    /** @var array<array{contractId?: int}> */
    public array $declined = [];
}
