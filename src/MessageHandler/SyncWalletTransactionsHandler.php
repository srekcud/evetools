<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncWalletTransactions;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\WalletTransactionSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncWalletTransactionsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private WalletTransactionSyncService $walletTransactionSyncService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncWalletTransactions $message): void
    {
        $this->syncTracker->start('wallet');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $synced = 0;

            foreach ($characters as $character) {
                $token = $character->getEveToken();
                if ($token === null || !$token->hasScope('esi-wallet.read_character_wallet.v1')) {
                    continue;
                }

                try {
                    $this->walletTransactionSyncService->syncCharacterTransactions($character);
                    $synced++;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to sync wallet transactions', [
                        'characterName' => $character->getName(),
                        'error' => $e->getMessage(),
                    ]);
                }

                usleep(500_000);
            }

            $this->logger->info('Wallet transactions sync completed', [
                'synced' => $synced,
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('wallet', "{$synced}/" . count($characters) . ' chars synced');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('wallet', $e->getMessage());
            throw $e;
        }
    }
}
