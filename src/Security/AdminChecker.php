<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Admins are identified by the eveCharacterId of their main character, never by name:
 * a character name can be renamed or reused, the EVE character ID cannot.
 */
final readonly class AdminChecker
{
    /** @var list<string> */
    private array $adminEveCharacterIds;

    /**
     * @param string $adminCharacterIds comma-separated EVE character IDs (ADMIN_CHARACTER_IDS), empty means no admin
     */
    public function __construct(string $adminCharacterIds)
    {
        // Empty entries (blank value, stray commas) can never equal a character ID, so they need no filtering.
        $this->adminEveCharacterIds = array_map(trim(...), explode(',', $adminCharacterIds));
    }

    public function isAdmin(User $user): bool
    {
        $mainCharacter = $user->getMainCharacter();
        if (null === $mainCharacter) {
            return false;
        }

        return \in_array((string) $mainCharacter->getEveCharacterId(), $this->adminEveCharacterIds, true);
    }
}
