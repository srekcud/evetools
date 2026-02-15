<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;

class MiningStatsByTypeResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'mining-stats-by-type';

    /** @var array<string, mixed> */
    public array $period = [];

    /** @var MiningTypeStatsResource[] */
    public array $byType = [];
}
