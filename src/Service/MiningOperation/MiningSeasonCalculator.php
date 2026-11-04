<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonCalculator
{
    private const int MINUTES_PER_HOUR = 60;

    /**
     * @param list<MiningSeasonOperation> $operations
     */
    public function summarise(array $operations): MiningSeasonSummary
    {
        $knownOperationsCount = 0;
        $knownValue = 0;
        $knownOperationMinutes = 0;
        $playedMinutes = 0;
        $playersByUserKey = [];
        $shipsByTypeId = [];
        $unknownShipCharactersCount = 0;

        foreach ($operations as $operation) {
            if ($operation->value !== null) {
                ++$knownOperationsCount;
                $knownValue += $operation->value;
                $knownOperationMinutes += $operation->minutes;

                foreach ($operation->characters as $character) {
                    if ($character->shipTypeId === null) {
                        ++$unknownShipCharactersCount;
                        continue;
                    }
                    $shipsByTypeId[$character->shipTypeId] = $this->withCharacter($shipsByTypeId[$character->shipTypeId] ?? null, $character->shipTypeId, $character);
                }
            }

            foreach ($operation->participants as $participant) {
                $playedMinutes += $participant->minutes;
                $player = $playersByUserKey[$participant->userKey] ?? ['name' => $participant->name, 'operationsCount' => 0, 'minutes' => 0, 'knownOperationsCount' => 0, 'minedValue' => 0, 'hasUnknownValue' => false];
                ++$player['operationsCount'];
                $player['minutes'] += $participant->minutes;
                if ($operation->value === null) {
                    $player['hasUnknownValue'] = true;
                } else {
                    ++$player['knownOperationsCount'];
                    $player['minedValue'] += $participant->minedValue ?? throw new \LogicException('A participant of a known operation has a mined value');
                }
                $playersByUserKey[$participant->userKey] = $player;
            }
        }

        $players = array_map(
            static fn (array $player): array => [
                'name' => $player['name'],
                'operationsCount' => $player['operationsCount'],
                'minutes' => $player['minutes'],
                'minedValue' => $player['knownOperationsCount'] === 0 ? null : $player['minedValue'],
                'hasUnknownValue' => $player['hasUnknownValue'],
            ],
            array_values($playersByUserKey),
        );
        usort($players, static fn (array $left, array $right): int => [$right['minutes'], $left['name']] <=> [$left['minutes'], $right['name']]);

        return new MiningSeasonSummary(
            count($operations),
            count($operations) - $knownOperationsCount,
            $knownOperationsCount === 0 ? null : $knownValue,
            $knownOperationMinutes,
            $playedMinutes,
            $knownOperationsCount === 0 || $knownOperationMinutes === 0 ? null : (int) round($knownValue * self::MINUTES_PER_HOUR / $knownOperationMinutes),
            $players,
            $this->shipsOf($shipsByTypeId),
            $unknownShipCharactersCount,
        );
    }

    /**
     * @param ?array{shipTypeId: int, characterKeys: array<string, true>, minutes: int, minedValue: int, isBooster: bool} $ship
     *
     * @return array{shipTypeId: int, characterKeys: array<string, true>, minutes: int, minedValue: int, isBooster: bool}
     */
    private function withCharacter(?array $ship, int $shipTypeId, MiningSeasonCharacter $character): array
    {
        $ship ??= ['shipTypeId' => $shipTypeId, 'characterKeys' => [], 'minutes' => 0, 'minedValue' => 0, 'isBooster' => $character->isBooster];
        $ship['characterKeys'][$character->characterKey] = true;
        $ship['minutes'] += $character->minutes;
        if (!$character->isBooster) {
            $ship['minedValue'] += $character->minedValue ?? throw new \LogicException('A miner of a known operation has a mined value');
        }

        return $ship;
    }

    /**
     * @param array<int, array{shipTypeId: int, characterKeys: array<string, true>, minutes: int, minedValue: int, isBooster: bool}> $shipsByTypeId
     *
     * @return list<array{shipTypeId: int, charactersCount: int, minutes: int, minedValue: ?int, iskPerHour: ?int, isBooster: bool}>
     */
    private function shipsOf(array $shipsByTypeId): array
    {
        $ships = array_map(
            fn (array $ship): array => [
                'shipTypeId' => $ship['shipTypeId'],
                'charactersCount' => count($ship['characterKeys']),
                'minutes' => $ship['minutes'],
                'minedValue' => $ship['isBooster'] ? null : $ship['minedValue'],
                'iskPerHour' => $ship['isBooster'] || $ship['minutes'] === 0 ? null : (int) round($ship['minedValue'] * self::MINUTES_PER_HOUR / $ship['minutes']),
                'isBooster' => $ship['isBooster'],
            ],
            array_values($shipsByTypeId),
        );
        usort($ships, static fn (array $left, array $right): int => match (true) {
            $left['iskPerHour'] === $right['iskPerHour'] => $left['shipTypeId'] <=> $right['shipTypeId'],
            $left['iskPerHour'] === null => 1,
            $right['iskPerHour'] === null => -1,
            default => $right['iskPerHour'] <=> $left['iskPerHour'],
        });

        return $ships;
    }
}
