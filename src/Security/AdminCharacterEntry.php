<?php

declare(strict_types=1);

namespace App\Security;

final readonly class AdminCharacterEntry
{
    public function __construct(
        public int $eveCharacterId,
        public ?string $characterName,
        public bool $hasUser,
    ) {
    }
}
