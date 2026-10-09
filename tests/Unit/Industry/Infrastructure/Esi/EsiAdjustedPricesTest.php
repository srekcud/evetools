<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Esi;

use App\Industry\Infrastructure\Esi\EsiAdjustedPrices;
use App\Service\ESI\EsiClient;
use App\Service\Industry\EsiCostIndexService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Adapter of the AdjustedPrices port (spec R5, R10) over the adjusted prices cached by EsiCostIndexService.
 *
 * The real EsiCostIndexService fills an in-memory cache from GET /markets/prices/, simulated at EsiClient::get().
 */
final class EsiAdjustedPricesTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int HOBGOBLIN_II = 2456;

    private EsiCostIndexService $esiCostIndexService;

    protected function setUp(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnMap([
            ['/markets/prices/', null, [], [
                ['type_id' => self::TRITANIUM, 'adjusted_price' => 4.27, 'average_price' => 3.95],
                ['type_id' => self::HOBGOBLIN_II, 'adjusted_price' => 412_345.67, 'average_price' => 398_000.0],
                // The ESI publishes no adjusted price for this type
                ['type_id' => self::PYERITE, 'average_price' => 12.5],
            ]],
        ]);

        $this->esiCostIndexService = new EsiCostIndexService($esiClient, new ArrayAdapter(), new NullLogger());
    }

    public function testAdjustedPriceIsTheOnePublishedByTheEsi(): void
    {
        $this->esiCostIndexService->syncAdjustedPrices();
        $adjustedPrices = new EsiAdjustedPrices($this->esiCostIndexService);

        $this->assertSame(4.27, $adjustedPrices->adjustedPriceOf(self::TRITANIUM)?->amount);
        $this->assertSame(412_345.67, $adjustedPrices->adjustedPriceOf(self::HOBGOBLIN_II)?->amount);
    }

    public function testTypeAbsentFromTheEsiPricesHasNoAdjustedPrice(): void
    {
        $this->esiCostIndexService->syncAdjustedPrices();
        $adjustedPrices = new EsiAdjustedPrices($this->esiCostIndexService);

        $this->assertNull($adjustedPrices->adjustedPriceOf(990_000_001));
    }

    public function testNoAdjustedPriceBeforeTheFirstSync(): void
    {
        $adjustedPrices = new EsiAdjustedPrices($this->esiCostIndexService);

        $this->assertNull($adjustedPrices->adjustedPriceOf(self::TRITANIUM));
    }

    /**
     * EsiCostIndexService::syncAdjustedPrices() caches 0.0 when the ESI entry has no adjusted_price
     * (EsiCostIndexService.php:59, `?? 0.0`). The adapter must not let this plausible default through (R10).
     */
    public function testEsiEntryWithoutAdjustedPriceGivesNoAdjustedPriceRatherThanZero(): void
    {
        $this->esiCostIndexService->syncAdjustedPrices();
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 — the service caches 0.0 for a missing adjusted price
        $this->assertSame(0.0, $this->esiCostIndexService->getAdjustedPrice(self::PYERITE));

        $adjustedPrices = new EsiAdjustedPrices($this->esiCostIndexService);

        $this->assertNull($adjustedPrices->adjustedPriceOf(self::PYERITE));
    }
}
