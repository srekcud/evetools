<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class ScanContractsResultResource
{
    public int $scannedContracts = 0;

    public int $scannedTransactions = 0;

    /** @var DetectedExpenseResource[] */
    public array $detectedExpenses = [];
}
