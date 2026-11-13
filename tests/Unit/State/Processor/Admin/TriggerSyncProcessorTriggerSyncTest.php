<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Admin;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Admin\ActionResultResource;
use App\ApiResource\Input\Admin\TriggerSyncInput;
use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncCostIndices;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerAssetsSync;
use App\Message\TriggerJitaMarketSync;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\NotificationRepository;
use App\Security\AdminChecker;
use App\Service\Admin\SyncTracker;
use App\Tests\Unit\Service\Admin\InMemorySyncRunLog;
use App\Service\MarketAlertService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Admin\TriggerSyncProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TriggerSyncProcessor::class)]
class TriggerSyncProcessorTriggerSyncTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const OTHER_EVE_CHARACTER_ID = 90000002;
    private const ADMIN_USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';

    /** @var list<object> */
    private array $dispatchedMessages = [];

    /** @var list<Update> */
    private array $publishedUpdates = [];

    private SyncTracker $syncTracker;
    private MarketAlertService $marketAlertService;

    protected function setUp(): void
    {
        $this->dispatchedMessages = [];
        $this->publishedUpdates = [];
        $this->marketAlertService = $this->createStub(MarketAlertService::class);
    }

    public function testTriggeringACostIndicesSyncDispatchesItsMessage(): void
    {
        $result = $this->triggerSync($this->adminProcessor(), 'cost-indices');

        $this->assertTrue($result->success);
        $this->assertSame('Sync triggered: cost-indices', $result->message);
        $this->assertEquals([new SyncCostIndices()], $this->dispatchedMessages);
    }

    public function testTriggeringAMarketTypeDispatchesOnlyItsOwnMessage(): void
    {
        $this->triggerSync($this->adminProcessor(), 'market-jita');

        $this->assertEquals([new TriggerJitaMarketSync()], $this->dispatchedMessages);
    }

    public function testTheTriggeringAdminIsNotifiedWhenTheSyncStarts(): void
    {
        $this->triggerSync($this->adminProcessor(), 'cost-indices');

        $this->syncTracker->start('cost-indices');

        $this->assertNotEmpty($this->publishedUpdates);
        $this->assertSame(['/user/' . self::ADMIN_USER_ID . '/sync/admin-sync'], $this->publishedUpdates[0]->getTopics());
    }

    public function testTriggeringMarketAlertsRunsTheCheckSynchronously(): void
    {
        $this->marketAlertService = $this->createStub(MarketAlertService::class);
        $this->marketAlertService->method('checkAlerts')->willReturn(3);

        $result = $this->triggerSync($this->adminProcessor(), 'market-alerts');

        $this->assertTrue($result->success);
        $this->assertSame('Market alerts checked: 3 triggered', $result->message);
        $this->assertSame([], $this->dispatchedMessages);
    }

    public function testAnUnknownTypeDispatchesNothing(): void
    {
        $result = $this->triggerSync($this->adminProcessor(), 'does-not-exist');

        $this->assertFalse($result->success);
        $this->assertSame('Unknown sync type', $result->message);
        $this->assertSame([], $this->dispatchedMessages);
    }

    public function testANonAdminCannotTriggerASync(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->triggerSync($this->processorFor(self::OTHER_EVE_CHARACTER_ID), 'cost-indices');
    }

    public function testTheLegacyAssetsActionStillDispatchesTheAssetsSync(): void
    {
        $result = $this->adminProcessor()->process(null, new Post(name: 'sync_assets'));

        $this->assertTrue($result->success);
        $this->assertEquals([new TriggerAssetsSync()], $this->dispatchedMessages);
    }

    #[Group('issue-916')]
    public function testTriggeringAWalletSyncDispatchesItForEveryCharacterWhateverTheirNextReadAt(): void
    {
        $result = $this->triggerSync($this->adminProcessor(), 'wallet');

        $this->assertTrue($result->success);
        $this->assertSame('Sync triggered: wallet', $result->message);
        $this->assertEquals([new SyncWalletTransactions(dueOnly: false)], $this->dispatchedMessages);
    }

    #[Group('issue-916')]
    public function testTheLegacyWalletActionDispatchesItForEveryCharacterWhateverTheirNextReadAt(): void
    {
        $result = $this->adminProcessor()->process(null, new Post(name: 'sync_wallet'));

        $this->assertTrue($result->success);
        $this->assertEquals([new SyncWalletTransactions(dueOnly: false)], $this->dispatchedMessages);
    }

    private function triggerSync(TriggerSyncProcessor $processor, string $type): ActionResultResource
    {
        $input = new TriggerSyncInput();
        $input->type = $type;

        return $processor->process($input, new Post(name: 'trigger_sync'));
    }

    private function adminProcessor(): TriggerSyncProcessor
    {
        return $this->processorFor(self::ADMIN_EVE_CHARACTER_ID);
    }

    private function processorFor(int $eveCharacterId): TriggerSyncProcessor
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'id';
        });

        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($hub, new NullLogger()),
            new InMemorySyncRunLog(),
        );

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->userWithCharacter($eveCharacterId));

        return new TriggerSyncProcessor(
            $security,
            $messageBus,
            $this->syncTracker,
            $this->marketAlertService,
            $this->createStub(NotificationRepository::class),
            $this->createStub(MarketPriceHistoryRepository::class),
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
        );
    }

    private function userWithCharacter(int $eveCharacterId): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString(self::ADMIN_USER_ID));
        $user->method('getMainCharacter')->willReturn($character);

        return $user;
    }
}
