<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class RecordSaleFromContractInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $contractId = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $characterId = null;
}
