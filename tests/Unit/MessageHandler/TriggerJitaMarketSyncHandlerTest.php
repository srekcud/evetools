<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Constant\EveConstants;
use App\Message\TriggerJitaMarketSync;
use App\MessageHandler\TriggerJitaMarketSyncHandler;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Unit\Service\SimulatesJitaMarketEsi;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(TriggerJitaMarketSyncHandler::class)]
final class TriggerJitaMarketSyncHandlerTest extends TestCase
{
    use SimulatesJitaMarketEsi;

    private const int JITA_STATION = EveConstants::JITA_STATION_ID;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const string SYNC_TYPE = 'market-jita';

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

    public function testCompletesTheTrackerWithTheNumberOfTypesWithASellOrderBook(): void
    {
        $trackerStatusesDuringSync = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(function () use (&$trackerStatusesDuringSync): array {
            $trackerStatusesDuringSync[] = $this->trackerState()['status'];

            return [(string) self::TRITANIUM, (string) self::PYERITE];
        });
        $handler = $this->createHandler($connection, [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [[
                $this->esiSellOrder(self::TRITANIUM, 3.875, 1200, self::JITA_STATION),
                $this->esiBuyOrder(self::TRITANIUM, 3.375, 5300, self::JITA_STATION),
            ]],
            $this->theForgeOrdersRoute(self::PYERITE) => [[
                $this->esiSellOrder(self::PYERITE, 9.75, 410, self::JITA_STATION),
            ]],
        ]);

        $handler(new TriggerJitaMarketSync());

        $tracker = $this->trackerState();
        $this->assertSame(['running', 'running', 'running', 'running'], $trackerStatusesDuringSync);
        $this->assertSame('ok', $tracker['status']);
        $this->assertMatchesRegularExpression('/^2 types, 0(\.\d)?s$/', $tracker['message']);
        $this->assertNotNull($tracker['started_at']);
        $this->assertNotNull($tracker['completed_at']);
        $this->assertSame(
            [self::TRITANIUM => [['price' => 3.875, 'volume' => 1200]], self::PYERITE => [['price' => 9.75, 'volume' => 410]]],
            $this->marketCache->getItem('jita_market_prices')->get(),
        );
    }

    #[Group('market-audit-3')]
    public function testCurrentlyCompletesTheTrackerWhenEveryOrdersRequestFailsMarketAudit3(): void
    {
        $handler = $this->createHandler($this->industryMaterialsConnection([self::TRITANIUM, self::PYERITE]), [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->esiServiceUnavailable()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->esiServiceUnavailable()],
        ]);

        $handler(new TriggerJitaMarketSync());

        $tracker = $this->trackerState();
        $this->assertSame('ok', $tracker['status']);
        $this->assertMatchesRegularExpression('/^0 types, 0(\.\d)?s$/', $tracker['message']);
        $this->assertSame([], $this->marketCache->getItem('jita_market_prices')->get());
    }

    #[Group('market-audit-3')]
    public function testCurrentlyCompletesTheTrackerWhenTheSyncReportsAFailureMarketAudit3(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException('SDE tables missing'));
        $handler = $this->createHandler($connection, []);

        $handler(new TriggerJitaMarketSync());

        $tracker = $this->trackerState();
        $this->assertSame('ok', $tracker['status']);
        $this->assertMatchesRegularExpression('/^0 types, 0(\.\d)?s$/', $tracker['message']);
        $this->assertSame([], $this->requestedEsiUrls);
    }

    private function createHandler(Connection $connection, array $esiResponsesByRoute): TriggerJitaMarketSyncHandler
    {
        return new TriggerJitaMarketSyncHandler(
            jitaMarketService: $this->createJitaMarketServiceOnSimulatedEsi($esiResponsesByRoute, $this->marketCache, $connection),
            logger: new NullLogger(),
            syncTracker: $this->syncTracker,
        );
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
}
