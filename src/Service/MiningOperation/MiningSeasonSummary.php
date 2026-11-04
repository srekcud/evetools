<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonSummary
{
    /**
     * @param list<array{name: string, operationsCount: int, minutes: int, minedValue: ?int, hasUnknownValue: bool}> $players
     */
    public function __construct(
        public int $operationsCount,
        public int $unknownValueOperationsCount,
        public ?int $knownValue,
        public int $knownOperationMinutes,
        public int $playedMinutes,
        public ?int $iskPerHour,
        public array $players,
    ) {
    }
}
