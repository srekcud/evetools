<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncMoonTax;
use App\Service\Sync\MoonTaxSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncMoonTaxHandler
{
    public function __construct(
        private MoonTaxSyncService $syncService,
    ) {
    }

    public function __invoke(SyncMoonTax $message): void
    {
        $this->syncService->sync($message->corporationId);
    }
}
