<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateStepInput
{
    public ?bool $purchased = null;

    #[Assert\PositiveOrZero]
    public ?int $inStockQuantity = null;

    #[Assert\Positive]
    public ?int $runs = null;

    #[Assert\Range(min: 0, max: 10)]
    public ?int $meLevel = null;

    #[Assert\Range(min: 0, max: 20)]
    public ?int $teLevel = null;

    /** UUID of IndustryStructureConfig */
    public ?string $structureConfigId = null;

    #[Assert\Choice(choices: ['auto', 'manual', 'none'])]
    public ?string $jobMatchMode = null;
}
