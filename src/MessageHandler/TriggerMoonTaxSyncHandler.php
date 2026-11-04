<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncMoonTax;
use App\Message\TriggerMoonTaxSync;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\MoonTaxSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerMoonTaxSyncHandler
{
    public function __construct(
        private MoonTaxRefinerySettingRepository $settingRepository,
        private MessageBusInterface $messageBus,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerMoonTaxSync $message): void
    {
        $this->syncTracker->start(MoonTaxSyncService::SYNC_TYPE);

        try {
            $corporationIds = $this->settingRepository->findOpenCorporationIds();

            foreach ($corporationIds as $corporationId) {
                $this->messageBus->dispatch(new SyncMoonTax($corporationId));
            }

            $this->syncTracker->complete(MoonTaxSyncService::SYNC_TYPE, count($corporationIds) . ' corporations dispatched');
        } catch (\Throwable $e) {
            $this->syncTracker->fail(MoonTaxSyncService::SYNC_TYPE, $e->getMessage());
            throw $e;
        }
    }
}
