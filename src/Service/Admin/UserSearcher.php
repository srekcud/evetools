<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Repository\UserRepository;

class UserSearcher
{
    public const MINIMUM_TERM_LENGTH = 2;
    public const MAXIMUM_RESULTS = 20;

    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<UserSearchHit>
     */
    public function search(string $term): array
    {
        $trimmedTerm = trim($term);
        if (mb_strlen($trimmedTerm) < self::MINIMUM_TERM_LENGTH) {
            return [];
        }

        return array_map(
            static fn (User $user): UserSearchHit => new UserSearchHit(
                $user->getId()?->toRfc4122() ?? throw new \LogicException('A persisted user has an id'),
                $user->getMainCharacter()?->getName(),
                $user->getMainCharacter()?->getEveCharacterId(),
                $user->getCharacters()->count(),
                $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            ),
            $this->users->searchByCharacter($trimmedTerm, self::MAXIMUM_RESULTS),
        );
    }
}
