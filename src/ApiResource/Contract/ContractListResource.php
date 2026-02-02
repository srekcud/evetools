<?php

declare(strict_types=1);

namespace App\ApiResource\Contract;

class ContractListResource
{
    /** @var ContractResource[] */
    public array $contracts = [];

    public int $total = 0;
}
