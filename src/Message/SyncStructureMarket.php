<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncStructureMarket
{
    public function __construct(
        public int $structureId,
        public string $structureName,
        public string $characterId,
    ) {
    }
}
