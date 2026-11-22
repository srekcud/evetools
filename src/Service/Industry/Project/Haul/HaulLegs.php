<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final readonly class HaulLegs
{
    /**
     * @param list<HaulLeg> $legs
     * @param list<HaulUnreadable> $unreadable
     */
    public function __construct(
        public array $legs,
        public array $unreadable,
        public ?string $personalStockUnknownReason,
    ) {
    }
}
