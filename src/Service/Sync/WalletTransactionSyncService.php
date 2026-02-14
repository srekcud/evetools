<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Repository\CachedWalletTransactionRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WalletTransactionSyncService
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedWalletTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncCharacterTransactions(Character $character): void
    {
        $token = $character->getEveToken();
        if ($token === null || !$token->hasScope('esi-wallet.read_character_wallet.v1')) {
            return;
        }

        $userId = $character->getUser()?->getId()?->toRfc4122();
        $characterId = $character->getEveCharacterId();

        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'wallet-transactions', 'Fetching wallet transactions...');
        }

        $totalCount = 0;
        $newCount = 0;
        $fromId = null;
        $maxPages = 50; // Safety limit

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $url = "/characters/{$characterId}/wallet/transactions/";
                if ($fromId !== null) {
                    $url .= "?from_id={$fromId}";
                }

                $transactions = $this->esiClient->get($url, $token);

                if (empty($transactions)) {
                    break;
                }

                $totalCount += count($transactions);
                $lowestId = null;
                $hasNewTransactions = false;

                foreach ($transactions as $txData) {
                    $transactionId = (int) $txData['transaction_id'];

                    // Track lowest ID for pagination
                    if ($lowestId === null || $transactionId < $lowestId) {
                        $lowestId = $transactionId;
                    }

                    $existing = $this->transactionRepository->findByTransactionId($transactionId);
                    if ($existing !== null) {
                        continue;
                    }

                    $hasNewTransactions = true;

                    $tx = new CachedWalletTransaction();
                    $tx->setCharacter($character);
                    $tx->setTransactionId($transactionId);
                    $tx->setTypeId($txData['type_id']);
                    $tx->setQuantity($txData['quantity']);
                    $tx->setUnitPrice((float) $txData['unit_price']);
                    $tx->setIsBuy($txData['is_buy']);
                    $tx->setLocationId((int) $txData['location_id']);
                    $tx->setClientId((int) $txData['client_id']);
                    $tx->setDate(new \DateTimeImmutable($txData['date']));

                    $this->entityManager->persist($tx);
                    $newCount++;
                }

                $this->entityManager->flush();

                // Stop if no new transactions (we've caught up with existing data)
                if (!$hasNewTransactions) {
                    break;
                }

                // Set from_id for next page
                $fromId = $lowestId;

                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress(
                        $userId,
                        'wallet-transactions',
                        0,
                        sprintf('%d transactions fetched...', $totalCount),
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch wallet transactions from ESI', [
                'characterName' => $character->getName(),
                'error' => $e->getMessage(),
                'page' => $page,
            ]);

            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'wallet-transactions', $e->getMessage());
            }

            return;
        }

        $this->logger->info('Wallet transactions synced', [
            'characterName' => $character->getName(),
            'total' => $totalCount,
            'new' => $newCount,
        ]);

        if ($userId !== null) {
            $this->mercurePublisher->syncCompleted(
                $userId,
                'wallet-transactions',
                sprintf('%d transactions fetched (%d new)', $totalCount, $newCount),
            );
        }
    }
}
