<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Character;
use App\Entity\User;

final readonly class OwnedCharacterId
{
    public static function of(User $user, mixed $candidate): ?int
    {
        if (!is_int($candidate)) {
            return null;
        }

        $isOwned = $user->getCharacters()->exists(
            static fn (int $key, Character $character): bool => $character->getEveCharacterId() === $candidate,
        );

        return $isOwned ? $candidate : null;
    }
}
