<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateGroupProjectInput
{
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Choice(choices: ['published', 'in_progress', 'selling', 'completed'])]
    public ?string $status = null;

    #[Assert\Length(max: 255)]
    public ?string $containerName = null;

    #[Assert\PositiveOrZero]
    public ?float $brokerFeePercent = null;

    #[Assert\PositiveOrZero]
    public ?float $salesTaxPercent = null;
}
