<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;
use App\Entity\CorporationMiningExtraction;
use App\Entity\CorporationMiningSyncState;
use App\Enum\CorporationMiningSyncFailure;
use App\Enum\CorporationRoleStatus;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Repository\CharacterRepository;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\CorporationMiningSyncStateRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sde\SdeCelestialNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

class CorporationMiningExtractionSyncService
{
    public const string SYNC_TYPE = 'corporation-mining-extractions';

    private const array REQUIRED_SCOPES = [
        'esi-industry.read_corporation_mining.v1',
        'esi-corporations.read_structures.v1',
        'esi-characters.read_corporation_roles.v1',
    ];
    private const string STATION_MANAGER_ROLE = 'Station_Manager';

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CorporationRoleReader $corporationRoleReader,
        private readonly CharacterRepository $characterRepository,
        private readonly CorporationMiningExtractionRepository $extractionRepository,
        private readonly CorporationMiningSyncStateRepository $syncStateRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly SdeCelestialNameResolver $celestialNameResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sync(int $corporationId): CorporationMiningSyncState
    {
        $characters = $this->characterRepository->findByCorporationId($corporationId);
        $userIds = $this->userIdsOf($characters);
        foreach ($userIds as $userId) {
            $this->mercurePublisher->syncStarted($userId, self::SYNC_TYPE, 'Reading the moon extractions...');
        }

        try {
            $failure = $this->synchronize($corporationId, $characters);
            $state = $this->recordOutcome($corporationId, $failure);
        } catch (\Throwable $e) {
            foreach ($userIds as $userId) {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $e->getMessage());
            }

            throw $e;
        }

        foreach ($userIds as $userId) {
            if ($failure === null) {
                $this->mercurePublisher->syncCompleted($userId, self::SYNC_TYPE, 'Moon extractions up to date');
            } else {
                $this->mercurePublisher->syncError($userId, self::SYNC_TYPE, $this->failureMessage($failure));
            }
        }

