<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\MarketPrices;

final class RecordingMarketPrices implements MarketPrices
{
    /** @var list<array<int, float>> */
    public array $requests = [];

    public function __construct(private readonly MarketPrices $prices)
    {
    }

    public function unitPricesFor(array $quantitiesByTypeId): array
    {
        $this->requests[] = $quantitiesByTypeId;

        return $this->prices->unitPricesFor($quantitiesByTypeId);
    }
}
