<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class DetectedLootContractResource
{
    public int $contractId = 0;

    public string $description = '';

    /** @var array<array{typeId: int, typeName: string, quantity: int}> */
    public array $items = [];

    public int $totalQuantity = 0;

    public float $contractPrice = 0.0;

    public float $suggestedPrice = 0.0;

    public string $date = '';

    public string $characterName = '';
}
