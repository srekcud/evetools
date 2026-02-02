<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

use Symfony\Component\Validator\Constraints as Assert;

class CreateLootSaleInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $description = '';

    #[Assert\NotNull]
    #[Assert\Positive]
    public float $amount = 0.0;

    public ?string $type = null;

    public ?string $date = null;
}
