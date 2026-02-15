<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;

class LedgerDailyStatsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'ledger-daily-stats';

    /** @var array<string, mixed> */
    public array $period = [];

    /** @var LedgerDayResource[] */
    public array $daily = [];
}
