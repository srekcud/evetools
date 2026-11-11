<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckMiningSiteDepletionAlerts;
use App\Service\Admin\SyncTracker;
use App\Service\MiningSite\MiningSiteDepletionAlertSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckMiningSiteDepletionAlertsHandler
{
    private const string SYNC_KEY = 'mining-site-depletion-alerts';

    public function __construct(
        private MiningSiteDepletionAlertSender $sender,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(CheckMiningSiteDepletionAlerts $message): void
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
