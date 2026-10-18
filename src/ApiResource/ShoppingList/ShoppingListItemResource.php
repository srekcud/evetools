<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

class ShoppingListItemResource
{
    public int $typeId = 0;

    public string $typeName = '';

    public int $quantity = 0;

    public ?float $volume = null;

    public ?float $totalVolume = null;

    public ?float $jitaPrice = null;

    public ?float $jitaTotal = null;

    public ?float $importCost = null;

    public ?float $jitaWithImport = null;

    public ?float $structurePrice = null;

    public ?float $structureTotal = null;

    public ?string $bestLocation = null;

    public ?float $bestTotal = null;
}
