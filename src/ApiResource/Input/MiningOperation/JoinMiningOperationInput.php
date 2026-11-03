<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class JoinMiningOperationInput
{
    /** @var list<string> */
    #[Assert\Count(min: 1)]
    #[Assert\Unique]
    #[Assert\All([new Assert\Uuid()])]
    public array $characterIds = [];
}
