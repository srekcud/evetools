<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckCourierContracts;
use App\Service\Admin\SyncTracker;
use App\Service\Industry\Project\Courier\CourierFailureNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckCourierContractsHandler
{
    private const string SYNC_KEY = 'courier-contracts';

    public function __construct(
        private CourierFailureNotifier $failureNotifier,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(CheckCourierContracts $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);

        try {
            $alerts = $this->failureNotifier->notifyFailedContracts();
            $this->syncTracker->complete(self::SYNC_KEY, sprintf('%d alert(s) sent', $alerts));
        } catch (\Throwable $failure) {
            $this->syncTracker->fail(self::SYNC_KEY, $failure->getMessage());

            throw $failure;
        }
    }
}
