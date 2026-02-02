<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

class ShoppingListItemResource
{
    public int $typeId = 0;

    public string $typeName = '';

    public int $quantity = 0;

    public float $volume = 0.0;

    public float $totalVolume = 0.0;

    public ?float $jitaPrice = null;

    public ?float $jitaTotal = null;

    public float $importCost = 0.0;

    public ?float $jitaWithImport = null;

    public ?float $structurePrice = null;

    public ?float $structureTotal = null;

    public ?string $bestLocation = null;

    public ?float $bestTotal = null;
}
