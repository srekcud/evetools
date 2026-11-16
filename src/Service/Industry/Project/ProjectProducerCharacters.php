<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\User;

final readonly class ProjectProducerCharacters
{
    /**
     * @param list<int> $eveCharacterIds
     *
     * @return list<int>
     */
    public static function chooseAmongTheCharactersOf(User $owner, array $eveCharacterIds): array
    {
        $ownedIds = array_map(static fn (Character $character): int => $character->getEveCharacterId(), $owner->getCharacters()->toArray());

        if ([] !== array_diff($eveCharacterIds, $ownedIds)) {
            throw new \InvalidArgumentException('Unknown producer character');
        }

        return array_values(array_unique($eveCharacterIds));
    }
}
