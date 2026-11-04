<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\MoonObserverRecord;
use App\Entity\MoonTaxRefinerySetting;
use App\Enum\MoonTaxSyncFailure;
use App\Repository\MoonObserverRecordRepository;
use App\Service\ESI\EsiClient;
use App\Service\MoonTax\MoonTaxManagerAccess;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MoonObserverLedgerSync
{
    private const array READER_SCOPES = ['esi-industry.read_corporation_mining.v1', MoonTaxManagerAccess::ROLES_SCOPE];
    private const array READER_ROLES = [MoonTaxManagerAccess::ACCOUNTANT_ROLE];

    public function __construct(
        private MoonTaxReaderCandidates $readerCandidates,
        private EsiClient $esiClient,
        private MoonObserverRecordRepository $recordRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Character>              $characters
     * @param list<MoonTaxRefinerySetting> $openSettings
     */
    public function sync(int $corporationId, array $characters, array $openSettings): ?MoonTaxSyncFailure
    {
        $openStructureIds = array_map(static fn (MoonTaxRefinerySetting $setting): int => $setting->getStructureId(), $openSettings);

        $outcome = $this->readerCandidates->readWithFirstAbleCharacter(
            $characters,
            self::READER_SCOPES,
            self::READER_ROLES,
            fn (Character $reader): array => $this->readRaw($corporationId, $reader, $openStructureIds),
        );
        if ($outcome->failure !== null) {
            return $outcome->failure;
        }

        $entriesByObserverId = $this->readableAnswer($outcome->value);
        if ($entriesByObserverId === null) {
            $this->logger->error('Moon observer ledger response is unreadable', ['corporation_id' => $corporationId]);

            return MoonTaxSyncFailure::EsiError;
        }

        foreach ($entriesByObserverId as $observerId => $entries) {
            $this->store($corporationId, $observerId, $entries);
        }

        return null;
    }

    /**
     * @param list<int> $openStructureIds
     *
     * @return array{observers: array<mixed>, ledgers: array<int, array<mixed>>}
     */
    private function readRaw(int $corporationId, Character $reader, array $openStructureIds): array
    {
        $observers = $this->esiClient->getPaginated(sprintf('/corporation/%d/mining/observers/', $corporationId), $reader->getEveToken());

        $ledgers = [];
        foreach ($observers as $observer) {
            $observerId = is_array($observer) ? ($observer['observer_id'] ?? null) : null;
            if (is_int($observerId) && in_array($observerId, $openStructureIds, true)) {
                $ledgers[$observerId] = $this->esiClient->getPaginated(sprintf('/corporation/%d/mining/observers/%d/', $corporationId, $observerId), $reader->getEveToken());
            }
        }

        return ['observers' => $observers, 'ledgers' => $ledgers];
    }

    /**
     * @param array{observers: array<mixed>, ledgers: array<int, array<mixed>>} $raw
     *
     * @return array<int, list<array{characterId: int, typeId: int, quantity: int, recordedCorporationId: int, lastUpdated: \DateTimeImmutable}>>|null
     */
    private function readableAnswer(array $raw): ?array
    {
        foreach ($raw['observers'] as $observer) {
            if (!is_array($observer) || !is_int($observer['observer_id'] ?? null)) {
                return null;
            }
        }

        $entriesByObserverId = [];
        foreach ($raw['ledgers'] as $observerId => $rawEntries) {
            $entries = [];
            foreach ($rawEntries as $rawEntry) {
                $entry = is_array($rawEntry) ? $this->readableEntry($rawEntry) : null;
                if ($entry === null) {
                    return null;
                }
                $entries[] = $entry;
            }
            $entriesByObserverId[$observerId] = $entries;
        }

        return $entriesByObserverId;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array{characterId: int, typeId: int, quantity: int, recordedCorporationId: int, lastUpdated: \DateTimeImmutable}|null
     */
    private function readableEntry(array $raw): ?array
    {
        $characterId = $raw['character_id'] ?? null;
        $typeId = $raw['type_id'] ?? null;
        $quantity = $raw['quantity'] ?? null;
        $recordedCorporationId = $raw['recorded_corporation_id'] ?? null;
        $lastUpdated = $this->readableDate($raw['last_updated'] ?? null);
        if (!is_int($characterId) || !is_int($typeId) || !is_int($quantity) || !is_int($recordedCorporationId) || $lastUpdated === null) {
            return null;
        }

        return ['characterId' => $characterId, 'typeId' => $typeId, 'quantity' => $quantity, 'recordedCorporationId' => $recordedCorporationId, 'lastUpdated' => $lastUpdated];
    }

    private function readableDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @param list<array{characterId: int, typeId: int, quantity: int, recordedCorporationId: int, lastUpdated: \DateTimeImmutable}> $entries
     */
    private function store(int $corporationId, int $observerId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $oldest = min(array_map(static fn (array $entry): \DateTimeImmutable => $entry['lastUpdated'], $entries));
        $records = [];
        foreach ($this->recordRepository->findOfObserverSince($corporationId, $observerId, $oldest) as $record) {
            $records[$this->recordKey($record->getCharacterId(), $record->getTypeId(), $record->getLastUpdated())] = $record;
        }

        foreach ($entries as $entry) {
            $key = $this->recordKey($entry['characterId'], $entry['typeId'], $entry['lastUpdated']);
            $record = $records[$key] ?? (new MoonObserverRecord())
                ->setCorporationId($corporationId)
                ->setObserverId($observerId)
                ->setCharacterId($entry['characterId'])
                ->setTypeId($entry['typeId'])
                ->setLastUpdated($entry['lastUpdated']);
            $record->setQuantity($entry['quantity'])->setRecordedCorporationId($entry['recordedCorporationId']);
            $records[$key] = $record;
            $this->entityManager->persist($record);
        }
    }

    private function recordKey(int $characterId, int $typeId, \DateTimeImmutable $lastUpdated): string
    {
        return sprintf('%d|%d|%s', $characterId, $typeId, $lastUpdated->format('Y-m-d'));
    }
}
