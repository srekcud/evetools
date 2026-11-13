<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Assets;

use Symfony\Component\Validator\Constraints as Assert;

class PinContainerInput
{
    #[Assert\Positive]
    public int $itemId;
}
