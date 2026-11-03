<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

class MiningOperationStockpileCoverageResource
{
    public ?string $operationId = null;

    public ?string $state = null;

    public ?string $unknownReason = null;

    public bool $yieldKnown = false;

    public ?float $reprocessYield = null;

    /** @var list<array<string, mixed>> */
    public array $minerals = [];
}
