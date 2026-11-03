<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MinedQuantityState;
use App\Enum\MinedQuantityUnknownReason;

final readonly class MinedQuantity
{
    /**
     * @param array<int, int> $quantitiesByTypeId
     */
    public function __construct(
        public MinedQuantityState $state,
        public ?MinedQuantityUnknownReason $unknownReason = null,
        public array $quantitiesByTypeId = [],
    ) {
    }

    public function totalQuantity(): ?int
    {
        return null;
    }
}
