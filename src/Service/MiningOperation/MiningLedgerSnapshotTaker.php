<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperation;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class MiningLedgerSnapshotTaker
{
    public const string SYNC_TYPE = 'mining-operation-snapshot';

    private const int LEDGER_EXPIRY_MARGIN_SECONDS = 5;
    private const int LEDGER_CACHE_SECONDS = 600;
    private const int LEDGER_RETENTION_DAYS = 30;
    private const int PERCENT = 100;
    private const array REVOKED_TOKEN_HTTP_STATUSES = [401, 403];

    public function __construct(
        private MiningLedgerReader $reader,
        private EntityManagerInterface $entityManager,
        private MiningLedgerSnapshotDispatcher $dispatcher,
        private MercurePublisherService $mercurePublisher,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private MiningOperationNotifier $notifier,
        private MiningCharacterShipReader $shipReader,
    ) {
    }

    public function take(MiningLedgerSnapshot $snapshot): void
    {
        if ($snapshot->getStatus()->isSettled()) {
            return;
        }

        $now = $this->clock->now();
        $readyAt = $snapshot->getReadyAt();
        if ($snapshot->getStatus() === MiningLedgerSnapshotStatus::Waiting && $readyAt !== null && $readyAt > $now) {
            $this->dispatcher->dispatch($snapshot);

            return;
        }

        $operationCharacter = $snapshot->getOperationCharacter();
        $operation = $operationCharacter->getMember()->getOperation();

        if ($snapshot->getKind() === MiningLedgerSnapshotKind::End && $this->isBeyondLedgerRetention($operation, $now)) {
            $this->settleWithoutLedger($snapshot, MiningLedgerSnapshotStatus::OutOfWindow, 'The end snapshot is beyond the 30-day ledger');

            return;
        }

        $this->publishStarted($snapshot);

        try {
            $reading = $this->reader->read($operationCharacter->getCharacter());
        } catch (EveAuthRequiredException $authRequired) {
            $this->settleWithoutLedger($snapshot, MiningLedgerSnapshotStatus::ReconnectRequired, $authRequired->getMessage());

            return;
        } catch (EsiApiException $esiError) {
            $this->settleWithoutLedger(
                $snapshot,
                in_array($esiError->statusCode, self::REVOKED_TOKEN_HTTP_STATUSES, true) ? MiningLedgerSnapshotStatus::ReconnectRequired : MiningLedgerSnapshotStatus::Failed,
                $esiError->getMessage(),
            );

            return;
        }

        if ($snapshot->getStatus() === MiningLedgerSnapshotStatus::Scheduling) {
            $snapshot->schedule($this->readTimeAfter($snapshot, $reading));
            $this->entityManager->flush();
            $this->dispatcher->dispatch($snapshot);
            $this->publishReadTimeKnown($snapshot);

            return;
        }

        $snapshot->take($now, $reading);
        $shipData = $snapshot->getKind() === MiningLedgerSnapshotKind::Start ? ['shipTypeId' => $this->recordShipOf($snapshot)] : [];
        $this->entityManager->flush();
        $this->publishCompleted($snapshot, $shipData);
        $this->notifyWhenEndLedgerSettled($snapshot);
    }

    private function recordShipOf(MiningLedgerSnapshot $snapshot): ?int
    {
        $operationCharacter = $snapshot->getOperationCharacter();
        $character = $operationCharacter->getCharacter();
        $shipTypeId = $this->shipReader->read($character);
        $operationCharacter->recordShip($shipTypeId);

        if ($shipTypeId === null) {
            $this->logger->warning('Ship of a mining operation character could not be read', [
                'operationId' => PersistedUuid::of($operationCharacter->getMember()->getOperation()->getId()),
                'character' => $character->getName(),
                'eveCharacterId' => $character->getEveCharacterId(),
            ]);
        }

        return $shipTypeId;
    }

    private function settleWithoutLedger(MiningLedgerSnapshot $snapshot, MiningLedgerSnapshotStatus $status, string $reason): void
    {
        $snapshot->fail($status, $status === MiningLedgerSnapshotStatus::Failed ? $reason : null);
        $this->entityManager->flush();

        $operationCharacter = $snapshot->getOperationCharacter();
        $this->logger->warning('Mining ledger snapshot could not be taken', [
            'operationId' => PersistedUuid::of($operationCharacter->getMember()->getOperation()->getId()),
            'character' => $operationCharacter->getCharacter()->getName(),
            'eveCharacterId' => $operationCharacter->getCharacter()->getEveCharacterId(),
            'kind' => $snapshot->getKind()->value,
            'status' => $status->value,
            'error' => $reason,
        ]);

        $this->publishError($snapshot, $reason);
        $this->notifyWhenEndLedgerSettled($snapshot);
    }

    private function notifyWhenEndLedgerSettled(MiningLedgerSnapshot $snapshot): void
    {
        if ($snapshot->getKind() === MiningLedgerSnapshotKind::End) {
            $this->notifier->endLedgerSettled($snapshot->getOperationCharacter()->getMember()->getOperation());
        }
    }

    private function isBeyondLedgerRetention(MiningOperation $operation, \DateTimeImmutable $readAt): bool
    {
        $startedAt = $operation->getStartedAt() ?? throw new \LogicException('An operation with an end snapshot has started');
        $utc = new \DateTimeZone('UTC');

        $daysSinceStart = $startedAt->setTimezone($utc)->setTime(0, 0)->diff($readAt->setTimezone($utc)->setTime(0, 0))->days;

        return $daysSinceStart >= self::LEDGER_RETENTION_DAYS;
    }

    private function readTimeAfter(MiningLedgerSnapshot $snapshot, MiningLedgerReading $reading): \DateTimeImmutable
    {
        if ($reading->expiresAt === null) {
            return $snapshot->getReferenceAt()->modify(sprintf('+%d seconds', self::LEDGER_CACHE_SECONDS));
        }

        return $reading->expiresAt->modify(sprintf('+%d seconds', self::LEDGER_EXPIRY_MARGIN_SECONDS));
    }

    private function publishStarted(MiningLedgerSnapshot $snapshot): void
    {
        foreach ($this->audienceOf($snapshot) as $userId) {
            $this->mercurePublisher->syncStarted($userId, self::SYNC_TYPE, $this->messageAbout($snapshot), $this->progressDataOf($snapshot));
        }
    }

    private function publishReadTimeKnown(MiningLedgerSnapshot $snapshot): void
    {
        foreach ($this->audienceOf($snapshot) as $userId) {
            $this->mercurePublisher->syncProgress($userId, self::SYNC_TYPE, $this->progressPercentOf($snapshot), $this->messageAbout($snapshot), $this->progressDataOf($snapshot));
        }
    }

    /**
     * @param array<string, mixed> $extraData
     */
    private function publishCompleted(MiningLedgerSnapshot $snapshot, array $extraData): void
    {
        foreach ($this->audienceOf($snapshot) as $userId) {
            $this->mercurePublisher->syncCompleted($userId, self::SYNC_TYPE, $this->messageAbout($snapshot), [...$this->progressDataOf($snapshot), ...$extraData]);
        }
    }

    private function publishError(MiningLedgerSnapshot $snapshot, string $reason): void
    {
        foreach ($this->audienceOf($snapshot) as $userId) {
            $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $reason, $this->progressDataOf($snapshot));
        }
    }

    /**
     * @return list<string>
     */
    private function audienceOf(MiningLedgerSnapshot $snapshot): array
    {
        return MiningOperationAudience::participantIdsOf($snapshot->getOperationCharacter()->getMember()->getOperation());
    }

    private function messageAbout(MiningLedgerSnapshot $snapshot): string
    {
        return sprintf('%s ledger snapshot of %s: %s', $snapshot->getKind()->value, $snapshot->getOperationCharacter()->getCharacter()->getName(), $snapshot->getStatus()->value);
    }

    private function progressPercentOf(MiningLedgerSnapshot $snapshot): int
    {
        [$settled, $total] = $this->settledAndTotalOf($snapshot);

        return intdiv($settled * self::PERCENT, $total);
    }

    /**
     * @return array<string, mixed>
     */
    private function progressDataOf(MiningLedgerSnapshot $snapshot): array
    {
        $operationCharacter = $snapshot->getOperationCharacter();
        [$settled, $total] = $this->settledAndTotalOf($snapshot);

        return [
            'operationId' => PersistedUuid::of($operationCharacter->getMember()->getOperation()->getId()),
            'operationCharacterId' => PersistedUuid::of($operationCharacter->getId()),
            'characterName' => $operationCharacter->getCharacter()->getName(),
            'kind' => $snapshot->getKind()->value,
            'status' => $snapshot->getStatus()->value,
            'readyAt' => $snapshot->getReadyAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'settled' => $settled,
            'total' => $total,
        ];
    }

    /**
     * @return array{int, int}
     */
    private function settledAndTotalOf(MiningLedgerSnapshot $snapshot): array
    {
        $settled = 0;
        $total = 0;
        foreach ($snapshot->getOperationCharacter()->getMember()->getOperation()->getMembers() as $member) {
            foreach ($member->getCharacters() as $operationCharacter) {
                $sibling = $operationCharacter->snapshotOf($snapshot->getKind());
                if ($sibling === null) {
                    continue;
                }
                ++$total;
                if ($sibling->getStatus()->isSettled()) {
                    ++$settled;
                }
            }
        }

        return [$settled, $total];
    }
}
