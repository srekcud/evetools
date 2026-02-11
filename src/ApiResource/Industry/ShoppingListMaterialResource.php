<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

class ShoppingListMaterialResource
{
    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $volume;

    public float $totalVolume;

    public ?float $jitaUnitPrice = null;

    public ?float $jitaTotal = null;

    public float $importCost;

    public ?float $jitaWithImport = null;

    public ?float $structureUnitPrice = null;

    public ?float $structureTotal = null;

    public ?string $bestLocation = null;

    public ?float $bestPrice = null;

    public ?float $savings = null;

    public int $purchasedQuantity = 0;

    public int $extraQuantity = 0;
}
