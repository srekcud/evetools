<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\Entity\Character;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait ResolvesSellerOfUser
{
    private function sellerOf(User $user, ?int $sellerCharacterId): ?Character
    {
        if ($sellerCharacterId === null) {
            return null;
        }

        foreach ($user->getCharacters() as $character) {
            if ($character->getEveCharacterId() === $sellerCharacterId) {
                return $character;
            }
        }

        throw new BadRequestHttpException('The seller must be one of your characters.');
    }
}
