<?php

declare(strict_types=1);

namespace App\ApiResource\Input\GroupIndustry;

use Symfony\Component\Validator\Constraints as Assert;

class SubmitContributionInput
{
    private const int MAXIMUM_QUANTITY = 2147483647;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['material', 'job_install', 'bpc', 'line_rental'])]
    public string $type;

    #[Assert\Uuid]
    public ?string $bomItemId = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAXIMUM_QUANTITY)]
    public int $quantity;

    #[Assert\PositiveOrZero]
    public ?float $estimatedValue = null;

    #[Assert\Length(max: 500)]
    public ?string $note = null;
}
