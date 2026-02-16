<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NotificationDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserNotificationPreferenceRepository $preferenceRepository,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly WebPushService $webPushService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch a notification to a user.
     *
     * Checks user preferences, persists the notification, publishes via Mercure,
     * and optionally sends a Web Push notification.
     *
     * @param array<string, mixed>|null $data
     */
    public function dispatch(
        User $user,
        string $category,
        string $level,
        string $title,
        string $message,
        ?array $data = null,
        ?string $route = null,
    ): void {
        $userId = $user->getId()?->toRfc4122();
        if ($userId === null) {
            return;
        }

        // Check user preferences
        $preference = $this->preferenceRepository->findByUserAndCategory($user, $category);

        // If preference exists and is disabled, skip
        if ($preference !== null && !$preference->isEnabled()) {
            return;
        }

        // Create and persist notification
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setCategory($category);
        $notification->setLevel($level);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setData($data);
        $notification->setRoute($route);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        // Publish via Mercure
        $this->mercurePublisher->publishNotification($userId, [
            'id' => $notification->getId()?->toRfc4122(),
            'category' => $category,
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'route' => $route,
            'isRead' => false,
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);

        // Send Web Push if enabled for this category
        if ($preference !== null && $preference->isPushEnabled()) {
            try {
                $this->webPushService->send($user, $notification);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send web push notification', [
                    'userId' => $userId,
                    'category' => $category,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
