<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningFleetStorageDelivery;

final readonly class MiningFleetStorageContribution
{
    /**
     * @param array<int, int>|null $minedByTypeId  null when the start snapshot of a character could not be read
     * @param array<int, int>|null $declaredByTypeId
     */
    public function __construct(
        public ?array $minedByTypeId,
        public MiningFleetStorageDelivery $delivery,
        public ?array $declaredByTypeId,
        public bool $isRecipient,
    ) {
    }
}
