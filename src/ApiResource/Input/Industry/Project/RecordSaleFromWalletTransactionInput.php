<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class RecordSaleFromWalletTransactionInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $transactionId;

    #[Assert\Range(min: 1, max: RecordSaleInput::MAXIMUM_QUANTITY)]
    public ?int $quantity = null;

    /** @param array<array-key, mixed> $values */
    public static function fromArray(array $values): self
    {
        $input = new self();
        $input->transactionId = isset($values['transactionId']) && \is_string($values['transactionId']) ? $values['transactionId'] : '';
        $input->quantity = isset($values['quantity']) && \is_int($values['quantity']) ? $values['quantity'] : null;

        return $input;
    }
}
