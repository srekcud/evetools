<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

class ResetDeclinedInput
{
    /** @var int[] */
    public array $keepContractIds = [];

    /** @var int[] */
    public array $keepTransactionIds = [];
}
