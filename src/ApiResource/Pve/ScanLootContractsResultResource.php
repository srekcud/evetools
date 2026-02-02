<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class ScanLootContractsResultResource
{
    public int $scannedContracts = 0;

    /** @var DetectedLootContractResource[] */
    public array $detectedContracts = [];

    public float $defaultPricePerItem = 0.0;

    public bool $hasMore = false;

    public int $maxPerScan = 50;
}
