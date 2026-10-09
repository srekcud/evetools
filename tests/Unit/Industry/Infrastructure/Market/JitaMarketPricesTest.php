<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Market;

use App\Constant\EveConstants;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
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
        $this->assertSame(800.0 / 150.0, $unitPrices[self::TRITANIUM]->amount);
    }

    public function testEachTypeIsWeightedByItsOwnQuantityToBuy(): void
    {
        $this->theForgeOrdersByTypeId = [
            self::TRITANIUM => [$this->sellOrder(5.0, 100), $this->sellOrder(6.0, 100)],
            self::PYERITE => [$this->sellOrder(10.0, 10), $this->sellOrder(20.0, 1_000)],
        ];

        $unitPrices = $this->marketPrices()->unitPricesFor([self::TRITANIUM => 50.0, self::PYERITE => 20.0]);

        $this->assertSame(5.0, $unitPrices[self::TRITANIUM]->amount);
        // 10 units at 10.00 then 10 units at 20.00: (100 + 200) / 20
        $this->assertSame(15.0, $unitPrices[self::PYERITE]->amount);
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
        $this->assertSame(506.0 / 101.0, $unitPrices[self::TRITANIUM]->amount);
        // 1 unit at 10.00
        $this->assertSame(10.0, $unitPrices[self::PYERITE]->amount);
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
        $this->assertSame(5.0, $unitPrices[self::TRITANIUM]->amount);
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

    private function marketPrices(): JitaMarketPrices
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
            cache: new ArrayAdapter(),
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
