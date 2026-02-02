<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class ScanLootSalesResultResource
{
    public int $scannedTransactions = 0;

    public int $scannedContracts = 0;

    public int $scannedProjects = 0;

    /** @var DetectedSaleResource[] */
    public array $detectedSales = [];

    public bool $noLootTypesConfigured = false;
}
