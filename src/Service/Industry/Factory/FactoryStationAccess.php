<?php

declare(strict_types=1);

namespace App\Service\Industry\Factory;

use App\Enum\StructureAccessStatus;

final readonly class FactoryStationAccess
{
    public function __construct(
        public int $characterId,
        public string $characterName,
        public StructureAccessStatus $status,
    ) {
    }
}
