<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class AssignUnassignedJobInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public ?string $lineId = null;

    #[Assert\Positive]
    public ?int $runs = null;
}
