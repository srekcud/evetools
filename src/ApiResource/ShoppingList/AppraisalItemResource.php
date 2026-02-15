<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

class AppraisalItemResource
{
    public int $typeId = 0;

    public string $typeName = '';

    public int $quantity = 0;

    public float $volume = 0.0;

    public float $totalVolume = 0.0;

    public ?float $sellPrice = null;

    public ?float $sellTotal = null;

    public ?float $buyPrice = null;

    public ?float $buyTotal = null;

    public ?float $splitPrice = null;

    public ?float $splitTotal = null;
}
