<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Enum\TransferCarrierKind;

final readonly class TransferLeg
{
    public function __construct(
        public TransferCarrierKind $carrierKind,
        public int $fromSolarSystemId,
        public int $toSolarSystemId,
    ) {
    }
}
