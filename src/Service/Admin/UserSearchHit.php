<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class UserSearchHit
{
    public function __construct(
        public string $userId,
        public ?string $mainCharacterName,
        public ?int $mainEveCharacterId,
        public int $characterCount,
        public ?string $lastLoginAt,
    ) {
    }
}
