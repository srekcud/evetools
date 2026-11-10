<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class RemainingCost
{
    public function __construct(
        public ?float $total,
        public int $unknownLineCount,
    ) {
    }
}
