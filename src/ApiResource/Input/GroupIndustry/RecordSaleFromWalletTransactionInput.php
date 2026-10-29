<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class RecordSaleFromWalletTransactionInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $transactionId;

    #[Assert\Range(min: 1, max: RecordSaleInput::MAXIMUM_QUANTITY)]
    public ?int $quantity = null;
}
