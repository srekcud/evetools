<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class LinkCourierContractInput
{
    #[Assert\Positive]
    public int $contractId;
}
