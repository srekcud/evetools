<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\OrderPositionState;
use App\Enum\OrderPositionUnknownReason;

final readonly class OrderPosition
{
    public function __construct(
        public OrderPositionState $state,
        public ?int $rank,
        public ?float $bestPrice,
        public ?OrderPositionUnknownReason $unknownReason,
        public ?float $suggestedPrice,
    ) {
    }
}
