<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

class UpdateMiningOperationValuationInput
{
    public ?string $valuationForm;

    public ?string $valuationMarket;

    public ?string $valuationSide;

    public ?int $structureId;

    public ?string $structureName;

    public ?float $reprocessYield;

    public ?int $boosterCutPercent;

    public ?int $corporationCutPercent;

    public ?string $boosterMemberId;
}
