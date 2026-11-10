<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Service\ESI\EsiClient;
use App\Service\Industry\Project\ProjectPaymentReference;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WalletJournalSyncService
{
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';
    private const string SYNC_TYPE = 'wallet-journal';

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedWalletJournalEntryRepository $journalRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncCharacterJournal(Character $character): void
    {
        $token = $character->getEveToken();
        if ($token === null || !$token->hasScope(self::WALLET_SCOPE)) {
            return;
        }

        $userId = $character->getUser()?->getId()?->toRfc4122();
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, self::SYNC_TYPE, 'Fetching wallet journal...');
        }

        try {
            $lines = $this->keptLines($this->esiClient->getPaginated("/characters/{$character->getEveCharacterId()}/wallet/journal/", $token));
            $knownIds = array_flip($this->journalRepository->findExistingEntryIds($character, array_map(static fn (array $line): int => (int) $line['id'], $lines)));

            $stored = 0;
            foreach ($lines as $line) {
                if (isset($knownIds[(int) $line['id']])) {
                    continue;
                }

                $this->entityManager->persist($this->entryOf($character, $line));
                ++$stored;
            }
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync the wallet journal', [
                'characterName' => $character->getName(),
                'error' => $e->getMessage(),
            ]);
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $e->getMessage());
            }

            return;
        }

        if ($userId !== null) {
            $this->mercurePublisher->syncCompleted($userId, self::SYNC_TYPE, sprintf('%d journal lines stored', $stored));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $journal
     *
     * @return list<array<string, mixed>>
     */
    private function keptLines(array $journal): array
    {
        return array_values(array_filter(
            $journal,
            fn (array $line): bool => \in_array($line['ref_type'] ?? null, CachedWalletJournalEntry::CONTRACT_FEE_REF_TYPES, true)
                || $this->isIncomingPaymentWithProjectReference($line),
        ));
    }

    /**
     * @param array<string, mixed> $line
     */
    private function isIncomingPaymentWithProjectReference(array $line): bool
    {
        return ($line['ref_type'] ?? null) === CachedWalletJournalEntry::PLAYER_DONATION
            && (float) ($line['amount'] ?? 0.0) > 0.0
            && \is_string($line['reason'] ?? null)
            && ProjectPaymentReference::appearsIn($line['reason']);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function entryOf(Character $character, array $line): CachedWalletJournalEntry
    {
        $isDonation = $line['ref_type'] === CachedWalletJournalEntry::PLAYER_DONATION;

        return (new CachedWalletJournalEntry())
            ->setCharacter($character)
            ->setJournalEntryId((int) $line['id'])
            ->setRefType((string) $line['ref_type'])
            ->setAmount((float) $line['amount'])
            ->setDate(new \DateTimeImmutable((string) $line['date']))
            ->setContextId(isset($line['context_id']) ? (int) $line['context_id'] : null)
            ->setContextIdType(isset($line['context_id_type']) ? (string) $line['context_id_type'] : null)
            ->setReason($isDonation ? (string) $line['reason'] : null)
            ->setFirstPartyId($isDonation && isset($line['first_party_id']) ? (int) $line['first_party_id'] : null);
    }
}
