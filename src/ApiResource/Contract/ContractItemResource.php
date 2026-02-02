<?php

declare(strict_types=1);

namespace App\ApiResource\Contract;

class ContractItemResource
{
    public int $typeId = 0;

    public string $typeName = '';

    public int $quantity = 0;

    public bool $isIncluded = true;

    public bool $isSingleton = false;

    public ?float $jitaPrice = null;

    public ?float $jitaValue = null;

    public ?float $delvePrice = null;

    public ?float $delveValue = null;
}
