<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Character;
use App\Enum\CharacterRepairState;
use App\Repository\CharacterRepository;
use App\Service\ESI\AuthenticationService;

class CharacterRepairLister
{
    public function __construct(
        private readonly CharacterRepository $characters,
    ) {
    }

    /**
     * @return list<CharacterToRepair>
     */
    public function all(): array
    {
        return $this->repairsAmong($this->characters->findAllWithToken());
    }

    /**
     * @param iterable<Character> $characters
     *
     * @return list<CharacterToRepair>
     */
    public function repairsAmong(iterable $characters): array
    {
        $entries = [];
        foreach ($characters as $character) {
            $entry = $this->entryOf($character);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (CharacterToRepair $first, CharacterToRepair $second): int => [
            $first->state === CharacterRepairState::Invalid ? 0 : 1,
            $first->sinceAt,
            $first->characterName,
        ] <=> [
            $second->state === CharacterRepairState::Invalid ? 0 : 1,
            $second->sinceAt,
            $second->characterName,
        ]);

        return $entries;
    }

    private function entryOf(Character $character): ?CharacterToRepair
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return null;
        }

        $missingScopes = array_values(array_diff(AuthenticationService::getRequiredScopes(), $token->getScopes()));
        $invalid = !$character->isAuthValid();
        if (!$invalid && $missingScopes === []) {
            return null;
        }

        $user = $character->getUser();

        return new CharacterToRepair(
            $character->getEveCharacterId(),
            $character->getName(),
            $user?->getId()?->toRfc4122(),
            $user?->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            $invalid ? CharacterRepairState::Invalid : CharacterRepairState::MissingScopes,
            $missingScopes,
            $token->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
