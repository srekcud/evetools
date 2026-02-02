<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class DetectedExpenseResource
{
    public int $contractId = 0;

    public int $transactionId = 0;

    public string $type = '';

    public int $typeId = 0;

    public string $typeName = '';

    public int $quantity = 0;

    public float $price = 0.0;

    public string $dateIssued = '';

    public string $source = '';
}
