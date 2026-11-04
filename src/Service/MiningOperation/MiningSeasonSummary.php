<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonSummary
{
    /**
     * @param list<array{name: string, operationsCount: int, minutes: int, minedValue: ?int, hasUnknownValue: bool}> $players
     * @param list<array{shipTypeId: int, charactersCount: int, minutes: int, minedValue: ?int, iskPerHour: ?int, isBooster: bool}> $ships
     */
    public function __construct(
        public int $operationsCount,
        public int $unknownValueOperationsCount,
        public ?int $knownValue,
        public int $knownOperationMinutes,
        public int $playedMinutes,
        public ?int $iskPerHour,
        public array $players,
        public array $ships = [],
        public int $unknownShipCharactersCount = 0,
    ) {
    }
}
