<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\Constraints as Assert;

class MarkBomLineInput
{
    #[Assert\NotNull]
    public ?string $state = null;

    #[Assert\NotNull]
    public ?int $runs = null;
}
