<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperationCharacter;

final readonly class MiningCharacterValuation
{
    public function __construct(
        public MiningOperationCharacter $operationCharacter,
        public MinedQuantity $quantity,
        public ?int $minedValue,
    ) {
    }
}
