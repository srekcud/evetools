<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercure;

use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(MercurePublisherService::class)]
class MercurePublisherServiceTest extends TestCase
{
    private HubInterface&MockObject $hub;
    private LoggerInterface&MockObject $logger;
    private MercurePublisherService $service;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MercurePublisherService(
            $this->hub,
            $this->logger,
        );
    }

    // ===========================================
    // publishSyncProgress() tests
    // ===========================================

    public function testPublishSyncProgressPublishesUpdateWithCorrectTopicAndPayload(): void
    {
        $userId = 'abc-123';
        $syncType = 'character-assets';

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($userId, $syncType): bool {
                $expectedTopic = sprintf('/user/%s/sync/%s', $userId, $syncType);
                $topics = $update->getTopics();
                if ($topics[0] !== $expectedTopic) {
                    return false;
                }

                $payload = json_decode($update->getData(), true);
                if ($payload['syncType'] !== $syncType) {
                    return false;
                }
                if ($payload['status'] !== 'in_progress') {
                    return false;
                }
                if ($payload['progress'] !== 50) {
                    return false;
                }
                if ($payload['message'] !== 'Halfway there') {
                    return false;
                }
                if (!isset($payload['timestamp'])) {
                    return false;
                }

                return $update->isPrivate();
            }));

        $this->service->publishSyncProgress($userId, $syncType, 'in_progress', 50, 'Halfway there');
    }

    public function testPublishSyncProgressIncludesAdditionalData(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['data'] === ['count' => 42, 'label' => 'items'];
            }));

        $this->service->publishSyncProgress(
            'user-1',
            'industry-jobs',
            'in_progress',
            75,
            'Processing',
            ['count' => 42, 'label' => 'items'],
        );
    }

    public function testPublishSyncProgressSetsNullForOptionalFields(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['progress'] === null
                    && $payload['message'] === null
                    && $payload['data'] === null;
            }));

        $this->service->publishSyncProgress('user-1', 'pve', 'started');
    }

    public function testPublishSyncProgressLogsDebugOnSuccess(): void
    {
        $this->hub->method('publish');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Mercure update published', $this->callback(function (array $context): bool {
                return $context['topic'] === '/user/u1/sync/mining'
                    && $context['status'] === 'completed'
                    && $context['progress'] === 100;
            }));

        $this->service->publishSyncProgress('u1', 'mining', 'completed', 100);
    }

    public function testPublishSyncProgressCatchesExceptionAndLogsWarning(): void
    {
        $this->hub
            ->method('publish')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to publish Mercure update', $this->callback(function (array $context): bool {
                return $context['topic'] === '/user/u1/sync/pve'
                    && $context['error'] === 'Connection refused';
            }));

        // Must not throw
        $this->service->publishSyncProgress('u1', 'pve', 'started', 0, 'Go');
    }

    // ===========================================
    // syncStarted() tests
    // ===========================================

    public function testSyncStartedPublishesWithStartedStatusAndZeroProgress(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['status'] === 'started'
                    && $payload['progress'] === 0
                    && $payload['message'] === 'Starting...';
            }));

        $this->service->syncStarted('user-1', 'character-assets');
    }

    public function testSyncStartedUsesCustomMessageWhenProvided(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['message'] === 'Fetching assets from ESI';
            }));

        $this->service->syncStarted('user-1', 'character-assets', 'Fetching assets from ESI');
    }

    // ===========================================
    // syncProgress() tests
    // ===========================================

    public function testSyncProgressPublishesWithInProgressStatus(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['status'] === 'in_progress'
                    && $payload['progress'] === 60
                    && $payload['message'] === 'Processing page 3/5';
            }));

        $this->service->syncProgress('user-1', 'corporation-assets', 60, 'Processing page 3/5');
    }

    public function testSyncProgressForwardsAdditionalData(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['data'] === ['page' => 3, 'total' => 5];
            }));

        $this->service->syncProgress('user-1', 'market-structure', 60, 'Page 3', ['page' => 3, 'total' => 5]);
    }

    // ===========================================
    // syncCompleted() tests
    // ===========================================

    public function testSyncCompletedPublishesWithCompletedStatusAndFullProgress(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['status'] === 'completed'
                    && $payload['progress'] === 100
                    && $payload['message'] === 'Done';
            }));

        $this->service->syncCompleted('user-1', 'industry-jobs');
    }

    public function testSyncCompletedUsesCustomMessageAndData(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['message'] === 'Synced 150 jobs'
                    && $payload['data'] === ['jobCount' => 150];
            }));

        $this->service->syncCompleted('user-1', 'industry-jobs', 'Synced 150 jobs', ['jobCount' => 150]);
    }

    // ===========================================
    // syncError() tests
    // ===========================================

    public function testSyncErrorPublishesWithErrorStatusAndNullProgress(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['status'] === 'error'
                    && $payload['progress'] === null
                    && $payload['message'] === 'ESI returned 502';
            }));

        $this->service->syncError('user-1', 'ansiblex', 'ESI returned 502');
    }

    // ===========================================
    // publishAlert() tests
    // ===========================================

    public function testPublishAlertPublishesOnCorrectAlertTopic(): void
    {
        // Use integer values to avoid float-to-int coercion through JSON round-trip
        $alertData = ['typeId' => 34, 'currentPrice' => 450, 'threshold' => 500];

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($alertData): bool {
                $topics = $update->getTopics();
                if ($topics[0] !== '/user/u42/alerts/market-price') {
                    return false;
                }

                $payload = json_decode($update->getData(), true);

                return $payload['alertType'] === 'market-price'
                    && $payload['data'] === $alertData
                    && isset($payload['timestamp'])
                    && $update->isPrivate();
            }));

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Alert published', $this->callback(function (array $context): bool {
                return $context['topic'] === '/user/u42/alerts/market-price'
                    && $context['alertType'] === 'market-price';
            }));

        $this->service->publishAlert('u42', 'market-price', $alertData);
    }

    public function testPublishAlertCatchesExceptionAndLogsWarning(): void
    {
        $this->hub
            ->method('publish')
            ->willThrowException(new \RuntimeException('Timeout'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to publish alert', $this->callback(function (array $context): bool {
                return $context['topic'] === '/user/u1/alerts/planetary-expiry'
                    && $context['error'] === 'Timeout';
            }));

        $this->service->publishAlert('u1', 'planetary-expiry', ['colonyId' => 99]);
    }

    // ===========================================
    // publishNotification() tests
    // ===========================================

    public function testPublishNotificationPublishesOnNotificationTopic(): void
    {
        $notifData = ['category' => 'industry', 'title' => 'Job completed', 'body' => 'Rifter x10'];

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($notifData): bool {
                $topics = $update->getTopics();
                if ($topics[0] !== '/user/u7/notifications') {
                    return false;
                }

                $payload = json_decode($update->getData(), true);

                return $payload['type'] === 'notification'
                    && $payload['notification'] === $notifData
                    && isset($payload['timestamp'])
                    && $update->isPrivate();
            }));

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Notification published', $this->callback(function (array $context): bool {
                return $context['topic'] === '/user/u7/notifications'
                    && $context['category'] === 'industry';
            }));

        $this->service->publishNotification('u7', $notifData);
    }

    public function testPublishNotificationLogsCategoryAsUnknownWhenMissing(): void
    {
        $this->hub->method('publish');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Notification published', $this->callback(function (array $context): bool {
                return $context['category'] === 'unknown';
            }));

        $this->service->publishNotification('u7', ['title' => 'Test']);
    }

    public function testPublishNotificationCatchesExceptionAndLogsWarning(): void
    {
        $this->hub
            ->method('publish')
            ->willThrowException(new \RuntimeException('Connection lost'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to publish notification', $this->callback(function (array $context): bool {
                return $context['topic'] === '/user/u1/notifications'
                    && $context['error'] === 'Connection lost';
            }));

        $this->service->publishNotification('u1', ['title' => 'Test']);
    }

    // ===========================================
    // publishEscalationEvent() tests
    // ===========================================

    public function testPublishEscalationEventPublishesCorpTopic(): void
    {
        $escalationData = ['id' => 'esc-1', 'system' => 'Jita'];

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($escalationData): bool {
                $topics = $update->getTopics();
                if ($topics[0] !== '/corp/98000001/escalations') {
                    return false;
                }

                $payload = json_decode($update->getData(), true);

                return $payload['action'] === 'created'
                    && $payload['escalation'] === $escalationData
                    && isset($payload['timestamp'])
                    && !$update->isPrivate();
            }));

        $this->service->publishEscalationEvent('created', $escalationData, 98000001, null, 'corp');
    }

    public function testPublishEscalationEventPublishesAllianceTopic(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                return $update->getTopics()[0] === '/alliance/99000001/escalations'
                    && !$update->isPrivate();
            }));

        $this->service->publishEscalationEvent('updated', ['id' => 'esc-2'], null, 99000001, 'alliance');
    }

    public function testPublishEscalationEventPublishesPublicTopic(): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                return $update->getTopics()[0] === '/public/escalations';
            }));

        $this->service->publishEscalationEvent('deleted', ['id' => 'esc-3'], null, null, 'public');
    }

    public function testPublishEscalationEventDoesNothingWhenCorpVisibilityButNoCorporationId(): void
    {
        $this->hub->expects($this->never())->method('publish');

        $this->service->publishEscalationEvent('created', ['id' => 'esc-4'], null, null, 'corp');
    }

    public function testPublishEscalationEventDoesNothingWhenAllianceVisibilityButNoAllianceId(): void
    {
        $this->hub->expects($this->never())->method('publish');

        $this->service->publishEscalationEvent('created', ['id' => 'esc-5'], 98000001, null, 'alliance');
    }

    public function testPublishEscalationEventDoesNothingForUnknownVisibility(): void
    {
        $this->hub->expects($this->never())->method('publish');

        $this->service->publishEscalationEvent('created', ['id' => 'esc-6'], 98000001, 99000001, 'private');
    }

    public function testPublishEscalationEventCatchesExceptionAndLogsWarning(): void
    {
        $this->hub
            ->method('publish')
            ->willThrowException(new \RuntimeException('Hub down'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to publish escalation event', $this->callback(function (array $context): bool {
                return $context['error'] === 'Hub down';
            }));

        $this->service->publishEscalationEvent('created', ['id' => 'esc-7'], 98000001, null, 'corp');
    }

    // ===========================================
    // getTopicsForUser() tests
    // ===========================================

    public function testGetTopicsForUserReturnsAllExpectedTopics(): void
    {
        $userId = 'user-uuid-123';
        $topics = MercurePublisherService::getTopicsForUser($userId);

        $expectedTopics = [
            '/user/user-uuid-123/sync/character-assets',
            '/user/user-uuid-123/sync/corporation-assets',
            '/user/user-uuid-123/sync/ansiblex',
            '/user/user-uuid-123/sync/industry-jobs',
            '/user/user-uuid-123/sync/industry-job-completed',
            '/user/user-uuid-123/sync/industry-project',
            '/user/user-uuid-123/sync/pve',
            '/user/user-uuid-123/sync/mining',
            '/user/user-uuid-123/sync/wallet-transactions',
            '/user/user-uuid-123/sync/market-structure',
            '/user/user-uuid-123/sync/planetary',
            '/user/user-uuid-123/sync/public-contracts',
            '/user/user-uuid-123/sync/admin-sync',
            '/user/user-uuid-123/alerts/planetary-expiry',
            '/user/user-uuid-123/alerts/market-price',
            '/user/user-uuid-123/notifications',
        ];

        $this->assertSame($expectedTopics, $topics);
    }

    public function testGetTopicsForUserReturnsCorrectCount(): void
    {
        $topics = MercurePublisherService::getTopicsForUser('any-user');

        $this->assertCount(16, $topics);
    }

    // ===========================================
    // getGroupTopics() tests
    // ===========================================

    public function testGetGroupTopicsAlwaysIncludesPublicEscalations(): void
    {
        $topics = MercurePublisherService::getGroupTopics(null, null);

        $this->assertSame(['/public/escalations'], $topics);
    }

    public function testGetGroupTopicsIncludesCorpTopicWhenCorporationIdProvided(): void
    {
        $topics = MercurePublisherService::getGroupTopics(98000001, null);

        $this->assertContains('/public/escalations', $topics);
        $this->assertContains('/corp/98000001/escalations', $topics);
        $this->assertCount(2, $topics);
    }

    public function testGetGroupTopicsIncludesAllianceTopicWhenAllianceIdProvided(): void
    {
        $topics = MercurePublisherService::getGroupTopics(null, 99000001);

        $this->assertContains('/public/escalations', $topics);
        $this->assertContains('/alliance/99000001/escalations', $topics);
        $this->assertCount(2, $topics);
    }

    public function testGetGroupTopicsIncludesBothCorpAndAllianceTopics(): void
    {
        $topics = MercurePublisherService::getGroupTopics(98000001, 99000001);

        $this->assertContains('/public/escalations', $topics);
        $this->assertContains('/corp/98000001/escalations', $topics);
        $this->assertContains('/alliance/99000001/escalations', $topics);
        $this->assertCount(3, $topics);
    }
}
