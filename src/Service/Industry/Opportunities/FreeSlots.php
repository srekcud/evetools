<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class FreeSlots
{
    public function __construct(
        public int $manufacturing,
        public int $reaction,
    ) {
    }
}
