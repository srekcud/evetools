<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class WeeklyCapacity
{
    public function __construct(
        public int $quantity,
        public CapacityLimit $limitedBy,
        public int $marketLimit,
        public ?int $capitalLimit,
        public int $slotsLimit,
    ) {
    }
}
