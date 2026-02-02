<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

use Symfony\Component\Validator\Constraints as Assert;

class AddTypeInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public int $typeId = 0;
}
