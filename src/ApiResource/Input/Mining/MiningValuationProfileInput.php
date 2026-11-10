<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Mining;

use Symfony\Component\Validator\Constraints as Assert;

class MiningValuationProfileInput
{
    public const float MAX_EXPORT_TAX = 1_000_000.0;

    #[Assert\Choice(choices: ['jita', 'structure'])]
    public ?string $market = null;

    #[Assert\Choice(choices: ['buy', 'sell'])]
    public ?string $side = null;

    #[Assert\Choice(choices: ['raw', 'compressed', 'reprocessed'])]
    public ?string $form = null;

    #[Assert\Positive]
    public ?int $structureId = null;

    #[Assert\Length(max: 255)]
    public ?string $structureName = null;

    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(self::MAX_EXPORT_TAX)]
    public ?float $exportTax = null;

    public bool $clearStructure = false;
}
