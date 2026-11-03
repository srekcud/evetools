<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateMiningOperationValuationInput
{
    #[Assert\Choice(choices: ['raw', 'compressed', 'reprocessed'])]
    public ?string $valuationForm;

    #[Assert\Choice(choices: ['jita', 'structure'])]
    public ?string $valuationMarket;

    #[Assert\Choice(choices: ['buy', 'sell'])]
    public ?string $valuationSide;

    #[Assert\Positive]
    public ?int $structureId;

    #[Assert\Length(min: 1, max: 255)]
    public ?string $structureName;

    #[Assert\Positive]
    #[Assert\LessThanOrEqual(1)]
    public ?float $reprocessYield;

    #[Assert\Range(min: 0, max: 100)]
    public ?int $boosterCutPercent;

    #[Assert\Range(min: 0, max: 100)]
    public ?int $corporationCutPercent;

    #[Assert\Uuid]
    public ?string $boosterMemberId;
}
