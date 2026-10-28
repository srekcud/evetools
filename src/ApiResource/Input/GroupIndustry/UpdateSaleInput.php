<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

class UpdateSaleInput
{
    public ?int $typeId = null;

    public ?string $typeName = null;

    public ?int $quantity = null;

    public ?float $unitPrice = null;

    public ?string $venue = null;

    public ?string $soldAt = null;
}
