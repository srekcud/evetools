<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class ShoppingListResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /** @var ShoppingListMaterialResource[] */
    public array $materials = [];

    public ?int $structureId = null;

    public ?string $structureName = null;

    public bool $structureAccessible = false;

    public bool $structureFromCache = false;

    public ?string $structureLastSync = null;

    public float $transportCostPerM3;

    public ShoppingListTotalsResource $totals;

    public ?string $priceError = null;
}
