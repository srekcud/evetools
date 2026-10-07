<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncCharacterPlanetaryColonies
{
    public function __construct(
        public string $characterId,
    ) {
    }
}
