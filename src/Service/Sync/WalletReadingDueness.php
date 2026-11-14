<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\CharacterWalletReading;

final class WalletReadingDueness
{
    public const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    /**
     * @param iterable<Character> $characters
     *
     * @return array<string, Character>
     */
    public static function walletCharactersById(iterable $characters): array
    {
        $walletCharacters = [];
        foreach ($characters as $character) {
            $characterId = $character->getId()?->toRfc4122();
            if ($characterId === null || $character->getEveToken()?->hasScope(self::WALLET_SCOPE) !== true) {
                continue;
            }

            $walletCharacters[$characterId] = $character;
        }

        return $walletCharacters;
    }

    /**
     * @param array<string, Character>      $walletCharacters
     * @param list<CharacterWalletReading> $readings
     *
     * @return array<string, Character>
     */
    public static function notDueAmong(array $walletCharacters, array $readings, \DateTimeImmutable $now): array
    {
        $notDueIds = [];
        foreach ($readings as $reading) {
            $characterId = $reading->getCharacter()->getId()?->toRfc4122();
            if ($characterId !== null && $reading->getNextReadAt() > $now) {
                $notDueIds[$characterId] = true;
            }
        }

        return array_intersect_key($walletCharacters, $notDueIds);
    }
}
