<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateScannerFavoriteInput
{
    #[Assert\Positive]
    public int $typeId;
}
