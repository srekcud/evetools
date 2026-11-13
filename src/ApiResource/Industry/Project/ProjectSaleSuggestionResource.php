<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

class ProjectSaleSuggestionResource
{
    public string $transactionId;

    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    public string $date;

    public int $sellerCharacterId;

    public string $sellerCharacterName;

    public int $locationId;

    /** @var string[] */
    public array $possibleDuplicateSaleIds = [];
}
