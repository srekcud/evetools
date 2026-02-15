<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;

class MiningStatsDailyResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'mining-stats-daily';

    /** @var array<string, mixed> */
    public array $period = [];

    /** @var MiningDailyStatsResource[] */
    public array $daily = [];
}
