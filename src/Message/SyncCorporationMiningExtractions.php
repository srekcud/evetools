<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncCorporationMiningExtractions
{
    public function __construct(
        public int $corporationId,
    ) {
    }
}
