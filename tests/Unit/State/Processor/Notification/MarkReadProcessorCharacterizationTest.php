<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Notification;

use ApiPlatform\Metadata\Patch;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\State\Processor\Notification\MarkReadProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarkReadProcessor::class)]
final class MarkReadProcessorCharacterizationTest extends TestCase
{
    #[Group('notif-audit-P3-5')]
    public function testMalformedNotificationIdCurrentlyThrowsUidInvalidArgumentNotifAuditP35(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects($this->never())->method('find');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID.');

        $this->processor($repository, $entityManager)->process(null, new Patch(), ['id' => '12345']);
    }

    public function testUnknownNotificationIdIsNotFound(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects($this->once())->method('find')
            ->with(self::equalTo(Uuid::fromString('0192a7c6-4d6f-7b02-83e5-7f9a1b2d4f68')))
            ->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Notification not found');

        $this->processor($repository, $entityManager)->process(null, new Patch(), ['id' => '0192a7c6-4d6f-7b02-83e5-7f9a1b2d4f68']);
    }

    private function processor(NotificationRepository $repository, EntityManagerInterface $entityManager): MarkReadProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        return new MarkReadProcessor($security, $repository, $entityManager);
    }
}
