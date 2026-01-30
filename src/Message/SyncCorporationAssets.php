<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncCorporationAssets
{
    public function __construct(
        public int $corporationId,
        public string $triggerCharacterId,
    ) {
    }
}
