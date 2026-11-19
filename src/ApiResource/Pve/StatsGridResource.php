<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class StatsGridResource
{
    /** @var array<string, mixed> */
    public array $period = [];

    /** @var array<string, array{value: float, previous: float, change: float|null, daily: array<string, float>, contributors: list<array{name: string, value: float}>|null}> */
    public array $columns = [];

    /** @var array{value: float, previous: float, change: float|null, daily: array<string, float>} */
    public array $net = ['value' => 0.0, 'previous' => 0.0, 'change' => null, 'daily' => []];

    /** @var array<string, array{lastScanAt: string|null, overdue: bool}> */
    public array $scans = [];
}
