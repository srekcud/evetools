<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1391')]
final class NotificationRepositoryFindByUserAndCategorySinceTest extends IntegrationTestCase
{
    private const string SINCE = '2026-11-26 10:00:00';

    private NotificationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(NotificationRepository::class);
    }

    public function testFindsNotificationsOfTheUserInTheCategoryCreatedAtOrAfterTheBound(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->createNotification($user, Notification::CATEGORY_PLANETARY, 'One second too old', '2026-11-26 09:59:59');
        $this->createNotification($user, Notification::CATEGORY_PLANETARY, 'Exactly at bound', self::SINCE);
        $this->createNotification($user, Notification::CATEGORY_PLANETARY, 'Recent planetary alert', '2026-11-26 11:30:00');
        $this->createNotification($user, Notification::CATEGORY_PRICE, 'Recent price alert', '2026-11-26 11:45:00');
        $this->createNotification($otherUser, Notification::CATEGORY_PLANETARY, 'Other user planetary alert', '2026-11-26 11:50:00');
        $this->flushAndClear();

        $found = $this->repository->findByUserAndCategorySince(
            $this->em->getReference(User::class, $user->getId()),
            Notification::CATEGORY_PLANETARY,
            new \DateTimeImmutable(self::SINCE),
        );

        self::assertSame(['Exactly at bound', 'Recent planetary alert'], $this->sortedTitles($found));
    }

    public function testReturnsAnEmptyListWhenNothingMatches(): void
    {
        $user = $this->createUser();
        $this->createNotification($user, Notification::CATEGORY_PLANETARY, 'Old planetary alert', '2026-11-25 08:00:00');
        $this->flushAndClear();

        $found = $this->repository->findByUserAndCategorySince(
            $this->em->getReference(User::class, $user->getId()),
            Notification::CATEGORY_PLANETARY,
            new \DateTimeImmutable(self::SINCE),
        );

        self::assertSame([], $found);
    }

    private function createNotification(User $user, string $category, string $title, string $createdAt): void
    {
        $notification = (new Notification())
            ->setUser($user)
            ->setCategory($category)
            ->setLevel(Notification::LEVEL_INFO)
            ->setTitle($title)
            ->setMessage($title.' message')
            ->setData(['kind' => 'data_stale', 'subject' => 'stale:colony'])
            ->setIsRead(false);
        (new \ReflectionProperty(Notification::class, 'createdAt'))->setValue($notification, new \DateTimeImmutable($createdAt));
        $this->em->persist($notification);
    }

    private function sortedTitles(array $notifications): array
    {
        $titles = array_map(
            static fn (Notification $notification): string => $notification->getTitle(),
            $notifications,
        );
        sort($titles);

        return $titles;
    }
}
