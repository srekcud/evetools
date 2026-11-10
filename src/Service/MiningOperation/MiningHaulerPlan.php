<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningHaulerPlanUnknownReason;

final readonly class MiningHaulerPlan
{
    /**
     * @param list<int> $rawTypeIds
     */
    public function __construct(
        public ?MiningHaulerPlanUnknownReason $unknownReason = null,
        public ?float $inflowM3PerMinute = null,
        public ?float $fillM3 = null,
        public ?float $fillRatio = null,
        public ?float $fullInMinutes = null,
        public ?bool $isFull = null,
        public ?float $transportM3PerMinute = null,
        public ?float $toMoveM3 = null,
        public ?int $trips = null,
        public array $rawTypeIds = [],
    ) {
    }

    public function isKnown(): bool
    {
        return null === $this->unknownReason;
    }
}
