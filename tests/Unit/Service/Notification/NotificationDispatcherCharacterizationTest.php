<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(NotificationDispatcher::class)]
final class NotificationDispatcherCharacterizationTest extends TestCase
{
    private const string USER_ID = '0192a7c4-5e1f-7d3a-9b2c-4f6e8a1d3c57';
    private const string NOTIFICATION_ID = '0192a7c4-6a2b-7e4c-8d1f-2a3b4c5d6e7f';

    private EntityManagerInterface&MockObject $entityManager;
    private UserNotificationPreferenceRepository&Stub $preferenceRepository;
    private HubInterface&Stub $hub;
    private WebPushService&MockObject $webPushService;
    private BufferingLogger $logger;
    private NotificationDispatcher $dispatcher;
    private User&Stub $user;

    /** @var list<Notification> */
    private array $persistedNotifications = [];

    /** @var list<Update> */
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->preferenceRepository = $this->createStub(UserNotificationPreferenceRepository::class);
        $this->hub = $this->createStub(HubInterface::class);
        $this->webPushService = $this->createMock(WebPushService::class);
        $this->logger = new BufferingLogger();

        $this->user = $this->createStub(User::class);
        $this->user->method('getId')->willReturn(Uuid::fromString(self::USER_ID));

        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            self::assertInstanceOf(Notification::class, $entity);
            (new \ReflectionProperty(Notification::class, 'id'))->setValue($entity, Uuid::fromString(self::NOTIFICATION_ID));
            $this->persistedNotifications[] = $entity;
        });
        $this->hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });

        $this->dispatcher = new NotificationDispatcher(
            $this->entityManager,
            $this->preferenceRepository,
            new MercurePublisherService($this->hub, $this->logger),
            $this->webPushService,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testPersistsNotificationWithEveryDispatchedField(): void
    {
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn(null);
        $this->entityManager->expects($this->once())->method('flush');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_PLANETARY,
            Notification::LEVEL_CRITICAL,
            'Extractor expiring soon',
            'Base Metals extractor on Jita IV expires in 17 min (Pilot Alpha)',
            ['planetName' => 'Jita IV', 'minutesRemaining' => 17],
            '/planetary',
        );

        self::assertCount(1, $this->persistedNotifications);
        $notification = $this->persistedNotifications[0];
        self::assertSame($this->user, $notification->getUser());
        self::assertSame('planetary', $notification->getCategory());
        self::assertSame('critical', $notification->getLevel());
        self::assertSame('Extractor expiring soon', $notification->getTitle());
        self::assertSame('Base Metals extractor on Jita IV expires in 17 min (Pilot Alpha)', $notification->getMessage());
        self::assertSame(['planetName' => 'Jita IV', 'minutesRemaining' => 17], $notification->getData());
        self::assertSame('/planetary', $notification->getRoute());
        self::assertFalse($notification->isRead());
        self::assertCount(1, $this->publishedUpdates);
        self::assertSame(['/user/' . self::USER_ID . '/notifications'], $this->publishedUpdates[0]->getTopics());
        self::assertSame([[
            'debug',
            'Notification published',
            ['topic' => '/user/' . self::USER_ID . '/notifications', 'category' => 'planetary'],
        ]], $this->logger->cleanLogs());
    }

    #[Group('notif-audit-P1-2')]
    public function testMercureUpdateCurrentlyNestsNotificationUnderEnvelopeNotifAuditP12(): void
    {
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn(null);
        $this->entityManager->expects($this->once())->method('flush');
        $this->webPushService->expects($this->never())->method('send');
        $before = (new \DateTimeImmutable())->getTimestamp();

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_SUCCESS,
            'Job completed',
            'Rifter x7 manufacturing finished',
            ['jobId' => 612_345_678, 'runs' => 7],
            '/industry/jobs',
        );

        $after = (new \DateTimeImmutable())->getTimestamp();
        self::assertCount(1, $this->publishedUpdates);
        $update = $this->publishedUpdates[0];
        self::assertSame(['/user/' . self::USER_ID . '/notifications'], $update->getTopics());
        self::assertTrue($update->isPrivate());

        $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['type', 'notification', 'timestamp'], array_keys($payload));
        self::assertSame('notification', $payload['type']);
        self::assertSame([
            'id' => self::NOTIFICATION_ID,
            'category' => 'industry',
            'level' => 'success',
            'title' => 'Job completed',
            'message' => 'Rifter x7 manufacturing finished',
            'data' => ['jobId' => 612_345_678, 'runs' => 7],
            'route' => '/industry/jobs',
            'isRead' => false,
            'createdAt' => $this->persistedNotifications[0]->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $payload['notification']);

        $timestamp = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $payload['timestamp']);
        self::assertNotFalse($timestamp);
        self::assertGreaterThanOrEqual($before, $timestamp->getTimestamp());
        self::assertLessThanOrEqual($after, $timestamp->getTimestamp());
    }

    public function testSendsWebPushWithThePersistedNotificationWhenPushIsEnabled(): void
    {
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn($this->preference(enabled: true, pushEnabled: true));
        $this->entityManager->expects($this->once())->method('flush');
        $pushedNotifications = [];
        $this->webPushService->expects($this->once())->method('send')
            ->willReturnCallback(function (User $user, Notification $notification) use (&$pushedNotifications): void {
                self::assertSame($this->user, $user);
                $pushedNotifications[] = $notification;
            });

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_ESCALATION,
            Notification::LEVEL_WARNING,
            'Escalation expiring',
            'Angel Military Operations Complex expires in 38 min',
        );

        self::assertCount(1, $this->persistedNotifications);
        self::assertSame($this->persistedNotifications, $pushedNotifications);
        self::assertSame([], array_filter($this->logger->cleanLogs(), static fn (array $log): bool => $log[0] === 'warning'));
    }

    public function testDisabledCategorySkipsPersistMercureAndPushEvenWhenPushIsEnabled(): void
    {
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn($this->preference(enabled: false, pushEnabled: true));
        $this->entityManager->expects($this->never())->method('flush');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_ESI,
            Notification::LEVEL_ERROR,
            'ESI token expired',
            'Pilot Beta needs to log in again',
        );

        self::assertSame([], $this->persistedNotifications);
        self::assertSame([], $this->publishedUpdates);
    }

    public function testWebPushFailureIsLoggedAsWarningWithUserCategoryAndError(): void
    {
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn($this->preference(enabled: true, pushEnabled: true));
        $this->entityManager->expects($this->once())->method('flush');
        $this->webPushService->expects($this->once())->method('send')
            ->willThrowException(new \RuntimeException('Push service unreachable'));

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_PRICE,
            Notification::LEVEL_INFO,
            'Price alert',
            'Tritanium sell price crossed 4.37 ISK',
        );

        self::assertCount(1, $this->persistedNotifications);
        self::assertCount(1, $this->publishedUpdates);
        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $log): bool => $log[0] === 'warning'));
        self::assertSame([[
            'warning',
            'Failed to send web push notification',
            ['userId' => self::USER_ID, 'category' => 'price', 'error' => 'Push service unreachable'],
        ]], $warnings);
    }

    public function testEnabledCategoryWithPushDisabledPersistsWithoutPush(): void
    {
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn($this->preference(enabled: true, pushEnabled: false));
        $this->entityManager->expects($this->once())->method('flush');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_INFO,
            'Research completed',
            'Raven Blueprint ME 8 research finished',
        );

        self::assertCount(1, $this->persistedNotifications);
        self::assertSame('Research completed', $this->persistedNotifications[0]->getTitle());
        self::assertCount(1, $this->publishedUpdates);
    }

    #[Group('notif-audit-P2-4')]
    public function testDispatchCurrentlyIgnoresCategoryThresholdNotifAuditP24(): void
    {
        $preference = $this->preference(enabled: true, pushEnabled: false);
        $preference->setThresholdMinutes(45);
        $this->preferenceRepository->method('findByUserAndCategory')->willReturn($preference);
        $this->entityManager->expects($this->once())->method('flush');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $this->user,
            Notification::CATEGORY_PLANETARY,
            Notification::LEVEL_WARNING,
            'Extractor expiring soon',
            'Heavy Metals extractor on Amarr VIII expires in 95 min (Pilot Gamma)',
            ['minutesRemaining' => 95],
            '/planetary',
        );

        self::assertCount(1, $this->persistedNotifications);
        self::assertSame(['minutesRemaining' => 95], $this->persistedNotifications[0]->getData());
        self::assertCount(1, $this->publishedUpdates);
    }

    private function preference(bool $enabled, bool $pushEnabled): UserNotificationPreference
    {
        return (new UserNotificationPreference())->setEnabled($enabled)->setPushEnabled($pushEnabled);
    }
}
