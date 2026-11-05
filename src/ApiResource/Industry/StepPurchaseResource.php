<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;

class StepPurchaseResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $stepId;

    public int $typeId;

    public ?string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    /** 'esi_wallet' or 'manual' */
    public string $source;

    /** Wallet transaction ID if source is esi_wallet */
    public ?string $transactionId = null;

    public string $createdAt;
}
