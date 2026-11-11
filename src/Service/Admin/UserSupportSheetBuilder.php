<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Character;
use App\Entity\User;
use App\Enum\CharacterTokenState;
use App\Service\ESI\AuthenticationService;

class UserSupportSheetBuilder
{
    public function __construct(
        private readonly UserLastDataReader $lastDataReader,
    ) {
    }

    public function build(User $user): UserSupportSheet
    {
        return new UserSupportSheet(
            $user->getId()?->toRfc4122() ?? throw new \LogicException('A persisted user has an id'),
            $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            $this->charactersOf($user),
            $this->syncLinesOf($user),
        );
    }

    /**
     * @return list<UserSupportCharacter>
     */
    private function charactersOf(User $user): array
    {
        $characters = $user->getCharacters()->toArray();
        $main = $user->getMainCharacter();
        usort($characters, static fn (Character $first, Character $second): int => [$first !== $main ? 1 : 0, $first->getName()]
            <=> [$second !== $main ? 1 : 0, $second->getName()]);

        return array_map(fn (Character $character): UserSupportCharacter => $this->entryOf($character, $character === $main), $characters);
    }

    private function entryOf(Character $character, bool $isMain): UserSupportCharacter
    {
        $token = $character->getEveToken();

        return new UserSupportCharacter(
            $character->getEveCharacterId(),
            $character->getName(),
            $isMain,
            match (true) {
                $token === null => CharacterTokenState::NoToken,
                !$character->isAuthValid() => CharacterTokenState::Invalid,
                $token->isExpiringSoon() => CharacterTokenState::Expiring,
                default => CharacterTokenState::Valid,
            },
            $token?->getAccessTokenExpiresAt()->format(\DateTimeInterface::ATOM),
            $token === null ? [] : array_values(array_diff(AuthenticationService::getRequiredScopes(), $token->getScopes())),
            $character->getLastSyncAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @return list<UserSupportSyncLine>
     */
    private function syncLinesOf(User $user): array
    {
        $lastDataByType = $this->lastDataReader->lastDataByType($user);

        return array_map(
            static fn (string $type): UserSupportSyncLine => new UserSupportSyncLine(
                $type,
                ($lastDataByType[$type] ?? null)?->format(\DateTimeInterface::ATOM),
                UserTriggerableSyncs::isTriggerable($type),
            ),
            UserTriggerableSyncs::TYPES,
        );
    }
}
