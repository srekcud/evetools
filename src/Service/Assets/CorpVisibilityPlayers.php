<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\User;

final class CorpVisibilityPlayers
{
    public static function idOf(User $player): string
    {
        return $player->getId()?->toRfc4122() ?? throw new \LogicException('A player of a corporation must be persisted');
    }

    /**
     * @return array{id: string, name: string}
     */
    public static function describe(User $player): array
    {
        $mainCharacter = $player->getMainCharacter() ?? throw new \LogicException('A player of a corporation must have a main character');

        return [
            'id' => self::idOf($player),
            'name' => $mainCharacter->getName(),
        ];
    }

    /**
     * @param array<int, list<string>> $restrictedDivisions
     * @param list<User> $players
     *
     * @return array<int, list<array{id: string, name: string}>>
     */
    public static function namedByDivision(array $restrictedDivisions, array $players): array
    {
        $namesByPlayerId = [];
        foreach ($players as $player) {
            $description = self::describe($player);
            $namesByPlayerId[$description['id']] = $description;
        }

        $namedByDivision = [];
        foreach ($restrictedDivisions as $division => $playerIds) {
            $namedPlayers = [];
            foreach ($playerIds as $playerId) {
                if (isset($namesByPlayerId[$playerId])) {
                    $namedPlayers[] = $namesByPlayerId[$playerId];
                }
            }
            $namedByDivision[$division] = $namedPlayers;
        }

        return $namedByDivision;
    }
}
