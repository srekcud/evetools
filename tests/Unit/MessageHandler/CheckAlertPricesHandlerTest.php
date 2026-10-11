<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Constant\EveConstants;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use App\Message\CheckAlertPrices;
use App\MessageHandler\CheckAlertPricesHandler;
use App\Repository\MarketPriceAlertRepository;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Service\MarketAlertService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\StructureMarketService;
use App\Service\StructureMarketSnapshotService;
use App\Tests\Unit\Service\SimulatesJitaMarketEsi;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(CheckAlertPricesHandler::class)]
final class CheckAlertPricesHandlerTest extends TestCase
{
    use SimulatesJitaMarketEsi;

    private const int JITA_STATION = EveConstants::JITA_STATION_ID;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const string SYNC_TYPE = 'alert-prices';

    private ArrayAdapter $marketCache;
    private SyncTracker $syncTracker;

    protected function setUp(): void
    {
        $this->requestedEsiUrls = [];
        $this->marketCache = new ArrayAdapter();
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    public function testCompletesWithoutReadingEsiWhenNoAlertIsActive(): void
    {
        $handler = $this->createHandler($this->alertRepository([], []), []);

        $handler(new CheckAlertPrices());

        $this->assertSame('ok', $this->trackerState()['status']);
        $this->assertSame('No active alerts', $this->trackerState()['message']);
        $this->assertSame([], $this->requestedEsiUrls);
        $this->assertFalse($this->marketCache->getItem('jita_market_prices')->isHit());
    }

    public function testTrackerIsRunningWhileAlertPricesRefresh(): void
    {
        $trackerStatusesDuringRefresh = [];
        $alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $alertRepository->method('getActiveAlertTypeIds')->willReturnCallback(function () use (&$trackerStatusesDuringRefresh): array {
            $trackerStatusesDuringRefresh[] = $this->trackerState()['status'];

            return [self::PYERITE];
        });
        $alertRepository->method('findActiveAlerts')->willReturn([]);
        $handler = $this->createHandler($alertRepository, [
            $this->theForgeOrdersRoute(self::PYERITE) => [[$this->esiSellOrder(self::PYERITE, 9.75, 410, self::JITA_STATION)]],
        ]);

        $handler(new CheckAlertPrices());

        $this->assertSame(['running'], $trackerStatusesDuringRefresh);
        $this->assertSame('ok', $this->trackerState()['status']);
    }

    public function testRefreshesTheAlertTypesThenCompletesWithTheNumberOfTriggeredAlerts(): void
    {
        $this->storeInMarketCache('jita_market_prices', [
            self::TRITANIUM => [['price' => 4.5, 'volume' => 310]],
            self::MEXALLON => [['price' => 58.75, 'volume' => 93]],
        ]);
        $this->storeInMarketCache('jita_market_buy_prices', [self::MEXALLON => [['price' => 52.25, 'volume' => 44]]]);
        $tritaniumBelowAlert = $this->jitaSellAlert(self::TRITANIUM, AlertDirection::Below, 3.9);
        $pyeriteAboveAlert = $this->jitaSellAlert(self::PYERITE, AlertDirection::Above, 10.5);
        $handler = $this->createHandler(
            $this->alertRepository([self::TRITANIUM, self::PYERITE], [$tritaniumBelowAlert, $pyeriteAboveAlert]),
            [
                $this->theForgeOrdersRoute(self::TRITANIUM) => [[
                    $this->esiSellOrder(self::TRITANIUM, 3.875, 1200, self::JITA_STATION),
                    $this->esiBuyOrder(self::TRITANIUM, 3.375, 5300, self::JITA_STATION),
                ]],
                $this->theForgeOrdersRoute(self::PYERITE) => [[
                    $this->esiSellOrder(self::PYERITE, 9.75, 410, self::JITA_STATION),
                    $this->esiBuyOrder(self::PYERITE, 8.625, 1900, self::JITA_STATION),
                ]],
            ],
        );

        $handler(new CheckAlertPrices());

        $tracker = $this->trackerState();
        $this->assertSame('ok', $tracker['status']);
        $this->assertMatchesRegularExpression('/^2 types refreshed in 0(\.\d+)?s, 1 alerts triggered$/', $tracker['message']);
        $this->assertSame(AlertStatus::Triggered, $tritaniumBelowAlert->getStatus());
        $this->assertSame(AlertStatus::Active, $pyeriteAboveAlert->getStatus());
        $this->assertSame([
            self::TRITANIUM => [['price' => 3.875, 'volume' => 1200]],
            self::MEXALLON => [['price' => 58.75, 'volume' => 93]],
            self::PYERITE => [['price' => 9.75, 'volume' => 410]],
        ], $this->marketCache->getItem('jita_market_prices')->get());
        $this->assertSame([$this->theForgeOrdersUrl(self::TRITANIUM), $this->theForgeOrdersUrl(self::PYERITE)], $this->esiUrlsWithoutPage($this->requestedEsiUrls));
    }

    public function testFailsWithTheRefreshErrorWithoutCheckingTheAlerts(): void
    {
        $this->storeInMarketCache('jita_market_prices', [self::TRITANIUM => [['price' => 3.75, 'volume' => 270]]]);
        $tritaniumBelowAlert = $this->jitaSellAlert(self::TRITANIUM, AlertDirection::Below, 3.8);
        $handler = $this->createHandler(
            $this->alertRepository([self::TRITANIUM], [$tritaniumBelowAlert]),
            [$this->theForgeOrdersRoute(self::TRITANIUM) => [new \RuntimeException('ESI unreachable')]],
        );

        $handler(new CheckAlertPrices());

        $this->assertSame('error', $this->trackerState()['status']);
        $this->assertSame('ESI unreachable', $this->trackerState()['message']);
        $this->assertSame(AlertStatus::Active, $tritaniumBelowAlert->getStatus());
    }

    public function testFailsWithTheErrorOfTheAlertCheck(): void
    {
        $alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $alertRepository->method('getActiveAlertTypeIds')->willReturn([self::TRITANIUM]);
        $alertRepository->method('findActiveAlerts')->willThrowException(new \RuntimeException('Alert table locked'));
        $handler = $this->createHandler($alertRepository, [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [[$this->esiSellOrder(self::TRITANIUM, 3.875, 1200, self::JITA_STATION)]],
        ]);

        $handler(new CheckAlertPrices());

        $this->assertSame('error', $this->trackerState()['status']);
        $this->assertSame('Alert table locked', $this->trackerState()['message']);
    }

    #[Group('market-audit-6')]
    public function testCurrentlyTriggersAnAlertOnTheStalePriceOfATypeWhoseRefreshFailedMarketAudit6(): void
    {
        $this->storeInMarketCache('jita_market_prices', [self::TRITANIUM => [['price' => 3.75, 'volume' => 270]]]);
        $this->storeInMarketCache('jita_market_buy_prices', [self::TRITANIUM => [['price' => 3.125, 'volume' => 960]]]);
        $tritaniumBelowAlert = $this->jitaSellAlert(self::TRITANIUM, AlertDirection::Below, 3.8);
        $handler = $this->createHandler(
            $this->alertRepository([self::TRITANIUM], [$tritaniumBelowAlert]),
            [$this->theForgeOrdersRoute(self::TRITANIUM) => [$this->esiServiceUnavailable()]],
        );

        $handler(new CheckAlertPrices());

        $tracker = $this->trackerState();
        $this->assertSame('ok', $tracker['status']);
        $this->assertMatchesRegularExpression('/^1 types refreshed in 0(\.\d+)?s, 1 alerts triggered$/', $tracker['message']);
        $this->assertSame(AlertStatus::Triggered, $tritaniumBelowAlert->getStatus());
        $this->assertSame([$this->theForgeOrdersUrl(self::TRITANIUM)], $this->esiUrlsWithoutPage($this->requestedEsiUrls));
    }

    private function createHandler(MarketPriceAlertRepository $alertRepository, array $esiResponsesByRoute): CheckAlertPricesHandler
    {
        $jitaMarketService = $this->createJitaMarketServiceOnSimulatedEsi(
            $esiResponsesByRoute,
            $this->marketCache,
            $this->createStub(Connection::class),
        );

        return new CheckAlertPricesHandler(
            alertRepository: $alertRepository,
            jitaMarketService: $jitaMarketService,
            marketAlertService: $this->createMarketAlertService($alertRepository, $jitaMarketService),
            logger: new NullLogger(),
            syncTracker: $this->syncTracker,
        );
    }

    private function createMarketAlertService(MarketPriceAlertRepository $alertRepository, JitaMarketService $jitaMarketService): MarketAlertService
    {
        $mercurePublisher = new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger());

        return new MarketAlertService(
            $alertRepository,
            $jitaMarketService,
            new StructureMarketService(
                $this->createStub(EsiClient::class),
                $this->createStub(CacheItemPoolInterface::class),
                new NullLogger(),
                $mercurePublisher,
                new StructureMarketSnapshotService($this->createStub(Connection::class), new NullLogger()),
            ),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $mercurePublisher,
        );
    }

    private function alertRepository(array $activeAlertTypeIds, array $activeAlerts): MarketPriceAlertRepository
    {
        $alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $alertRepository->method('getActiveAlertTypeIds')->willReturn($activeAlertTypeIds);
        $alertRepository->method('findActiveAlerts')->willReturn($activeAlerts);

        return $alertRepository;
    }

    private function jitaSellAlert(int $typeId, AlertDirection $direction, float $threshold): MarketPriceAlert
    {
        return (new MarketPriceAlert())
            ->setUser(new User())
            ->setTypeId($typeId)
            ->setTypeName('Type ' . $typeId)
            ->setDirection($direction)
            ->setThreshold($threshold)
            ->setPriceSource(AlertPriceSource::JitaSell)
            ->setStatus(AlertStatus::Active);
    }

    private function trackerState(): array
    {
        foreach ($this->syncTracker->getAll() as $state) {
            if ($state['type'] === self::SYNC_TYPE) {
                return $state;
            }
        }

        $this->fail('No tracker state for ' . self::SYNC_TYPE);
    }

    private function storeInMarketCache(string $key, mixed $value): void
    {
        $cacheItem = $this->marketCache->getItem($key);
        $cacheItem->set($value);
        $this->marketCache->save($cacheItem);
    }
}
