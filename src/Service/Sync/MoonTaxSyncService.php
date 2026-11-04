<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\MoonTaxRefinerySetting;
use App\Entity\MoonTaxSyncState;
use App\Enum\MoonTaxSyncFailure;
use App\Repository\CharacterRepository;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Repository\MoonTaxSyncStateRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MoonTax\MoonTaxStatementCloser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

class MoonTaxSyncService
{
    public const string SYNC_TYPE = 'moon-tax';

    private const int LOCK_TTL_SECONDS = 900;

    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly MoonTaxRefinerySettingRepository $settingRepository,
        private readonly MoonTaxSyncStateRepository $syncStateRepository,
        private readonly MoonObserverLedgerSync $observerLedgerSync,
        private readonly CorporationWalletJournalSync $walletJournalSync,
        private readonly MoonTaxStatementCloser $statementCloser,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(int $corporationId): MoonTaxSyncState
    {
        $openSettings = $this->settingRepository->findOpenOfCorporation($corporationId);
        if ($openSettings === []) {
            return $this->stateOf($corporationId);
        }

        $lock = $this->lockFactory->createLock(sprintf('%s-%d', self::SYNC_TYPE, $corporationId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $this->logger->info('Moon tax sync already running for the corporation', ['corporation_id' => $corporationId]);

            return $this->stateOf($corporationId);
        }

        try {
            return $this->synchronize($corporationId, $openSettings);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param non-empty-list<MoonTaxRefinerySetting> $openSettings
     */
    private function synchronize(int $corporationId, array $openSettings): MoonTaxSyncState
    {
        $characters = array_values($this->characterRepository->findByCorporationId($corporationId));
        $userIds = $this->userIdsOf($characters);
        foreach ($userIds as $userId) {
            $this->mercurePublisher->syncStarted($userId, self::SYNC_TYPE, 'Reading the moon tax data...');
        }

        try {
            $observersFailure = $this->observerLedgerSync->sync($corporationId, $characters, $openSettings);
            $walletFailure = $this->walletJournalSync->sync($corporationId, $characters, $openSettings);
            $state = $this->recordOutcome($corporationId, $observersFailure, $walletFailure);
            if ($observersFailure === null) {
                $this->statementCloser->close($corporationId);
            }
        } catch (\Throwable $exception) {
            foreach ($userIds as $userId) {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $exception->getMessage());
            }

            throw $exception;
        }

        foreach ($userIds as $userId) {
            if ($observersFailure === null && $walletFailure === null) {
                $this->mercurePublisher->syncCompleted($userId, self::SYNC_TYPE, 'Moon tax up to date');
            } else {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $this->failureMessage($observersFailure, $walletFailure));
            }
        }

        return $state;
    }

    private function stateOf(int $corporationId): MoonTaxSyncState
    {
        return $this->syncStateRepository->findOneBy(['corporationId' => $corporationId])
            ?? (new MoonTaxSyncState())->setCorporationId($corporationId);
    }

    private function recordOutcome(int $corporationId, ?MoonTaxSyncFailure $observersFailure, ?MoonTaxSyncFailure $walletFailure): MoonTaxSyncState
    {
        $now = $this->clock->now();
        $state = $this->stateOf($corporationId);

        $state->setObserversFailure($observersFailure)->setWalletFailure($walletFailure);
        if ($observersFailure === null) {
            $state->setObserversSyncedAt($now);
        }
        if ($walletFailure === null) {
            $state->setWalletSyncedAt($now);
        }

        $this->entityManager->persist($state);
        $this->entityManager->flush();

        return $state;
    }

    /**
     * @param list<Character> $characters
     *
     * @return list<string>
     */
    private function userIdsOf(array $characters): array
    {
        $userIds = [];
        foreach ($characters as $character) {
            $userId = $character->getUser()?->getId()?->toRfc4122();
            if ($userId !== null) {
                $userIds[$userId] = $userId;
            }
        }

        return array_values($userIds);
    }

    private function failureMessage(?MoonTaxSyncFailure $observersFailure, ?MoonTaxSyncFailure $walletFailure): string
    {
        return sprintf(
            'Moon tax sync incomplete: ledger %s, payments %s',
            $observersFailure === null ? 'ok' : $observersFailure->value,
            $walletFailure === null ? 'ok' : $walletFailure->value,
        );
    }
}