        return $state;
    }

    /**
     * @param array<Character> $characters
     */
    private function synchronize(int $corporationId, array $characters): ?CorporationMiningSyncFailure
    {
        $lookup = $this->corporationRoleReader->readAsHolder(
            $corporationId,
            array_values($characters),
            [self::STATION_MANAGER_ROLE],
            self::REQUIRED_SCOPES,
            fn (Character $candidate): array => $this->esiClient->getPaginated($this->extractionsEndpoint($corporationId), $candidate->getEveToken()),
        );

        if ($lookup->status !== CorporationRoleStatus::Held || $lookup->holder === null || !is_array($lookup->value)) {
            return match ($lookup->status) {
                CorporationRoleStatus::ReconnectRequired => CorporationMiningSyncFailure::ReconnectRequired,
                CorporationRoleStatus::RoleAbsent => CorporationMiningSyncFailure::NoStationManagerToken,
                default => CorporationMiningSyncFailure::EsiError,
            };
        }

        $extractions = $this->readableExtractions($lookup->value);
        if ($extractions === null) {
            $this->logger->error('Corporation mining extractions response is unreadable', ['corporation_id' => $corporationId]);

            return CorporationMiningSyncFailure::EsiError;
        }

        $this->store($corporationId, $extractions, $this->readStructures($corporationId, $lookup->holder));

        return null;
    }

    /**
     * @param array<mixed> $rawExtractions
     *
     * @return list<array{structureId: int, moonId: int, start: \DateTimeImmutable, arrival: \DateTimeImmutable, decay: \DateTimeImmutable}>|null
     */
    private function readableExtractions(array $rawExtractions): ?array
    {
        $extractions = [];
        foreach ($rawExtractions as $raw) {
            $extraction = is_array($raw) ? $this->readableExtraction($raw) : null;
            if ($extraction === null) {
                return null;
            }
            $extractions[] = $extraction;
        }

        return $extractions;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array{structureId: int, moonId: int, start: \DateTimeImmutable, arrival: \DateTimeImmutable, decay: \DateTimeImmutable}|null
     */
    private function readableExtraction(array $raw): ?array
    {
        $structureId = $raw['structure_id'] ?? null;
        $moonId = $raw['moon_id'] ?? null;
        $start = $this->readableTime($raw['extraction_start_time'] ?? null);
        $arrival = $this->readableTime($raw['chunk_arrival_time'] ?? null);
        $decay = $this->readableTime($raw['natural_decay_time'] ?? null);
        if (!is_int($structureId) || !is_int($moonId) || $start === null || $arrival === null || $decay === null) {
            return null;
        }

        return ['structureId' => $structureId, 'moonId' => $moonId, 'start' => $start, 'arrival' => $arrival, 'decay' => $decay];
    }

    private function readableTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sp', $value);

        return $time === false ? null : $time->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @return array<int, array{name: ?string, systemId: ?int}>
     */
    private function readStructures(int $corporationId, Character $reader): array
    {
        try {
            $rawStructures = $this->esiClient->getPaginated($this->structuresEndpoint($corporationId), $reader->getEveToken());
        } catch (EsiApiException|EveAuthRequiredException $e) {
            $this->logger->warning('Corporation structures unreadable, refinery names stay unknown', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $structures = [];
        foreach ($rawStructures as $raw) {
            if (!is_array($raw) || !is_int($raw['structure_id'] ?? null)) {
                continue;
            }
            $structures[$raw['structure_id']] = [
                'name' => is_string($raw['name'] ?? null) ? $raw['name'] : null,
                'systemId' => is_int($raw['system_id'] ?? null) ? $raw['system_id'] : null,
            ];
        }

        return $structures;
    }

    /**
     * @param list<array{structureId: int, moonId: int, start: \DateTimeImmutable, arrival: \DateTimeImmutable, decay: \DateTimeImmutable}> $extractions
     * @param array<int, array{name: ?string, systemId: ?int}>                                                                              $structures
     */
    private function store(int $corporationId, array $extractions, array $structures): void
    {
        $rows = [];
        $moonNames = [];
        foreach ($this->extractionRepository->findBy(['corporationId' => $corporationId]) as $row) {
            $rows[$this->rowKey($row->getStructureId(), $row->getMoonId(), $row->getExtractionStartTime())] = $row;
            if ($row->getMoonName() !== null) {
                $moonNames[$row->getMoonId()] = ['name' => $row->getMoonName(), 'systemId' => null];
            }
        }

        foreach ($extractions as $extraction) {
            $moonId = $extraction['moonId'];
            $moonNames[$moonId] ??= $this->readMoon($moonId);
            $moon = $moonNames[$moonId];
            $structure = $structures[$extraction['structureId']] ?? ['name' => null, 'systemId' => null];

            $key = $this->rowKey($extraction['structureId'], $moonId, $extraction['start']);
            $row = $rows[$key] ?? (new CorporationMiningExtraction())
                ->setCorporationId($corporationId)
                ->setStructureId($extraction['structureId'])
                ->setMoonId($moonId)
                ->setExtractionStartTime($extraction['start']);
            $rows[$key] = $row;

            $solarSystemId = $structure['systemId'] ?? $moon['systemId'] ?? $row->getSolarSystemId();
            $row->setChunkArrivalTime($extraction['arrival'])
                ->setNaturalDecayTime($extraction['decay'])
                ->setStructureName($structure['name'] ?? $row->getStructureName())
                ->setMoonName($moon['name'])
                ->setSolarSystemId($solarSystemId)
                ->setSolarSystemName($solarSystemId === null ? null : $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName());
            $this->entityManager->persist($row);
        }
    }

    /**
     * @return array{name: ?string, systemId: ?int}
     */
    private function readMoon(int $moonId): array
    {
        $sdeMoon = $this->celestialNameResolver->moon($moonId);
        if ($sdeMoon !== null) {
            return ['name' => $sdeMoon->name, 'systemId' => $sdeMoon->solarSystemId];
        }

        $this->logger->warning('Moon missing from the SDE, read from ESI', ['moon_id' => $moonId]);

        try {
            $moon = $this->esiClient->getWithCache(sprintf('/universe/moons/%d/', $moonId));
        } catch (EsiApiException $e) {
            $this->logger->warning('Moon unreadable, its name stays unknown', ['moon_id' => $moonId, 'error' => $e->getMessage()]);

            return ['name' => null, 'systemId' => null];
        }

        return [
            'name' => is_string($moon['name'] ?? null) ? $moon['name'] : null,
            'systemId' => is_int($moon['system_id'] ?? null) ? $moon['system_id'] : null,
        ];
    }

    private function recordOutcome(int $corporationId, ?CorporationMiningSyncFailure $failure): CorporationMiningSyncState
    {
        $now = $this->clock->now();
        $state = $this->syncStateRepository->findOneBy(['corporationId' => $corporationId])
            ?? (new CorporationMiningSyncState())->setCorporationId($corporationId);

        $state->setLastAttemptAt($now)->setLastFailure($failure);
        if ($failure === null) {
            $state->setLastSuccessAt($now);
        }

        $this->entityManager->persist($state);
        $this->entityManager->flush();

        return $state;
    }

    /**
     * @param array<Character> $characters
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

    private function failureMessage(CorporationMiningSyncFailure $failure): string
    {
        return match ($failure) {
            CorporationMiningSyncFailure::NoStationManagerToken => 'No character with the Station_Manager role can read the extractions',
            CorporationMiningSyncFailure::ReconnectRequired => 'No character has a valid token with the scopes the extractions need: reconnect one',
            CorporationMiningSyncFailure::EsiError => 'ESI read failed',
        };
    }

    private function rowKey(int $structureId, int $moonId, \DateTimeImmutable $start): string
    {
        return sprintf('%d|%d|%d', $structureId, $moonId, $start->getTimestamp());
    }

    private function extractionsEndpoint(int $corporationId): string
    {
        return sprintf('/corporation/%d/mining/extractions/', $corporationId);
    }

    private function structuresEndpoint(int $corporationId): string
    {
        return sprintf('/corporations/%d/structures/', $corporationId);
    }
}
