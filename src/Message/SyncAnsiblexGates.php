<?php

declare(strict_types=1);

namespace App\Message;

class SyncAnsiblexGates
{
    public function __construct(
        private readonly string $characterId,
    ) {
    }

    public function getCharacterId(): string
    {
        return $this->characterId;
    }
}
