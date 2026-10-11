<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

final class NotificationRepositoryTest extends IntegrationTestCase
{
    private const string PURGE_THRESHOLD = '2026-10-04 06:00:00';

    private NotificationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(NotificationRepository::class);
    }

    public function testDeleteOlderThanRemovesReadNotificationsCreatedStrictlyBeforeThresholdForEveryUser(): void
    {
        $firstUser = $this->createUser();
        $secondUser = $this->createUser();
        $this->createNotification($firstUser, 'Nine days old', '2026-09-25 06:00:00', isRead: true);
        $this->createNotification($secondUser, 'One second too old', '2026-10-04 05:59:59', isRead: true);
        $this->createNotification($firstUser, 'Exactly at threshold', self::PURGE_THRESHOLD, isRead: true);
        $this->createNotification($secondUser, 'Two days old', '2026-10-09 17:30:00', isRead: true);
        $this->flushAndClear();

        $deleted = $this->repository->deleteOlderThan(new \DateTimeImmutable(self::PURGE_THRESHOLD));

        self::assertSame(2, $deleted);
        self::assertSame(['Exactly at threshold', 'Two days old'], $this->remainingTitles());
    }

    #[Group('notif-audit-P2-9')]
    public function testDeleteOlderThanCurrentlyRemovesUnreadNotificationsTooNotifAuditP29(): void
    {
        $user = $this->createUser();
        $this->createNotification($user, 'Unread extractor expired', '2026-09-28 11:15:00', isRead: false);
        $this->createNotification($user, 'Read job completed', '2026-09-27 08:45:00', isRead: true);
        $this->createNotification($user, 'Unread recent price alert', '2026-10-10 21:05:00', isRead: false);
        $this->flushAndClear();

        $deleted = $this->repository->deleteOlderThan(new \DateTimeImmutable(self::PURGE_THRESHOLD));

        self::assertSame(2, $deleted);
        self::assertSame(['Unread recent price alert'], $this->remainingTitles());
    }

    private function createNotification(User $user, string $title, string $createdAt, bool $isRead): void
    {
        $notification = (new Notification())
            ->setUser($user)
            ->setCategory(Notification::CATEGORY_PLANETARY)
            ->setLevel(Notification::LEVEL_INFO)
            ->setTitle($title)
            ->setMessage($title . ' message')
            ->setIsRead($isRead);
        (new \ReflectionProperty(Notification::class, 'createdAt'))->setValue($notification, new \DateTimeImmutable($createdAt));
        $this->em->persist($notification);
    }

    /** @return list<string> */
    private function remainingTitles(): array
    {
        $titles = array_map(
            static fn (Notification $notification): string => $notification->getTitle(),
            $this->repository->findAll(),
        );
        sort($titles);

        return $titles;
    }
}
