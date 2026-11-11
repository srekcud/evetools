<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\CharacterRepairState;

final readonly class CharacterToRepair
{
    /**
     * @param list<string> $missingScopes
     */
    public function __construct(
        public int $eveCharacterId,
        public string $characterName,
        public ?string $userId,
        public ?string $userLastLoginAt,
        public CharacterRepairState $state,
        public array $missingScopes,
        public string $sinceAt,
    ) {
    }
}
