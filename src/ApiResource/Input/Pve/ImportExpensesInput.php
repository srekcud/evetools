<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

class ImportExpensesInput
{
    /** @var array<array{type: string, typeName: string, price: float, dateIssued: string, contractId?: int, transactionId?: int}> */
    public array $expenses = [];

    /** @var array<array{contractId?: int, transactionId?: int}> */
    public array $declined = [];
}
