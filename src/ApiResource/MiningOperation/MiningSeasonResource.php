<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;

#[ApiResource(
    shortName: 'MiningSeason',
    description: 'Mined hours and known value of the corporation mining operations over a period',
    operations: [new NotExposed()],
)]
class MiningSeasonResource
{
    public string $period = '';

    public int $operationsCount = 0;

    public int $unknownValueOperationsCount = 0;

    public ?int $knownValue = null;

    public int $knownOperationMinutes = 0;

    public int $playedMinutes = 0;

    public ?int $iskPerHour = null;

    /** @var list<array{name: string, operationsCount: int, minutes: int, minedValue: ?int, hasUnknownValue: bool}> */
    public array $players = [];

    /** @var list<array{shipTypeId: int, shipName: ?string, charactersCount: int, minutes: int, minedValue: ?int, iskPerHour: ?int, isBooster: bool}> */
    public array $ships = [];

    public int $unknownShipCharactersCount = 0;
}
