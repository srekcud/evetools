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
use Symfony\Component\Mercure\Update;

#[CoversClass(TriggerJitaMarketSyncHandler::class)]
final class TriggerJitaMarketSyncHandlerTest extends TestCase
{
    use SimulatesJitaMarketEsi;

    private const int JITA_STATION = EveConstants::JITA_STATION_ID;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const string SYNC_TYPE = 'market-jita';
    private const string SDE_FAILURE_MESSAGE = 'SDE tables missing';
    private const string TRIGGERING_ADMIN_ID = 'admin-uuid';

    private ArrayAdapter $marketCache;
    private SyncTracker $syncTracker;
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->requestedEsiUrls = [];
        $this->publishedUpdates = [];
        $this->marketCache = new ArrayAdapter();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($hub, new NullLogger()),
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

    #[Group('issue-237')]
    public function testFailsTheTrackerWithTheSyncErrorWhenTheSyncReportsAFailure(): void
    {
        $handler = $this->createHandler($this->connectionWithMissingSdeTables(), []);

        $handler(new TriggerJitaMarketSync());

        $tracker = $this->trackerState();
        $this->assertSame('error', $tracker['status']);
        $this->assertSame(self::SDE_FAILURE_MESSAGE, $tracker['message']);
        $this->assertNotNull($tracker['started_at']);
        $this->assertNotNull($tracker['completed_at']);
        $this->assertSame([], $this->requestedEsiUrls);
    }

    #[Group('issue-237')]
    public function testNotifiesTheTriggeringAdminOfTheErrorAndNeverOfACompletionWhenTheSyncReportsAFailure(): void
    {
        $this->syncTracker->setTriggeredBy(self::SYNC_TYPE, self::TRIGGERING_ADMIN_ID);
        $handler = $this->createHandler($this->connectionWithMissingSdeTables(), []);

        $handler(new TriggerJitaMarketSync());

        $this->assertSame([
            ['status' => 'started', 'message' => 'Sync in progress...'],
            ['status' => 'error', 'message' => self::SDE_FAILURE_MESSAGE],
        ], $this->adminSyncNotifications());
    }

    public function testNotifiesTheTriggeringAdminOfTheCompletionWhenTheSyncSucceeds(): void
    {
        $this->syncTracker->setTriggeredBy(self::SYNC_TYPE, self::TRIGGERING_ADMIN_ID);
        $handler = $this->createHandler($this->industryMaterialsConnection([self::PYERITE]), [
            $this->theForgeOrdersRoute(self::PYERITE) => [[
                $this->esiSellOrder(self::PYERITE, 9.75, 410, self::JITA_STATION),
            ]],
        ]);

        $handler(new TriggerJitaMarketSync());

        $notifications = $this->adminSyncNotifications();
        $this->assertSame(['started', 'completed'], array_column($notifications, 'status'));
        $this->assertMatchesRegularExpression('/^1 types, 0(\.\d)?s$/', $notifications[1]['message']);
    }

    private function createHandler(Connection $connection, array $esiResponsesByRoute): TriggerJitaMarketSyncHandler
    {
        return new TriggerJitaMarketSyncHandler(
            jitaMarketService: $this->createJitaMarketServiceOnSimulatedEsi($esiResponsesByRoute, $this->marketCache, $connection),
            logger: new NullLogger(),
            syncTracker: $this->syncTracker,
        );
    }

    private function connectionWithMissingSdeTables(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException(self::SDE_FAILURE_MESSAGE));

        return $connection;
    }

    private function adminSyncNotifications(): array
    {
        return array_map(static function (Update $update): array {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

            return ['status' => $payload['status'], 'message' => $payload['message']];
        }, $this->publishedUpdates);
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
