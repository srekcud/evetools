<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateProjectInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public int $typeId;

    #[Assert\NotNull]
    #[Assert\Positive]
    public int $runs = 1;

    #[Assert\Range(min: 0, max: 10)]
    public int $meLevel = 0;

    #[Assert\Range(min: 0, max: 20)]
    public int $teLevel = 0;

    #[Assert\Positive]
    public float $maxJobDurationDays = 2.0;

    #[Assert\Length(max: 255)]
    public ?string $name = null;
}
