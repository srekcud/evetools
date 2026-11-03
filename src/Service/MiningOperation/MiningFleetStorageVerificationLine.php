<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningFleetStorageCheck;

final readonly class MiningFleetStorageVerificationLine
{
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public int $expectedQuantity,
        public ?int $foundQuantity,
        public ?int $missingQuantity,
        public MiningFleetStorageCheck $status,
    ) {
    }
}
