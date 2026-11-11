<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\CharacterTokenState;

final readonly class UserSupportCharacter
{
    /**
     * @param list<string> $missingScopes
     */
    public function __construct(
        public int $eveCharacterId,
        public string $name,
        public bool $isMain,
        public CharacterTokenState $tokenState,
        public ?string $accessTokenExpiresAt,
        public array $missingScopes,
        public ?string $lastSyncAt,
    ) {
    }
}
