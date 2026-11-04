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

        foreach ($operations as $operation) {
            if ($operation->value !== null) {
                ++$knownOperationsCount;
                $knownValue += $operation->value;
                $knownOperationMinutes += $operation->minutes;
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
        );
    }
}
