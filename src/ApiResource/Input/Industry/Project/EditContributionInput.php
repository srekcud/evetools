<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class EditContributionInput
{
    private const int MAXIMUM_QUANTITY = 2147483647;

    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAXIMUM_QUANTITY)]
    public ?int $quantity = null;

    #[Assert\PositiveOrZero]
    public ?float $estimatedValue = null;

    #[Assert\Length(max: 500)]
    public ?string $note = null;
}
