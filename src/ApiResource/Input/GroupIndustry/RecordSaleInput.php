<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

class RecordSaleInput
{
    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public ?string $venue = null;

    /** ISO 8601 date string, defaults to now if not provided */
    public ?string $soldAt = null;
}
