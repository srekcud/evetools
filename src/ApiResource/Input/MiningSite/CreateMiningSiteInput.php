<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningSite;

use Symfony\Component\Validator\Constraints as Assert;

class CreateMiningSiteInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100, normalizer: 'trim')]
    public string $label = '';

    #[Assert\Positive]
    public ?int $solarSystemId = null;
}
