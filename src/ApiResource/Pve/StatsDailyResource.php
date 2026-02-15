<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class StatsDailyResource
{
    /** @var array<string, mixed> */
    public array $period = [];

    /** @var DailyStatsResource[] */
    public array $daily = [];
}
