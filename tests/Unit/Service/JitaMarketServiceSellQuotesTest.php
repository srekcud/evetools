<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(JitaMarketService::class)]
#[Group('issue-538')]
final class JitaMarketServiceSellQuotesTest extends TestCase
{
    use CreatesJitaMarketService;

    private const string ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const string SELL_ORDER_BOOK_KEY = 'jita_market_prices';
    private const int JITA_STATION = 60003760;

    private const int TRITANIUM = 34;
    private const int RIFTER = 587;
    private const int MORPHITE = 11399;

    private const array TRITANIUM_SELL_BOOK = [
        ['price' => 5.0, 'volume' => 100],
        ['price' => 6.0, 'volume' => 100],
        ['price' => 7.0, 'volume' => 100],
    ];
    private const array RIFTER_SELL_BOOK = [
        ['price' => 10.0, 'volume' => 100],
        ['price' => 12.0, 'volume' => 100],
    ];

    private const float PRICE_TOLERANCE = 1e-9;

    private ArrayAdapter $marketCache;
    private int $sellOrderBookReads = 0;
    private array $requestedTypeIds = [];

    protected function setUp(): void
    {
        $this->sellOrderBookReads = 0;
        $this->requestedTypeIds = [];
        $reads = &$this->sellOrderBookReads;
        $this->marketCache = new class($reads) extends ArrayAdapter {
            public function __construct(private int &$reads)
            {
                parent::__construct();
            }

            public function getItem(mixed $key): \Symfony\Component\Cache\CacheItem
            {
                if ($key === 'jita_market_prices') {
                    ++$this->reads;
                }

                return parent::getItem($key);
            }
        };
        $this->seedSellOrderBook([self::TRITANIUM => self::TRITANIUM_SELL_BOOK, self::RIFTER => self::RIFTER_SELL_BOOK]);
    }

