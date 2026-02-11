<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreatePurchaseInput
{
    /** Wallet transaction UUID to link (null for manual entry) */
    public ?string $transactionId = null;

    /** Required for manual entry */
    public ?int $typeId = null;

    #[Assert\Positive]
    public ?int $quantity = null;

    #[Assert\PositiveOrZero]
    public ?float $unitPrice = null;
}
