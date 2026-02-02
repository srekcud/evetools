<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

use Symfony\Component\Validator\Constraints as Assert;

class CreateExpenseInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['fuel', 'ammo', 'crab_beacon', 'other'])]
    public string $type = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $description = '';

    #[Assert\NotNull]
    #[Assert\Positive]
    public float $amount = 0.0;

    public ?string $date = null;
}
