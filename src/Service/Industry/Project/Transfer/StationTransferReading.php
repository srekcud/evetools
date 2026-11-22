<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Entity\IndustryProjectStationTransfer;

final readonly class StationTransferReading
{
    public function __construct(
        public IndustryProjectStationTransfer $leg,
        public int $legCount,
        public TransferLegState $state,
    ) {
    }
}
