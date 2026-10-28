<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Market;

use App\Constant\EveConstants;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Adapter of the MarketPrices port (spec R10) over JitaMarketService.
 *
 * The unit price of a leaf follows the rule Profit Margins uses today for the cost of the materials:
 * the Jita sell price weighted by the quantity to buy, with the on-demand ESI fallback for the types
 * missing from the synced order books (ProfitMarginService.php:78, getWeightedSellPricesWithFallback()).
 *
 * The real JitaMarketService runs on an empty in-memory cache, so every price comes from the on-demand
 * fallback; the ESI is simulated at EsiClient::getBatch().
 */
final class JitaMarketPricesTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private const string SELL_ORDER_BOOK_CACHE_KEY = 'jita_market_prices';

    /** @var array<int, list<array<string, mixed>>|null> The Forge orders returned by the ESI, by typeId; null when the request failed */
    private array $theForgeOrdersByTypeId = [];

    public function testUnitPriceIsTheJitaSellPriceWeightedByTheQuantityToBuy(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => [
                $this->sellOrder(6.0, 100),
                $this->sellOrder(5.0, 100),
                $this->buyOrder(7.0, 1_000),
            ],
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 150.0]);

        // 100 units at 5.00 then 50 units at 6.00: (500 + 300) / 150
        $this->assertSame([self::TRITANIUM], array_keys($unitPrices));
        $this->assertSame(800.0 / 150.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
    }

    public function testEachTypeIsWeightedByItsOwnQuantityToBuy(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => [$this->sellOrder(5.0, 100), $this->sellOrder(6.0, 100)],
            self::PYERITE => [$this->sellOrder(10.0, 10), $this->sellOrder(20.0, 1_000)],
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 50.0, self::PYERITE => 20.0]);

        $this->assertSame(5.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        // 10 units at 10.00 then 10 units at 20.00: (100 + 200) / 20
        $this->assertSame(15.0, $unitPrices[self::PYERITE]->unitPrice->amount);
    }

    /**
     * Quantities are fractional in the marginal cost mode (spec D4b): the depth of the order book is read for
     * the whole units bought, never for 0 unit (which JitaMarketService would leave without price).
     */
    public function testFractionalQuantityToBuyIsWeightedOnTheWholeUnitsBought(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => [$this->sellOrder(5.0, 100), $this->sellOrder(6.0, 100)],
            self::PYERITE => [$this->sellOrder(10.0, 10), $this->sellOrder(20.0, 1_000)],
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 100.5, self::PYERITE => 0.25]);

        // 101 units: 100 at 5.00 then 1 at 6.00
        $this->assertSame(506.0 / 101.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        // 1 unit at 10.00
        $this->assertSame(10.0, $unitPrices[self::PYERITE]->unitPrice->amount);
    }

    public function testTypeWithoutSellOrderIsAbsentFromTheUnitPricesNeverPricedZero(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => [$this->sellOrder(5.0, 100)],
            self::PYERITE => [$this->buyOrder(9.0, 500)],
            self::MEXALLON => [],
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 10.0, self::PYERITE => 10.0, self::MEXALLON => 10.0]);

        $this->assertSame([self::TRITANIUM], array_keys($unitPrices));
        $this->assertSame(5.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
    }

    public function testTypeWhoseOrdersCouldNotBeReadFromTheEsiIsAbsentFromTheUnitPrices(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => [$this->sellOrder(5.0, 100)],
            self::PYERITE => null,
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 10.0, self::PYERITE => 10.0]);

        $this->assertSame([self::TRITANIUM], array_keys($unitPrices));
    }

    public function testOrderBookShorterThanTheQuantityToBuyGivesAPartialPriceWithItsCoverage(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 1_000.0]);

        $this->assertSame(6.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        $this->assertSame(0.3, $unitPrices[self::TRITANIUM]->coverage->fraction);
        $this->assertTrue($unitPrices[self::TRITANIUM]->coverage->isPartial());
    }

    public function testCachedOrderBookShorterThanTheQuantityToBuyGivesTheSamePartialPrice(): void
    {
        $cache = new ArrayAdapter();
        $sellOrderBooks = $cache->getItem(self::SELL_ORDER_BOOK_CACHE_KEY);
        $sellOrderBooks->set([self::TRITANIUM => [
            ['price' => 5.0, 'volume' => 100],
            ['price' => 6.0, 'volume' => 100],
            ['price' => 7.0, 'volume' => 100],
        ]]);
        $cache->save($sellOrderBooks);

        $unitPrices = $this->marketPrices($cache)->unitPricesFor([self::TRITANIUM => 1_000.0]);

        $this->assertSame(6.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        $this->assertSame(0.3, $unitPrices[self::TRITANIUM]->coverage->fraction);
        $this->assertTrue($unitPrices[self::TRITANIUM]->coverage->isPartial());
    }

    public function testOrderBookCoveringTheQuantityToBuyGivesAFullCoverage(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 150.0]);

        $this->assertSame(800.0 / 150.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        $this->assertSame(1.0, $unitPrices[self::TRITANIUM]->coverage->fraction);
        $this->assertFalse($unitPrices[self::TRITANIUM]->coverage->isPartial());
    }

    public function testOrderBookJustSufficientForTheQuantityToBuyGivesAFullCoverage(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 300.0]);

        $this->assertSame(6.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        $this->assertSame(1.0, $unitPrices[self::TRITANIUM]->coverage->fraction);
        $this->assertFalse($unitPrices[self::TRITANIUM]->coverage->isPartial());
    }

    public function testOneUnitAboveTheOrderBookGivesAPartialPriceWithoutAnyThreshold(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 301.0]);

        $this->assertSame(6.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        $this->assertSame(300.0 / 301.0, $unitPrices[self::TRITANIUM]->coverage->fraction);
        $this->assertTrue($unitPrices[self::TRITANIUM]->coverage->isPartial());
    }

    public function testFractionalQuantityToBuyIsCoveredOnTheWholeUnitsAsked(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => $this->threeHundredUnitsOrderBook(),
            self::PYERITE => [$this->sellOrder(11.0, 150), $this->sellOrder(12.0, 150)],
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 300.2, self::PYERITE => 300.0]);

        $this->assertSame(6.0, $unitPrices[self::TRITANIUM]->unitPrice->amount);
        $this->assertSame(300.0 / 301.0, $unitPrices[self::TRITANIUM]->coverage->fraction);
        $this->assertSame(11.5, $unitPrices[self::PYERITE]->unitPrice->amount);
        $this->assertSame(1.0, $unitPrices[self::PYERITE]->coverage->fraction);
    }

    public function testWeightedPriceWithoutCoverageIsAbsentFromTheUnitPricesNeverComplete(): void
    {
        $unitPrices = $this->marketPricesAnswering([
            self::TRITANIUM => ['weightedPrice' => 6.0, 'ordersUsed' => 3],
            self::PYERITE => ['weightedPrice' => 11.5, 'coverage' => 1.0, 'ordersUsed' => 2],
        ])->unitPricesFor([self::TRITANIUM => 100.0, self::PYERITE => 300.0]);

        $this->assertSame([self::PYERITE], array_keys($unitPrices));
        $this->assertSame(11.5, $unitPrices[self::PYERITE]->unitPrice->amount);
    }

    public function testWeightedPriceWithANullCoverageIsAbsentFromTheUnitPricesNeverComplete(): void
    {
        $unitPrices = $this->marketPricesAnswering([
            self::TRITANIUM => ['weightedPrice' => 6.0, 'coverage' => null, 'ordersUsed' => 3],
            self::PYERITE => ['weightedPrice' => 11.5, 'coverage' => 1.0, 'ordersUsed' => 2],
        ])->unitPricesFor([self::TRITANIUM => 100.0, self::PYERITE => 300.0]);

        $this->assertSame([self::PYERITE], array_keys($unitPrices));
    }

    #[DataProvider('coverageWithoutServedUnitProvider')]
    public function testWeightedPriceWithoutAnyServedUnitIsAbsentFromTheUnitPrices(float $coverageWithoutServedUnit): void
    {
        $unitPrices = $this->marketPricesAnswering([
            self::TRITANIUM => ['weightedPrice' => 6.0, 'coverage' => $coverageWithoutServedUnit, 'ordersUsed' => 3],
            self::MEXALLON => ['weightedPrice' => 42.0, 'coverage' => 0.25, 'ordersUsed' => 1],
        ])->unitPricesFor([self::TRITANIUM => 100.0, self::MEXALLON => 40.0]);

        $this->assertSame([self::MEXALLON], array_keys($unitPrices));
        $this->assertSame(0.25, $unitPrices[self::MEXALLON]->coverage->fraction);
    }

    public static function coverageWithoutServedUnitProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.2];
    }

    private function marketPricesAnswering(array $weightedSellPricesByTypeId): JitaMarketPrices
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturn($weightedSellPricesByTypeId);

        return new JitaMarketPrices($jitaMarketService);
    }

    private function threeHundredUnitsOrderBook(): array
    {
        return [$this->sellOrder(7.0, 100), $this->sellOrder(5.0, 100), $this->sellOrder(6.0, 100)];
    }

    private function marketPrices(ArrayAdapter $cache = new ArrayAdapter()): JitaMarketPrices
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getBatch')->willReturnCallback(
            /**
             * @param array<int, string> $endpointsByTypeId
             *
             * @return array<int, list<array<string, mixed>>|null>
             */
            fn (array $endpointsByTypeId): array => array_map(
                fn (int $typeId): ?array => $this->theForgeOrdersByTypeId[$typeId] ?? null,
                array_combine(array_keys($endpointsByTypeId), array_keys($endpointsByTypeId)),
            ),
        );

        return new JitaMarketPrices(new JitaMarketService(
            esiClient: $esiClient,
            cache: $cache,
            connection: $this->createStub(Connection::class),
            logger: new NullLogger(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function sellOrder(float $price, int $volumeRemain): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'location_id' => EveConstants::JITA_STATION_ID, 'is_buy_order' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyOrder(float $price, int $volumeRemain): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'location_id' => EveConstants::JITA_STATION_ID, 'is_buy_order' => true];
    }
}
