<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Mining;

use Symfony\Component\Validator\Constraints as Assert;

class FollowRefineryInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $structureId = null;
}
