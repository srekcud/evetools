<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

class MiningStatsGridResource
{
    /** @var array<string, mixed> */
    public array $period = [];

    /** @var array<string, array{value: float|null, previous: float|null, change: float|null, quantity: int, daily: array<string, float>|null, contributors: list<array{id: int, name: string, value: float}>|null}> */
    public array $columns = [];

    /** @var array{value: float|null, previous: float|null, change: float|null, daily: array<string, float>, contributors: list<array{id: int, name: string, value: float}>} */
    public array $net = ['value' => null, 'previous' => null, 'change' => null, 'daily' => [], 'contributors' => []];

    /** @var list<int> */
    public array $missingPriceTypeIds = [];

    public bool $corpProjectCountedInPve = false;
}
