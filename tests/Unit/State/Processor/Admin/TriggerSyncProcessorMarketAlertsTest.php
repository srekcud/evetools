<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Admin;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Admin\ActionResultResource;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\NotificationRepository;
use App\Security\AdminChecker;
use App\Service\Admin\SyncTracker;
use App\Service\MarketAlertService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Admin\TriggerSyncProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #81 : le bouton « Market Alerts » de l'admin attend un événement Mercure admin-sync
 * completed/error. La vérification des alertes de prix, synchrone, doit donc être suivie par
 * SyncTracker sous la clé `market-alerts`, comme les autres syncs déclenchées depuis l'admin.
 */
#[CoversClass(TriggerSyncProcessor::class)]
class TriggerSyncProcessorMarketAlertsTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const ADMIN_USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';
    private const MARKET_ALERTS_SYNC_TYPE = 'market-alerts';

    private MarketAlertService&Stub $marketAlertService;
    private SyncTracker $syncTracker;
    private TriggerSyncProcessor $processor;

    /** @var list<Update> */
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->publishedUpdates = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'id';
        });

        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($hub, new NullLogger()),
        );

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->adminUser());

        $this->marketAlertService = $this->createStub(MarketAlertService::class);

        $this->processor = new TriggerSyncProcessor(
            $security,
            $this->createStub(MessageBusInterface::class),
            $this->syncTracker,
            $this->marketAlertService,
            $this->createStub(NotificationRepository::class),
            $this->createStub(MarketPriceHistoryRepository::class),
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
        );
    }

    public function testCheckingMarketAlertsReturnsTheNumberOfTriggeredAlerts(): void
    {
        $this->marketAlertService->method('checkAlerts')->willReturn(2);

        $result = $this->checkMarketAlerts();

        $this->assertTrue($result->success);
        $this->assertSame('Market alerts checked: 2 triggered', $result->message);
    }

    public function testCheckingMarketAlertsNotifiesTheTriggeringAdminOfCompletion(): void
    {
        $this->marketAlertService->method('checkAlerts')->willReturn(2);

        $this->checkMarketAlerts();

        $lastEvent = $this->lastAdminSyncEvent();
        $this->assertSame('completed', $lastEvent['status']);
        $this->assertSame(100, $lastEvent['progress']);
        $this->assertSame('Market alerts checked: 2 triggered', $lastEvent['message']);
        $this->assertSame(['syncType' => self::MARKET_ALERTS_SYNC_TYPE], $lastEvent['data']);
    }

    public function testCheckingMarketAlertsRecordsASuccessfulRunOnTheMarketAlertsRow(): void
    {
        $this->marketAlertService->method('checkAlerts')->willReturn(2);

        $this->checkMarketAlerts();

        $row = $this->marketAlertsRow();
        $this->assertSame('ok', $row['status']);
        $this->assertSame('Market alerts checked: 2 triggered', $row['message']);
        $this->assertNotNull($row['started_at']);
        $this->assertNotNull($row['completed_at']);
    }

    public function testFailedMarketAlertsCheckReturnsAnErrorResult(): void
    {
        $this->marketAlertService->method('checkAlerts')
            ->willThrowException(new \RuntimeException('Jita prices unavailable'));

        $result = $this->checkMarketAlerts();

        $this->assertFalse($result->success);
        $this->assertSame('Error: Jita prices unavailable', $result->message);
    }

    public function testFailedMarketAlertsCheckNotifiesTheTriggeringAdminOfTheError(): void
    {
        $this->marketAlertService->method('checkAlerts')
            ->willThrowException(new \RuntimeException('Jita prices unavailable'));

        $this->checkMarketAlerts();

        $lastEvent = $this->lastAdminSyncEvent();
        $this->assertSame('error', $lastEvent['status']);
        $this->assertNull($lastEvent['progress']);
        $this->assertSame('Jita prices unavailable', $lastEvent['message']);
        $this->assertSame(['syncType' => self::MARKET_ALERTS_SYNC_TYPE], $lastEvent['data']);
    }

    public function testFailedMarketAlertsCheckRecordsAnErrorOnTheMarketAlertsRow(): void
    {
        $this->marketAlertService->method('checkAlerts')
            ->willThrowException(new \RuntimeException('Jita prices unavailable'));

        $this->checkMarketAlerts();

        $row = $this->marketAlertsRow();
        $this->assertSame('error', $row['status']);
        $this->assertSame('Jita prices unavailable', $row['message']);
        $this->assertNotNull($row['completed_at']);
    }

    private function checkMarketAlerts(): ActionResultResource
    {
        return $this->processor->process(null, new Post(name: 'check_market_alerts'));
    }

    /** @return array<string, mixed> */
    private function lastAdminSyncEvent(): array
    {
        $this->assertNotEmpty($this->publishedUpdates, 'No admin-sync event published');
        $lastUpdate = $this->publishedUpdates[array_key_last($this->publishedUpdates)];
        $this->assertSame(['/user/' . self::ADMIN_USER_ID . '/sync/admin-sync'], $lastUpdate->getTopics());

        return json_decode($lastUpdate->getData(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function marketAlertsRow(): array
    {
        foreach ($this->syncTracker->getAll() as $row) {
            if ($row['type'] === self::MARKET_ALERTS_SYNC_TYPE) {
                return $row;
            }
        }

        $this->fail('No market-alerts row in the admin sync list');
    }

    private function adminUser(): User
    {
        $mainCharacter = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Admin Pilot');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString(self::ADMIN_USER_ID));
        $user->method('getMainCharacter')->willReturn($mainCharacter);

        return $user;
    }
}
