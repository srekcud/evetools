<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Enum\MinersEstimateStatus;

final readonly class MinersNeeded
{
    /**
     * @param list<array{miners: int, minutes: float, beforeDeadline: bool}> $durations
     */
    public function __construct(
        public MinersEstimateStatus $status,
        public int $knownSiteCount,
        public ?float $workMinerMinutes,
        public ?int $minersNeeded,
        public ?\DateTimeImmutable $deadline,
        public ?float $availableMinutes,
        public array $durations,
    ) {
    }
}
