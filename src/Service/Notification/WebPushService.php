<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class WebPushService
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::VAPID_PUBLIC_KEY)%')]
        private readonly ?string $vapidPublicKey,
        #[Autowire('%env(default::VAPID_PRIVATE_KEY)%')]
        private readonly ?string $vapidPrivateKey,
        #[Autowire('%env(default::VAPID_SUBJECT)%')]
        private readonly ?string $vapidSubject,
    ) {
    }

    public function send(User $user, Notification $notification): void
    {
        if (!$this->vapidPublicKey || !$this->vapidPrivateKey || !$this->vapidSubject) {
            $this->logger->debug('Web Push: VAPID keys not configured, skipping push notification');
            return;
        }

        $subscriptions = $this->subscriptionRepository->findByUser($user);

        if (empty($subscriptions)) {
            return;
        }

        try {
            $auth = [
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ];

            $webPush = new WebPush($auth);

            $payload = json_encode([
                'title' => $notification->getTitle(),
                'body' => $notification->getMessage(),
                'category' => $notification->getCategory(),
                'level' => $notification->getLevel(),
                'route' => $notification->getRoute(),
                'data' => $notification->getData(),
                'notificationId' => $notification->getId()?->toRfc4122(),
            ], JSON_THROW_ON_ERROR);

            /** @var array<int, PushSubscription> $indexedSubscriptions */
            $indexedSubscriptions = array_values($subscriptions);

            foreach ($indexedSubscriptions as $pushSub) {
                $subscription = Subscription::create([
                    'endpoint' => $pushSub->getEndpoint(),
                    'publicKey' => $pushSub->getPublicKey(),
                    'authToken' => $pushSub->getAuthToken(),
                ]);

                $webPush->queueNotification($subscription, $payload);
            }

            $index = 0;
            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    $this->logger->warning('Web Push delivery failed', [
                        'endpoint' => $report->getEndpoint(),
                        'reason' => $report->getReason(),
                    ]);

                    // Remove expired/invalid subscriptions
                    if ($report->isSubscriptionExpired() && isset($indexedSubscriptions[$index])) {
                        $this->entityManager->remove($indexedSubscriptions[$index]);
                        $this->logger->info('Removed expired push subscription', [
                            'endpoint' => $report->getEndpoint(),
                        ]);
                    }
                }
                $index++;
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Web Push sending failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
