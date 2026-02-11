<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class SplitStepInput
{
    #[Assert\Range(min: 2, max: 20)]
    #[Assert\NotNull]
    public int $numberOfJobs;
}