    public function testEveryRequestIsWeightedOnItsOwnQuantityAndCoverage(): void
    {
        $quotes = $this->serviceOnEsiServing([])->getWeightedSellQuotesWithFallback([
            ['typeId' => self::TRITANIUM, 'quantity' => 150],
            ['typeId' => self::RIFTER, 'quantity' => 100],
            ['typeId' => self::RIFTER, 'quantity' => 300],
        ]);

        $this->assertCount(3, $quotes);
        $this->assertEqualsWithDelta(800.0 / 150.0, $quotes[0]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertSame(1.0, $quotes[0]['coverage']);
        $this->assertSame(2, $quotes[0]['ordersUsed']);
        $this->assertEqualsWithDelta(10.0, $quotes[1]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertSame(1.0, $quotes[1]['coverage']);
        $this->assertSame(1, $quotes[1]['ordersUsed']);
        $this->assertEqualsWithDelta(11.0, $quotes[2]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertEqualsWithDelta(200.0 / 300.0, $quotes[2]['coverage'], self::PRICE_TOLERANCE);
        $this->assertSame(2, $quotes[2]['ordersUsed']);
    }

    public function testTheSameTypeAskedInTwoQuantitiesGetsTwoDistinctQuotesInTheOrderOfTheRequests(): void
    {
        $quotes = $this->serviceOnEsiServing([])->getWeightedSellQuotesWithFallback([
            ['typeId' => self::TRITANIUM, 'quantity' => 400],
            ['typeId' => self::TRITANIUM, 'quantity' => 300],
            ['typeId' => self::TRITANIUM, 'quantity' => 150],
        ]);

        $this->assertEqualsWithDelta(6.0, $quotes[0]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertEqualsWithDelta(0.75, $quotes[0]['coverage'], self::PRICE_TOLERANCE);
        $this->assertEqualsWithDelta(6.0, $quotes[1]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertSame(1.0, $quotes[1]['coverage']);
        $this->assertEqualsWithDelta(800.0 / 150.0, $quotes[2]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertSame(1.0, $quotes[2]['coverage']);
    }

    public function testTheCachedSellOrderBookIsReadOnceWhateverTheNumberOfRequests(): void
    {
        $this->serviceOnEsiServing([])->getWeightedSellQuotesWithFallback([
            ['typeId' => self::TRITANIUM, 'quantity' => 150],
            ['typeId' => self::TRITANIUM, 'quantity' => 300],
            ['typeId' => self::RIFTER, 'quantity' => 100],
            ['typeId' => self::RIFTER, 'quantity' => 150],
        ]);

        $this->assertSame(1, $this->sellOrderBookReads);
    }

    public function testATypeAbsentFromTheCachedBookIsServedByTheOnDemandEsiFallbackOnItsOwnQuantity(): void
    {
        $service = $this->serviceOnEsiServing([
            self::MORPHITE => [
                $this->esiSellOrder(13000.0, 20),
                $this->esiSellOrder(14000.0, 10),
            ],
        ]);

        $quotes = $service->getWeightedSellQuotesWithFallback([
            ['typeId' => self::MORPHITE, 'quantity' => 25],
            ['typeId' => self::TRITANIUM, 'quantity' => 150],
        ]);

        $this->assertEqualsWithDelta((20 * 13000.0 + 5 * 14000.0) / 25.0, $quotes[0]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertSame(1.0, $quotes[0]['coverage']);
        $this->assertSame(2, $quotes[0]['ordersUsed']);
        $this->assertEqualsWithDelta(800.0 / 150.0, $quotes[1]['weightedPrice'], self::PRICE_TOLERANCE);
        $this->assertSame([self::MORPHITE], $this->requestedTypeIds);
    }

    public function testATypeWithoutAnySellOrderOnEsiHasNoQuote(): void
    {
        $quotes = $this->serviceOnEsiServing([self::MORPHITE => []])->getWeightedSellQuotesWithFallback([
            ['typeId' => self::MORPHITE, 'quantity' => 10],
            ['typeId' => self::RIFTER, 'quantity' => 100],
        ]);

        $this->assertNull($quotes[0]);
        $this->assertEqualsWithDelta(10.0, $quotes[1]['weightedPrice'], self::PRICE_TOLERANCE);
    }

    public function testAZeroQuantityHasNoQuoteWhileTheOtherRequestsKeepTheirs(): void
    {
        $quotes = $this->serviceOnEsiServing([])->getWeightedSellQuotesWithFallback([
            ['typeId' => self::TRITANIUM, 'quantity' => 0],
            ['typeId' => self::TRITANIUM, 'quantity' => 150],
        ]);

        $this->assertNull($quotes[0]);
        $this->assertEqualsWithDelta(800.0 / 150.0, $quotes[1]['weightedPrice'], self::PRICE_TOLERANCE);
    }

    public function testNoRequestMakesNoQuoteAndNoEsiCall(): void
    {
        $quotes = $this->serviceOnEsiServing([])->getWeightedSellQuotesWithFallback([]);

        $this->assertSame([], $quotes);
        $this->assertSame([], $this->requestedTypeIds);
    }

    private function seedSellOrderBook(array $sellOrderBook): void
    {
        $item = $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY);
        $item->set($sellOrderBook);
        $this->marketCache->save($item);
        $this->sellOrderBookReads = 0;
    }

    private function serviceOnEsiServing(array $sellOrdersByTypeId): JitaMarketService
    {
        $esiHttpClient = new MockHttpClient(function (string $method, string $url) use ($sellOrdersByTypeId): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $typeId = (int) ($query['type_id'] ?? 0);
            $this->requestedTypeIds[] = $typeId;
            if (!array_key_exists($typeId, $sellOrdersByTypeId)) {
                $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
            }

            return new MockResponse((string) json_encode($sellOrdersByTypeId[$typeId]), [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        return $this->createJitaMarketService($esiHttpClient, self::ESI_BASE_URL, $this->marketCache);
    }

    private function esiSellOrder(float $price, int $volumeRemain): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'is_buy_order' => false, 'location_id' => self::JITA_STATION];
    }
}
