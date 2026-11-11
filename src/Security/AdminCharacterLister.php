<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\CharacterRepository;

class AdminCharacterLister
{
    public function __construct(
        private readonly AdminChecker $adminChecker,
        private readonly CharacterRepository $characters,
    ) {
    }

    /**
     * @return list<AdminCharacterEntry>
     */
    public function all(): array
    {
        return array_map($this->entryOf(...), $this->adminChecker->listedEveCharacterIds());
    }

    private function entryOf(int $eveCharacterId): AdminCharacterEntry
    {
        $character = $this->characters->findByEveCharacterId($eveCharacterId);
        $mainCharacter = $character?->getUser()?->getMainCharacter();

        return new AdminCharacterEntry(
            $eveCharacterId,
            $character?->getName(),
            $mainCharacter?->getEveCharacterId() === $eveCharacterId,
        );
    }
}
