<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class StatsDailyResource
{
    public array $period = [];

    /** @var DailyStatsResource[] */
    public array $daily = [];
}
