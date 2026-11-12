<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\CorporationMiningExtraction;
use App\Entity\CorporationMiningSyncState;
use App\Entity\EveToken;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\CorporationMiningSyncFailure;
use App\Exception\EsiApiException;
use App\Repository\CharacterRepository;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\CorporationMiningSyncStateRepository;
use App\Repository\MiningOperationRepository;
use App\Repository\MiningValuationProfileRepository;
use App\Repository\MoonCompositionRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Admin\SyncTracker;
use App\Tests\Unit\Service\Admin\InMemorySyncRunLog;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MoonChunk\MoonChunkValueReader;
use App\Service\MoonChunk\MoonChunkValuer;
use App\Service\MoonChunk\MoonChunkVolumeEstimator;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\Service\OreValueService;
use App\Service\StructureMarketService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

trait CorporationMiningExtractionHarness
{
    private const string NOW = '2026-11-05 19:00:00';
    private const string SYNC_TYPE = 'corporation-mining-extractions';

    private const string MINING_SCOPE = 'esi-industry.read_corporation_mining.v1';
    private const string STRUCTURES_SCOPE = 'esi-corporations.read_structures.v1';
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';
    private const array ALL_SCOPES = [self::MINING_SCOPE, self::STRUCTURES_SCOPE, self::ROLES_SCOPE];
    private const string STATION_MANAGER = 'Station_Manager';

    private const int CORPORATION_A = 98_000_001;
    private const int CORPORATION_B = 98_000_002;
    private const int CORPORATION_C = 98_000_003;
    private const int NPC_CORPORATION = 1_000_125;
    private const int FIRST_PLAYER_CORPORATION = 2_000_000;

    private const string USER_A_ID = '0195f3a0-2740-7000-8000-00000000000a';
    private const string USER_B_ID = '0195f3a0-2740-7000-8000-00000000000b';
    private const string USER_C_ID = '0195f3a0-2740-7000-8000-00000000000c';

    private const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    private const string ROCK_HOLLOW_NAME = 'NOL-M9 - Rock Hollow';
    private const int DEEP_CORE_ID = 1_035_000_000_102;
    private const string DEEP_CORE_NAME = 'Ihakana - Deep Core Tatara Of The Southern Belt Mining Consortium';

    private const int NOL_M9_SYSTEM_ID = 30_004_712;
    private const string NOL_M9_SYSTEM_NAME = 'NOL-M9';
    private const int IHAKANA_SYSTEM_ID = 30_003_135;
    private const string IHAKANA_SYSTEM_NAME = 'Ihakana';

    private const int NOL_M9_MOON_ID = 40_301_001;
    private const string NOL_M9_MOON_NAME = 'NOL-M9 VII - Moon 3';
    private const int IHAKANA_MOON_ID = 40_302_002;
    private const string IHAKANA_MOON_NAME = 'Ihakana II - Moon 1';

    private const string ROCK_HOLLOW_START = '2026-11-02T18:00:00+00:00';
    private const string ROCK_HOLLOW_ARRIVAL = '2026-11-05T20:00:00+00:00';
    private const string ROCK_HOLLOW_DECAY = '2026-11-05T23:00:00+00:00';
    private const string DEEP_CORE_START = '2026-10-30T12:00:00+00:00';
    private const string DEEP_CORE_ARRIVAL = '2026-11-08T06:15:00+00:00';
    private const string DEEP_CORE_DECAY = '2026-11-08T09:15:00+00:00';

    private User $userA;
    private User $userB;
    private User $userC;
    private ?User $currentUser = null;
    private MockClock $clock;

    private array $characters = [];

    private array $characterByTokenId = [];

    private array $esiResponses = [];

    private array $esiFailures = [];

    private array $esiCalls = [];

    private array $storedExtractions = [];

    private array $storedSyncStates = [];

    private array $storedFollows = [];

    private array $removedEntities = [];

    private array $publishedUpdates = [];

    private array $dispatchedMessages = [];

    private SyncTracker $syncTracker;

    private function setUpCorporationMiningHarness(): void
    {
        $this->clock = new MockClock(self::NOW, 'UTC');
        $this->userA = $this->userWithId(self::USER_A_ID);
        $this->userB = $this->userWithId(self::USER_B_ID);
        $this->userC = $this->userWithId(self::USER_C_ID);
        $this->currentUser = $this->userA;
        $this->syncTracker = new SyncTracker(new ArrayAdapter(), $this->mercurePublisher(), new InMemorySyncRunLog());
    }

