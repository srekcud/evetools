<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Notification;

use ApiPlatform\Metadata\Delete;
use App\Repository\NotificationRepository;
use App\State\Processor\Notification\DeleteNotificationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeleteNotificationProcessor::class)]
final class DeleteNotificationProcessorCharacterizationTest extends TestCase
{
    #[Group('notif-audit-P3-5')]
    public function testMalformedNotificationIdCurrentlyThrowsUidInvalidArgumentNotifAuditP35(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID.');

        (new DeleteNotificationProcessor($repository, $entityManager))->process(null, new Delete(), ['id' => 'zzzz-notification']);
    }

    public function testUnknownNotificationIdIsNotFound(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects($this->once())->method('find')
            ->with(self::equalTo(Uuid::fromString('0192a7c6-5e70-7c13-94f6-8a0b2c3e5079')))
            ->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Notification not found');

        (new DeleteNotificationProcessor($repository, $entityManager))->process(null, new Delete(), ['id' => '0192a7c6-5e70-7c13-94f6-8a0b2c3e5079']);
    }
}
