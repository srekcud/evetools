<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TriggerJitaMarketSync;
use App\Service\JitaMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TriggerJitaMarketSyncHandler
{
    public function __construct(
        private JitaMarketService $jitaMarketService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerJitaMarketSync $message): void
    {
        $this->logger->info('Starting Jita market sync');

        try {
            $result = $this->jitaMarketService->syncJitaMarket();

            $this->logger->info('Jita market sync completed', [
                'typeCount' => $result['typeCount'],
                'duration' => $result['duration'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Jita market sync failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
