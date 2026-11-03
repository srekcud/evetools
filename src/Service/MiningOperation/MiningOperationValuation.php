<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningShareBlocker;

final readonly class MiningOperationValuation
{
    /**
     * @param array<int, ?float>               $unitPricesByTypeId
     * @param list<int>                        $missingPriceTypeIds
     * @param list<MiningParticipantValuation> $participants
     * @param list<MiningShareBlocker>         $freezeBlockers
     * @param ?list<MiningShareBlocker>        $completionBlockers
     */
    public function __construct(
        public bool $frozen,
        public \DateTimeImmutable $pricesReadAt,
        public ?\DateTimeImmutable $pricesSyncedAt,
        public array $unitPricesByTypeId,
        public array $missingPriceTypeIds,
        public ?int $totalValue,
        public ?MiningShareSplit $split,
        public ?int $durationSeconds,
        public ?float $iskPerHour,
        public array $participants,
        public array $freezeBlockers,
        public ?array $completionBlockers = null,
    ) {
    }
}
