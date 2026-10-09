<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure;

use App\Industry\Application\AdjustedPrices;
use App\Industry\Application\MarketPrices;
use App\Industry\Application\SystemCostIndices;
use App\Industry\Infrastructure\Esi\EsiAdjustedPrices;
use App\Industry\Infrastructure\Esi\EsiSystemCostIndices;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Tests\Integration\IntegrationTestCase;

/**
 * The market and ESI ports of the Application (ADR-0009) are served by their Infrastructure adapters.
 */
final class MarketAndEsiAdaptersWiringTest extends IntegrationTestCase
{
    public function testJitaMarketPricesAreTheMarketPricesOfTheApplication(): void
    {
        $this->assertInstanceOf(JitaMarketPrices::class, self::getContainer()->get(MarketPrices::class));
    }

    public function testEsiAdjustedPricesAreTheAdjustedPricesOfTheApplication(): void
    {
        $this->assertInstanceOf(EsiAdjustedPrices::class, self::getContainer()->get(AdjustedPrices::class));
    }

    public function testEsiSystemCostIndicesAreTheSystemCostIndicesOfTheApplication(): void
    {
        $this->assertInstanceOf(EsiSystemCostIndices::class, self::getContainer()->get(SystemCostIndices::class));
    }
}
