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
     * @param array|null  $data     Additional data to include in the update
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
        $this->publishSyncProgress($userId, $syncType, 'started', 0, $message ?? 'Démarrage...');
    }

    /**
     * Publish a sync progress event.
     */
    public function syncProgress(string $userId, string $syncType, int $progress, string $message, ?array $data = null): void
    {
        $this->publishSyncProgress($userId, $syncType, 'in_progress', $progress, $message, $data);
    }

    /**
     * Publish a sync completed event.
     */
    public function syncCompleted(string $userId, string $syncType, ?string $message = null, ?array $data = null): void
    {
        $this->publishSyncProgress($userId, $syncType, 'completed', 100, $message ?? 'Terminé', $data);
    }

    /**
     * Publish a sync error event.
     */
    public function syncError(string $userId, string $syncType, string $errorMessage): void
    {
        $this->publishSyncProgress($userId, $syncType, 'error', null, $errorMessage);
    }

    /**
     * Get the list of sync topics for a user.
     * Used for generating subscriber JWT tokens.
     */
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
            sprintf('/user/%s/sync/market-jita', $userId),
            sprintf('/user/%s/sync/market-structure', $userId),
        ];
    }
}
