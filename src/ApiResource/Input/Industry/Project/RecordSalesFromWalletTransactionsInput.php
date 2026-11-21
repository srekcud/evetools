<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class RecordSalesFromWalletTransactionsInput
{
    public const int MAXIMUM_TRANSACTIONS_PER_BATCH = 100;

    /** @var list<mixed> */
    #[Assert\Count(min: 1, max: self::MAXIMUM_TRANSACTIONS_PER_BATCH)]
    #[Assert\All([new Assert\Type(RecordSaleFromWalletTransactionInput::class)])]
    #[Assert\Valid]
    public array $transactions = [];

    /** @param array<array-key, mixed> $transactions */
    public function setTransactions(array $transactions): void
    {
        $this->transactions = array_values(array_map(
            static fn (mixed $transaction): mixed => \is_array($transaction) ? RecordSaleFromWalletTransactionInput::fromArray($transaction) : $transaction,
            $transactions,
        ));
    }
}