    private function userWithId(string $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($id));

        return $user;
    }

    private function addCharacter(User $user, string $name, int $eveCharacterId, int $corporationId, array $scopes, array $roles): Character
    {
        $token = (new EveToken())
            ->setAccessToken('access-token-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-05 19:20:00', new \DateTimeZone('UTC')))
            ->setScopes($scopes);
        $character = (new Character())
            ->setName($name)
            ->setEveCharacterId($eveCharacterId)
            ->setCorporationId($corporationId);
        $character->setEveToken($token);
        $user->addCharacter($character);
        $this->characters[] = $character;
        $this->characterByTokenId[spl_object_id($token)] = $character;
        $this->esiResponses[sprintf('/characters/%d/roles/', $eveCharacterId)] = ['roles' => $roles];

        return $character;
    }

    private function stationManager(User $user, string $name, int $eveCharacterId, int $corporationId): Character
    {
        return $this->addCharacter($user, $name, $eveCharacterId, $corporationId, self::ALL_SCOPES, ['Accountant', self::STATION_MANAGER]);
    }

    private static function extractionsEndpoint(int $corporationId): string
    {
        return sprintf('/corporation/%d/mining/extractions/', $corporationId);
    }

    private static function structuresEndpoint(int $corporationId): string
    {
        return sprintf('/corporations/%d/structures/', $corporationId);
    }

    private static function moonEndpoint(int $moonId): string
    {
        return sprintf('/universe/moons/%d/', $moonId);
    }

    private static function rockHollowExtraction(): array
    {
        return [
            'structure_id' => self::ROCK_HOLLOW_ID,
            'moon_id' => self::NOL_M9_MOON_ID,
            'extraction_start_time' => '2026-11-02T18:00:00Z',
            'chunk_arrival_time' => '2026-11-05T20:00:00Z',
            'natural_decay_time' => '2026-11-05T23:00:00Z',
        ];
    }

    private static function deepCoreExtraction(): array
    {
        return [
            'structure_id' => self::DEEP_CORE_ID,
            'moon_id' => self::IHAKANA_MOON_ID,
            'extraction_start_time' => '2026-10-30T12:00:00Z',
            'chunk_arrival_time' => '2026-11-08T06:15:00Z',
            'natural_decay_time' => '2026-11-08T09:15:00Z',
        ];
    }

    private function respondWithCorporationMoons(int $corporationId): void
    {
        $this->esiResponses[self::structuresEndpoint($corporationId)] = [
            ['structure_id' => self::ROCK_HOLLOW_ID, 'name' => self::ROCK_HOLLOW_NAME, 'system_id' => self::NOL_M9_SYSTEM_ID, 'type_id' => 35835, 'corporation_id' => $corporationId],
            ['structure_id' => self::DEEP_CORE_ID, 'name' => self::DEEP_CORE_NAME, 'system_id' => self::IHAKANA_SYSTEM_ID, 'type_id' => 35836, 'corporation_id' => $corporationId],
            ['structure_id' => 1_035_000_000_199, 'name' => 'NOL-M9 - Market Keepstar', 'system_id' => self::NOL_M9_SYSTEM_ID, 'type_id' => 35834, 'corporation_id' => $corporationId],
        ];
        $this->esiResponses[self::moonEndpoint(self::NOL_M9_MOON_ID)] = ['moon_id' => self::NOL_M9_MOON_ID, 'name' => self::NOL_M9_MOON_NAME, 'system_id' => self::NOL_M9_SYSTEM_ID, 'position' => ['x' => 1.0, 'y' => 2.0, 'z' => 3.0]];
        $this->esiResponses[self::moonEndpoint(self::IHAKANA_MOON_ID)] = ['moon_id' => self::IHAKANA_MOON_ID, 'name' => self::IHAKANA_MOON_NAME, 'system_id' => self::IHAKANA_SYSTEM_ID, 'position' => ['x' => 4.0, 'y' => 5.0, 'z' => 6.0]];
    }

    private function failEsi(string $endpoint, int $statusCode, ?Character $character = null): void
    {
        $this->esiFailures[$endpoint][$character?->getEveCharacterId() ?? 'any'] = EsiApiException::fromResponse($statusCode, 'ESI ' . $statusCode, $endpoint);
    }

    private function storeExtraction(
        int $corporationId,
        int $structureId,
        int $moonId,
        string $start,
        string $arrival,
        string $decay,
        ?string $structureName,
        ?int $solarSystemId,
        ?string $solarSystemName,
        ?string $moonName,
        string $id,
    ): CorporationMiningExtraction {
        $extraction = (new CorporationMiningExtraction())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setMoonId($moonId)
            ->setExtractionStartTime(new \DateTimeImmutable($start))
            ->setChunkArrivalTime(new \DateTimeImmutable($arrival))
            ->setNaturalDecayTime(new \DateTimeImmutable($decay))
            ->setStructureName($structureName)
            ->setSolarSystemId($solarSystemId)
            ->setSolarSystemName($solarSystemName)
            ->setMoonName($moonName);
        (new \ReflectionProperty(CorporationMiningExtraction::class, 'id'))->setValue($extraction, Uuid::fromString($id));
        $this->storedExtractions[] = $extraction;

        return $extraction;
    }

    private function storeSyncState(int $corporationId, ?string $lastSuccessAt, ?string $lastAttemptAt, ?CorporationMiningSyncFailure $failure): CorporationMiningSyncState
    {
        $state = (new CorporationMiningSyncState())
            ->setCorporationId($corporationId)
            ->setLastSuccessAt($lastSuccessAt === null ? null : new \DateTimeImmutable($lastSuccessAt))
            ->setLastAttemptAt($lastAttemptAt === null ? null : new \DateTimeImmutable($lastAttemptAt))
            ->setLastFailure($failure);
        $this->storedSyncStates[] = $state;

        return $state;
    }

    private function storedSyncStateOf(int $corporationId): ?CorporationMiningSyncState
    {
        $states = array_values(array_filter(
            $this->storedSyncStates,
            static fn (CorporationMiningSyncState $state): bool => $state->getCorporationId() === $corporationId,
        ));
        self::assertLessThanOrEqual(1, count($states));

        return $states[0] ?? null;
    }

    private function syncStateSummaryOf(int $corporationId): array
    {
        $state = $this->storedSyncStateOf($corporationId);
        self::assertNotNull($state, sprintf('No sync state stored for corporation %d', $corporationId));

        return [
            'lastSuccessAt' => $state->getLastSuccessAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'lastAttemptAt' => $state->getLastAttemptAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'lastFailure' => $state->getLastFailure()?->value,
        ];
    }

    private function storedExtractionRows(): array
    {
        $rows = array_map(
            static fn (CorporationMiningExtraction $extraction): array => [
                'corporationId' => $extraction->getCorporationId(),
                'structureId' => $extraction->getStructureId(),
                'structureName' => $extraction->getStructureName(),
                'solarSystemId' => $extraction->getSolarSystemId(),
                'solarSystemName' => $extraction->getSolarSystemName(),
                'moonId' => $extraction->getMoonId(),
                'moonName' => $extraction->getMoonName(),
                'extractionStartTime' => $extraction->getExtractionStartTime()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                'chunkArrivalTime' => $extraction->getChunkArrivalTime()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                'naturalDecayTime' => $extraction->getNaturalDecayTime()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            ],
            $this->storedExtractions,
        );
        usort($rows, static fn (array $left, array $right): int => [$left['corporationId'], $left['extractionStartTime'], $left['structureId']] <=> [$right['corporationId'], $right['extractionStartTime'], $right['structureId']]);

        return $rows;
    }

    private static function rockHollowRow(int $corporationId): array
    {
        return [
            'corporationId' => $corporationId,
            'structureId' => self::ROCK_HOLLOW_ID,
            'structureName' => self::ROCK_HOLLOW_NAME,
            'solarSystemId' => self::NOL_M9_SYSTEM_ID,
            'solarSystemName' => self::NOL_M9_SYSTEM_NAME,
            'moonId' => self::NOL_M9_MOON_ID,
            'moonName' => self::NOL_M9_MOON_NAME,
            'extractionStartTime' => self::ROCK_HOLLOW_START,
            'chunkArrivalTime' => self::ROCK_HOLLOW_ARRIVAL,
            'naturalDecayTime' => self::ROCK_HOLLOW_DECAY,
        ];
    }

    private static function deepCoreRow(int $corporationId): array
    {
        return [
            'corporationId' => $corporationId,
            'structureId' => self::DEEP_CORE_ID,
            'structureName' => self::DEEP_CORE_NAME,
            'solarSystemId' => self::IHAKANA_SYSTEM_ID,
            'solarSystemName' => self::IHAKANA_SYSTEM_NAME,
            'moonId' => self::IHAKANA_MOON_ID,
            'moonName' => self::IHAKANA_MOON_NAME,
            'extractionStartTime' => self::DEEP_CORE_START,
            'chunkArrivalTime' => self::DEEP_CORE_ARRIVAL,
            'naturalDecayTime' => self::DEEP_CORE_DECAY,
        ];
    }

    private function esiCallsTo(string $endpoint): array
    {
        return array_values(array_filter($this->esiCalls, static fn (array $call): bool => $call[0] === $endpoint));
    }

    private function charactersReading(string $endpoint): array
    {
        return array_map(static fn (array $call): ?int => $call[1], $this->esiCallsTo($endpoint));
    }

    private function charactersCallingEsi(): array
    {
        $characterIds = array_values(array_unique(array_filter(array_map(static fn (array $call): ?int => $call[1], $this->esiCalls))));
        sort($characterIds);

        return $characterIds;
    }

    private function publishedSyncEvents(): array
    {
        $events = [];
        foreach ($this->publishedUpdates as $update) {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            foreach ($update->getTopics() as $topic) {
                $events[] = [$topic, $payload['status']];
            }
        }
        sort($events);

        return $events;
    }

    private function mercurePublisher(): MercurePublisherService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });

        return new MercurePublisherService($hub, new NullLogger());
    }

    private function boundaries(): array
    {
        $esiClient = $this->createStub(EsiClient::class);
        foreach (['get', 'getPaginated', 'getWithCache'] as $method) {
            $esiClient->method($method)->willReturnCallback(fn (string $endpoint, ?EveToken $token = null): array => $this->answerEsi($endpoint, $token));
        }

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findByCorporationId')->willReturnCallback(fn (int $corporationId): array => $this->charactersOfCorporationByName($corporationId));
        $characterRepository->method('findAll')->willReturnCallback(fn (): array => $this->characters);

        $extractionRepository = $this->createStub(CorporationMiningExtractionRepository::class);
        $extractionRepository->method('findBy')->willReturnCallback(
            fn (array $criteria, ?array $orderBy = null): array => self::matching($this->storedExtractions, $criteria, $orderBy),
        );
        $extractionRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?CorporationMiningExtraction => self::matching($this->storedExtractions, $criteria, null)[0] ?? null,
        );
        $extractionRepository->method('findAll')->willReturnCallback(fn (): array => $this->storedExtractions);

        $syncStateRepository = $this->createStub(CorporationMiningSyncStateRepository::class);
        $syncStateRepository->method('findBy')->willReturnCallback(
            fn (array $criteria, ?array $orderBy = null): array => self::matching($this->storedSyncStates, $criteria, $orderBy),
        );
        $syncStateRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?CorporationMiningSyncState => self::matching($this->storedSyncStates, $criteria, null)[0] ?? null,
        );

        $followRepository = $this->createStub(MoonRefineryFollowRepository::class);
        $followRepository->method('findBy')->willReturnCallback(
            fn (array $criteria, ?array $orderBy = null): array => self::matching($this->storedFollows, $criteria, $orderBy),
        );

        $miningOperationRepository = $this->createStub(MiningOperationRepository::class);

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        foreach (['find', 'findBySolarSystemId'] as $method) {
            $solarSystemRepository->method($method)->willReturnCallback(static fn (int $solarSystemId): ?MapSolarSystem => self::solarSystem($solarSystemId));
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof CorporationMiningExtraction && !in_array($entity, $this->storedExtractions, true)) {
                $this->storedExtractions[] = $entity;
            }
            if ($entity instanceof CorporationMiningSyncState && !in_array($entity, $this->storedSyncStates, true)) {
                $this->storedSyncStates[] = $entity;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removedEntities[] = $entity;
        });

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): ?User => $this->currentUser);

        $characterService = new CharacterService($esiClient);

        $moonChunkValueReader = new MoonChunkValueReader(
            $this->createStub(MoonCompositionRepository::class),
            $this->createStub(InvTypeRepository::class),
            new MiningProfileUnitPrices(
                new MiningValuationProfileReader($this->createStub(MiningValuationProfileRepository::class)),
                $this->createStub(ReprocessingProfileRepository::class),
                $this->createStub(OreValueService::class),
                $this->createStub(JitaMarketService::class),
                $this->createStub(StructureMarketService::class),
            ),
            new MoonChunkVolumeEstimator(),
            new MoonChunkValuer(),
        );

        return [
            $esiClient,
            $characterService,
            new CorporationRoleReader($characterService, new ArrayAdapter(), new NullLogger()),
            $moonChunkValueReader,
            $characterRepository,
            $extractionRepository,
            $syncStateRepository,
            $followRepository,
            $miningOperationRepository,
            $solarSystemRepository,
            $entityManager,
            $this->mercurePublisher(),
            $this->syncTracker,
            $messageBus,
            $security,
            $this->clock,
            new NullLogger(),
        ];
    }

    private function answerEsi(string $endpoint, ?EveToken $token): array
    {
        $character = $token === null ? null : ($this->characterByTokenId[spl_object_id($token)] ?? null);
        $this->esiCalls[] = [$endpoint, $character?->getEveCharacterId()];

        $failures = $this->esiFailures[$endpoint] ?? [];
        $failure = $failures[$character?->getEveCharacterId() ?? 'any'] ?? $failures['any'] ?? null;
        if ($failure !== null) {
            throw $failure;
        }
        if (!array_key_exists($endpoint, $this->esiResponses)) {
            throw EsiApiException::notFound('No ESI answer prepared for ' . $endpoint, $endpoint);
        }

        return $this->esiResponses[$endpoint];
    }

    private function charactersOfCorporationByName(int $corporationId): array
    {
        $characters = array_values(array_filter($this->characters, static fn (Character $character): bool => $character->getCorporationId() === $corporationId));
        usort($characters, static fn (Character $left, Character $right): int => $left->getName() <=> $right->getName());

        return $characters;
    }

    private static function solarSystem(int $solarSystemId): ?MapSolarSystem
    {
        $names = [self::NOL_M9_SYSTEM_ID => self::NOL_M9_SYSTEM_NAME, self::IHAKANA_SYSTEM_ID => self::IHAKANA_SYSTEM_NAME];
        if (!isset($names[$solarSystemId])) {
            return null;
        }

        return (new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($names[$solarSystemId]);
    }

    private static function matching(array $entities, array $criteria, ?array $orderBy): array
    {
        $matches = array_values(array_filter($entities, static function (object $entity) use ($criteria): bool {
            foreach ($criteria as $field => $expected) {
                $actual = $entity->{'get' . ucfirst($field)}();
                $same = $actual instanceof \DateTimeInterface && $expected instanceof \DateTimeInterface
                    ? $actual->getTimestamp() === $expected->getTimestamp()
                    : $actual === $expected;
                if (!$same) {
                    return false;
                }
            }

            return true;
        }));

        foreach (array_reverse($orderBy ?? []) as $field => $direction) {
            usort($matches, static function (object $left, object $right) use ($field, $direction): int {
                $comparison = $left->{'get' . ucfirst($field)}() <=> $right->{'get' . ucfirst($field)}();

                return strtoupper($direction) === 'DESC' ? -$comparison : $comparison;
            });
        }

        return $matches;
    }

    private function build(string $class): object
    {
        return self::autowired($class, $this->boundaries());
    }

    private static function autowired(string $class, array $services): object
    {
        $parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];

        return new $class(...array_map(
            static function (\ReflectionParameter $parameter) use ($class, $services): mixed {
                $type = ltrim((string) $parameter->getType(), '?');
                foreach ($services as $service) {
                    if ($service instanceof $type) {
                        return $service;
                    }
                }
                $buildable = str_starts_with($type, 'App\\Service\\Sync\\')
                    || str_starts_with($type, 'App\\Service\\Mining\\')
                    || str_starts_with($type, 'App\\State\\Provider\\Mining\\');
                if ($buildable && class_exists($type)) {
                    return self::autowired($type, $services);
                }
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }

                throw new \LogicException(sprintf('The harness has no %s for $%s of %s', $type, $parameter->getName(), $class));
            },
            $parameters,
        ));
    }
}
