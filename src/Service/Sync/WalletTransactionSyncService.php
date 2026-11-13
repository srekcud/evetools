<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\ESI\EsiClient;
use App\Service\Industry\Project\AutomaticPurchaseLinker;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

class WalletTransactionSyncService
{
    private const int WALLET_CACHE_TTL_SECONDS = 3600;
    private const int WALLET_RETRY_DELAY_SECONDS = 900;

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedWalletTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly AutomaticPurchaseLinker $purchaseLinker,
        private readonly CharacterWalletReadingRepository $readingRepository,
        private readonly ClockInterface $clock,
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
        $newTransactions = [];
        $fromId = null;
        $maxPages = 50; // Safety limit
        $pageExpirations = [];

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $url = "/characters/{$characterId}/wallet/transactions/";
                if ($fromId !== null) {
                    $url .= "?from_id={$fromId}";
                }

                $response = $this->esiClient->getWithExpiry($url, $token);
                $transactions = $response->items;
                $pageExpirations[] = $response->expiresAt;

                if (empty($transactions)) {
                    break;
                }

                $totalCount += count($transactions);
                $lowestId = null;

                // Batch check: collect all transaction IDs and query once
                $pageTransactionIds = array_map(
                    fn (array $txData) => (int) $txData['transaction_id'],
                    $transactions,
                );
                $existingIds = array_flip(
                    $this->transactionRepository->findExistingTransactionIds($pageTransactionIds),
                );

                $hasNewTransactions = false;

                foreach ($transactions as $txData) {
                    $transactionId = (int) $txData['transaction_id'];

                    // Track lowest ID for pagination
                    if ($lowestId === null || $transactionId < $lowestId) {
                        $lowestId = $transactionId;
                    }

                    if (isset($existingIds[$transactionId])) {
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
                    $tx->setDate(new \DateTimeImmutable($txData['date']));

                    $this->entityManager->persist($tx);
                    $newTransactions[] = $tx;
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

            $this->recordSuccessfulReading($character, $pageExpirations);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch wallet transactions from ESI', [
                'characterName' => $character->getName(),
                'error' => $e->getMessage(),
                'page' => $page,
            ]);

            $this->recordFailedReading($character);

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

        if ($newTransactions !== []) {
            $this->purchaseLinker->linkNewPurchases($character, $newTransactions);
        }

        if ($userId !== null) {
            $this->mercurePublisher->syncCompleted(
                $userId,
                'wallet-transactions',
                sprintf('%d transactions fetched (%d new)', $totalCount, $newCount),
            );
        }
    }

    /**
     * @param list<?\DateTimeImmutable> $pageExpirations
     */
    private function recordSuccessfulReading(Character $character, array $pageExpirations): void
    {
        $readAt = $this->clock->now();
        $nextReadAt = $pageExpirations === [] || in_array(null, $pageExpirations, true)
            ? $readAt->modify(sprintf('+%d seconds', self::WALLET_CACHE_TTL_SECONDS))
            : min($pageExpirations);

        $this->readingRepository->record($character, $readAt, $nextReadAt);
    }

    private function recordFailedReading(Character $character): void
    {
        $nextReadAt = $this->clock->now()->modify(sprintf('+%d seconds', self::WALLET_RETRY_DELAY_SECONDS));

        try {
            $this->readingRepository->record($character, null, $nextReadAt);
        } catch (\Throwable $recordingError) {
            $this->logger->error('Failed to record the failed wallet reading', [
                'characterName' => $character->getName(),
                'error' => $recordingError->getMessage(),
            ]);
        }
    }
}
