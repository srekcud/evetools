<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateMiningPaymentInput
{
    #[Assert\NotNull]
    public ?bool $paid = null;
}
