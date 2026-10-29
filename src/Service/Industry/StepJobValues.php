<?php

declare(strict_types=1);

namespace App\Service\Industry;

final readonly class StepJobValues
{
    public function __construct(
        public int $runs,
        public int $quantity,
        public int $meLevel,
        public int $teLevel,
    ) {
    }
}
