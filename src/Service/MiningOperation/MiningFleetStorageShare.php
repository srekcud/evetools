<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningHaulerPlanUnknownReason;

final readonly class MiningFleetStorageShare
{
    public function __construct(
        public ?float $share = null,
        public ?int $deliveredUnits = null,
        public ?int $minedUnits = null,
        public ?MiningHaulerPlanUnknownReason $unknownReason = null,
    ) {
    }
}
