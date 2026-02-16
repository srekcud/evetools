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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(NotificationDispatcher::class)]
class NotificationDispatcherTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UserNotificationPreferenceRepository&Stub $preferenceRepository;
    private HubInterface&MockObject $hub;
    private WebPushService&MockObject $webPushService;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->preferenceRepository = $this->createStub(UserNotificationPreferenceRepository::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->webPushService = $this->createMock(WebPushService::class);

        $mercurePublisher = new MercurePublisherService($this->hub, new NullLogger());

        $this->dispatcher = new NotificationDispatcher(
            $this->entityManager,
            $this->preferenceRepository,
            $mercurePublisher,
            $this->webPushService,
            new NullLogger(),
        );
    }

    // ===========================================
    // dispatch — creates notification when enabled
    // ===========================================

    public function testCreatesNotificationWhenCategoryIsEnabled(): void
    {
        $user = $this->createUserStub();
        $preference = $this->createPreference(true, false);

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn($preference);

        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(Notification::class));
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_INFO,
            'Job completed',
            'Your manufacturing job has completed.',
        );
    }

    public function testCreatesNotificationWhenNoPreferenceExists(): void
    {
        $user = $this->createUserStub();

        // No preference = default enabled
        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn(null);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_INFO,
            'Job completed',
            'Your manufacturing job has completed.',
        );
    }

    // ===========================================
    // dispatch — skips when disabled
    // ===========================================

    public function testSkipsNotificationWhenCategoryIsDisabled(): void
    {
        $user = $this->createUserStub();
        $preference = $this->createPreference(false, false);

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn($preference);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_INFO,
            'Job completed',
            'Your manufacturing job has completed.',
        );
    }

    // ===========================================
    // dispatch — Mercure publish
    // ===========================================

    public function testMercurePublishIsCalledOnDispatch(): void
    {
        $user = $this->createUserStub();
        $preference = $this->createPreference(true, false);

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn($preference);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_PRICE,
            Notification::LEVEL_WARNING,
            'Price alert',
            'Tritanium price dropped.',
        );
    }

    // ===========================================
    // dispatch — Web Push
    // ===========================================

    public function testWebPushIsCalledWhenPushEnabled(): void
    {
        $user = $this->createUserStub();
        $preference = $this->createPreference(true, true);

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn($preference);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->once())
            ->method('send')
            ->with(
                $this->equalTo($user),
                $this->isInstanceOf(Notification::class),
            );

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_PLANETARY,
            Notification::LEVEL_WARNING,
            'Extractor expiring',
            'Your extractor heads will expire in 60 minutes.',
        );
    }

    public function testWebPushIsNotCalledWhenPushDisabled(): void
    {
        $user = $this->createUserStub();
        $preference = $this->createPreference(true, false);

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn($preference);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_PLANETARY,
            Notification::LEVEL_WARNING,
            'Extractor expiring',
            'Your extractor heads will expire in 60 minutes.',
        );
    }

    public function testWebPushIsNotCalledWhenNoPreference(): void
    {
        $user = $this->createUserStub();

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn(null);

        // No preference means pushEnabled defaults to false
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_INFO,
            'Job done',
            'Test message.',
        );
    }

    // ===========================================
    // dispatch — Web Push failure is non-fatal
    // ===========================================

    public function testWebPushFailureDoesNotPreventNotification(): void
    {
        $user = $this->createUserStub();
        $preference = $this->createPreference(true, true);

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn($preference);

        $this->webPushService->expects($this->once())
            ->method('send')
            ->willThrowException(new \RuntimeException('Push failed'));

        // Notification should still be persisted and Mercure called
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        // Should not throw
        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_ESCALATION,
            Notification::LEVEL_WARNING,
            'Escalation expiring',
            'Your escalation is about to expire.',
        );
    }

    // ===========================================
    // dispatch — user without ID
    // ===========================================

    public function testSkipsWhenUserHasNoId(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);

        $this->entityManager->expects($this->never())->method('persist');
        $this->hub->expects($this->never())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_INFO,
            'Test',
            'Test message.',
        );
    }

    // ===========================================
    // dispatch — with optional data and route
    // ===========================================

    public function testDispatchPassesDataAndRouteToNotification(): void
    {
        $user = $this->createUserStub();

        $this->preferenceRepository
            ->method('findByUserAndCategory')
            ->willReturn(null);

        $capturedNotification = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Notification $n) use (&$capturedNotification): bool {
                $capturedNotification = $n;
                return true;
            }));
        $this->hub->expects($this->once())->method('publish');
        $this->webPushService->expects($this->never())->method('send');

        $this->dispatcher->dispatch(
            $user,
            Notification::CATEGORY_PRICE,
            Notification::LEVEL_INFO,
            'Price alert',
            'Price changed',
            ['typeId' => 34, 'price' => 5.50],
            '/market/34',
        );

        $this->assertNotNull($capturedNotification);
        $this->assertSame(['typeId' => 34, 'price' => 5.50], $capturedNotification->getData());
        $this->assertSame('/market/34', $capturedNotification->getRoute());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        return $user;
    }

    private function createPreference(bool $enabled, bool $pushEnabled): UserNotificationPreference
    {
        $pref = new UserNotificationPreference();
        $pref->setEnabled($enabled);
        $pref->setPushEnabled($pushEnabled);

        return $pref;
    }
}
