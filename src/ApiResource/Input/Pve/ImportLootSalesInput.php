<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

class ImportLootSalesInput
{
    /** @var array<array{typeName: string, price: float, dateIssued: string, type?: string, transactionId?: int}> */
    public array $sales = [];

    /** @var array<array{transactionId?: int}> */
    public array $declined = [];
}
