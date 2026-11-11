<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckMoonChunkAlerts;
use App\Service\Admin\SyncTracker;
use App\Service\MoonChunk\MoonChunkAlertSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckMoonChunkAlertsHandler
{
    private const string SYNC_KEY = 'moon-chunk-alerts';

    public function __construct(
        private MoonChunkAlertSender $sender,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(CheckMoonChunkAlerts $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);

        try {
            $this->sender->sendDueAlerts();
            $this->syncTracker->complete(self::SYNC_KEY);
        } catch (\Throwable $failure) {
            $this->syncTracker->fail(self::SYNC_KEY, $failure->getMessage());

            throw $failure;
        }
    }
}
