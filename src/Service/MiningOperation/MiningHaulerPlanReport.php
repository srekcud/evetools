<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningHaulerPlanUnknownReason;

final readonly class MiningHaulerPlanReport
{
    /**
     * @param list<int>    $rawTypeIds
     * @param list<string> $contributorNames
     */
    public function __construct(
        public string $operationId,
        public ?MiningHaulerPlanUnknownReason $unknownReason = null,
        public ?string $boostShipName = null,
        public ?string $haulerName = null,
        public ?\DateTimeImmutable $emptiedAt = null,
        public bool $originIsStart = true,
        public ?float $capacityM3 = null,
        public ?float $holdCapacityM3 = null,
        public ?float $share = null,
        public ?float $inflowM3PerMinute = null,
        public ?float $fillM3 = null,
        public ?float $fillRatio = null,
        public ?float $fullInMinutes = null,
        public ?bool $isFull = null,
        public ?float $transportM3PerMinute = null,
        public ?float $toMoveM3 = null,
        public ?int $trips = null,
        public ?\DateTimeImmutable $siteEtaAt = null,
        public array $rawTypeIds = [],
        public array $contributorNames = [],
    ) {
    }

    public function isKnown(): bool
    {
        return null === $this->unknownReason;
    }
}
