<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Notification;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\State\Provider\Notification\NotificationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(NotificationProvider::class)]
final class NotificationProviderCharacterizationTest extends TestCase
{
    #[Group('notif-audit-P3-5')]
    public function testMalformedNotificationIdCurrentlyThrowsUidInvalidArgumentNotifAuditP35(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects($this->never())->method('find');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID.');

        $this->provider($repository)->provide(new Get(), ['id' => 'not-a-notification-id']);
    }

    public function testUnknownNotificationIdIsNotFound(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects($this->once())->method('find')
            ->with(self::equalTo(Uuid::fromString('0192a7c6-3c5e-7a91-b2d4-6e8f0a1c3e57')))
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Notification not found');

        $this->provider($repository)->provide(new Get(), ['id' => '0192a7c6-3c5e-7a91-b2d4-6e8f0a1c3e57']);
    }

    private function provider(NotificationRepository $repository): NotificationProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        return new NotificationProvider($security, $repository);
    }
}
