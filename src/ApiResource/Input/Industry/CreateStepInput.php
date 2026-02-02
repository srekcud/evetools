<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateStepInput
{
    #[Assert\Positive]
    public ?int $typeId = null;

    #[Assert\Positive]
    public int $runs = 1;

    #[Assert\Range(min: 0, max: 10)]
    public ?int $meLevel = null;

    #[Assert\Range(min: 0, max: 20)]
    public ?int $teLevel = null;

    public ?string $splitGroupId = null;

    public ?string $stepId = null;
}
