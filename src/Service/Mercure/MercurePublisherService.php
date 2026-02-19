<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class MercurePublisherService
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Publish a sync progress update for a specific user.
     *
     * @param string      $userId   The user ID (UUID)
     * @param string      $syncType The type of sync (e.g., 'character-assets', 'corporation-assets', 'ansiblex')
     * @param string      $status   The status: 'started', 'in_progress', 'completed', 'error'
     * @param int|null    $progress The progress percentage (0-100)
     * @param string|null $message  A human-readable message describing the current step
     * @param array<string, mixed>|null  $data     Additional data to include in the update
     */
    public function publishSyncProgress(
        string $userId,
        string $syncType,
        string $status,
        ?int $progress = null,
        ?string $message = null,
        ?array $data = null,
    ): void {
        $topic = sprintf('/user/%s/sync/%s', $userId, $syncType);

        $payload = [
            'syncType' => $syncType,
            'status' => $status,
            'progress' => $progress,
            'message' => $message,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        try {
            $update = new Update(
                $topic,
                json_encode($payload, JSON_THROW_ON_ERROR),
                private: true,
            );

            $this->hub->publish($update);

            $this->logger->debug('Mercure update published', [
                'topic' => $topic,
                'status' => $status,
                'progress' => $progress,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish Mercure update', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish a sync started event.
     */
    public function syncStarted(string $userId, string $syncType, ?string $message = null): void
    {
        $this->publishSyncProgress($userId, $syncType, 'started', 0, $message ?? 'Starting...');
    }

    /**
     * Publish a sync progress event.
     */
    /** @param array<string, mixed>|null $data */
    public function syncProgress(string $userId, string $syncType, int $progress, string $message, ?array $data = null): void
    {
        $this->publishSyncProgress($userId, $syncType, 'in_progress', $progress, $message, $data);
    }

    /**
     * Publish a sync completed event.
     */
    /** @param array<string, mixed>|null $data */
    public function syncCompleted(string $userId, string $syncType, ?string $message = null, ?array $data = null): void
    {
        $this->publishSyncProgress($userId, $syncType, 'completed', 100, $message ?? 'Done', $data);
    }

    /**
     * Publish a sync error event.
     */
    public function syncError(string $userId, string $syncType, string $errorMessage): void
    {
        $this->publishSyncProgress($userId, $syncType, 'error', null, $errorMessage);
    }

    /**
     * Publish an alert for a specific user.
     */
    /** @param array<string, mixed> $data */
    public function publishAlert(string $userId, string $alertType, array $data): void
    {
        $topic = sprintf('/user/%s/alerts/%s', $userId, $alertType);

        $payload = [
            'alertType' => $alertType,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        try {
            $update = new Update(
                $topic,
                json_encode($payload, JSON_THROW_ON_ERROR),
                private: true,
            );

            $this->hub->publish($update);

            $this->logger->debug('Alert published', [
                'topic' => $topic,
                'alertType' => $alertType,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish alert', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish a notification to a user's notification topic.
     */
    /** @param array<string, mixed> $notificationData */
    public function publishNotification(string $userId, array $notificationData): void
    {
        $topic = sprintf('/user/%s/notifications', $userId);

        $payload = [
            'type' => 'notification',
            'notification' => $notificationData,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        try {
            $update = new Update(
                $topic,
                json_encode($payload, JSON_THROW_ON_ERROR),
                private: true,
            );

            $this->hub->publish($update);

            $this->logger->debug('Notification published', [
                'topic' => $topic,
                'category' => $notificationData['category'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish notification', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish an escalation event to group topics (corp/alliance).
     */
    /**
     * @param array<string, mixed> $escalationData
     */
    public function publishEscalationEvent(
        string $action,
        array $escalationData,
        ?int $corporationId,
        ?int $allianceId,
        string $visibility,
    ): void {
        $topics = [];
        if ($visibility === 'corp' && $corporationId !== null) {
            $topics[] = sprintf('/corp/%d/escalations', $corporationId);
        } elseif ($visibility === 'alliance' && $allianceId !== null) {
            $topics[] = sprintf('/alliance/%d/escalations', $allianceId);
        } elseif ($visibility === 'public') {
            $topics[] = '/public/escalations';
        }

        if (empty($topics)) {
            return;
        }

        $payload = [
            'action' => $action,
            'escalation' => $escalationData,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        try {
            foreach ($topics as $topic) {
                $update = new Update(
                    $topic,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    private: false,
                );
                $this->hub->publish($update);
            }

            $this->logger->debug('Escalation event published', [
                'action' => $action,
                'topics' => $topics,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish escalation event', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the list of sync topics for a user.
     * Used for generating subscriber JWT tokens.
     */
    /** @return list<string> */
    public static function getTopicsForUser(string $userId): array
    {
        return [
            sprintf('/user/%s/sync/character-assets', $userId),
            sprintf('/user/%s/sync/corporation-assets', $userId),
            sprintf('/user/%s/sync/ansiblex', $userId),
            sprintf('/user/%s/sync/industry-jobs', $userId),
            sprintf('/user/%s/sync/industry-job-completed', $userId),
            sprintf('/user/%s/sync/industry-project', $userId),
            sprintf('/user/%s/sync/pve', $userId),
            sprintf('/user/%s/sync/mining', $userId),
            sprintf('/user/%s/sync/wallet-transactions', $userId),
            sprintf('/user/%s/sync/market-structure', $userId),
            sprintf('/user/%s/sync/planetary', $userId),
            sprintf('/user/%s/sync/profit-tracker', $userId),
            sprintf('/user/%s/sync/public-contracts', $userId),
            sprintf('/user/%s/sync/admin-sync', $userId),
            sprintf('/user/%s/alerts/planetary-expiry', $userId),
            sprintf('/user/%s/alerts/market-price', $userId),
            sprintf('/user/%s/notifications', $userId),
        ];
    }

    /**
     * Get group topics for a user (corp/alliance escalations).
     * These are non-private topics for shared data.
     */
    /** @return list<string> */
    public static function getGroupTopics(?int $corporationId, ?int $allianceId): array
    {
        $topics = ['/public/escalations'];

        if ($corporationId !== null) {
            $topics[] = sprintf('/corp/%d/escalations', $corporationId);
        }
        if ($allianceId !== null) {
            $topics[] = sprintf('/alliance/%d/escalations', $allianceId);
        }

        return $topics;
    }
}
