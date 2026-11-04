<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MoonTax;

use Symfony\Component\Validator\Constraints as Assert;

class AttributeMoonTaxDonationInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $characterId = null;
}
