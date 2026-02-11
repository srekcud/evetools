<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

class PurchaseSuggestionResource
{
    public int $transactionId;

    public string $transactionUuid;

    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    public string $date;

    public string $characterName;

    public ?int $locationId = null;

    /** Whether already linked to a step in this project */
    public bool $alreadyLinked = false;
}
