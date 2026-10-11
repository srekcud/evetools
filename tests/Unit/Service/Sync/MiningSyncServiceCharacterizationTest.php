<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\MiningEntry;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\MiningEntryRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\MiningSyncService;
use App\Service\TypeNameResolver;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
final class MiningSyncServiceCharacterizationTest extends TestCase
{
    private const TESSA_ID = 91000001;
    private const TESSA_NAME = 'Tessa Orvik';
    private const DORIN_ID = 91000002;
    private const DORIN_NAME = 'Dorin Halk';
    private const TESSA_LEDGER = '/characters/91000001/mining/';
    private const DORIN_LEDGER = '/characters/91000002/mining/';

    private const VELDSPAR = 1230;
    private const SCORDITE = 1228;
    private const PYROXERES = 1224;
    private const KERNITE = 20;
    private const UNKNOWN_ORE = 62568;

    private const ALDRANETTE = 30003504;
    private const TAMA = 30002813;
    private const UNKNOWN_SYSTEM = 30045349;

    private const TYPE_NAMES = [
        self::VELDSPAR => 'Veldspar',
        self::SCORDITE => 'Scordite',
        self::PYROXERES => 'Pyroxeres',
        self::KERNITE => 'Kernite',
    ];

    private const SYSTEM_NAMES = [
        self::ALDRANETTE => 'Aldranette',
        self::TAMA => 'Tama',
    ];

    private const PREVIOUS_MINING_SYNC_AT = '2026-10-01 08:15:00+00:00';

    private EsiClient&Stub $esiClient;
    private TokenManager&Stub $tokenManager;
    private MarketService&MockObject $marketService;
    private MiningEntryRepository&MockObject $miningEntryRepository;
    private UserLedgerSettingsRepository&Stub $settingsRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private UserLedgerSettings $settings;
    private User&Stub $user;
    private MiningSyncService $service;

    private array $journal = [];

    private array $persisted = [];

    private array $publishedSyncEvents = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->tokenManager = $this->createStub(TokenManager::class);
        $this->marketService = $this->createMock(MarketService::class);
        $this->miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $this->settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('resolve')->willReturnCallback(
            static fn (int $typeId): string => self::TYPE_NAMES[$typeId] ?? "Type #{$typeId}"
        );

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('find')->willReturnCallback(function (int $solarSystemId): ?MapSolarSystem {
            if (!isset(self::SYSTEM_NAMES[$solarSystemId])) {
                return null;
            }
            $system = new MapSolarSystem();
            $system->setSolarSystemId($solarSystemId);
            $system->setSolarSystemName(self::SYSTEM_NAMES[$solarSystemId]);

            return $system;
        });

        $this->journal = [];
        $this->persisted = [];
        $this->publishedSyncEvents = [];

        $this->settings = new UserLedgerSettings();
        $this->settings->setLastMiningSyncAt(new \DateTimeImmutable(self::PREVIOUS_MINING_SYNC_AT));
        $this->settingsRepository->method('findByUser')->willReturn($this->settings);
        $this->settingsRepository->method('getOrCreate')->willReturn($this->settings);

        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            $this->journal[] = $entity instanceof MiningEntry ? 'persist ' . $entity->getTypeId() : 'persist ' . $entity::class;
        });
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            $this->journal[] = $this->settings->getLastMiningSyncAt()?->format('Y-m-d H:i:sP') === self::PREVIOUS_MINING_SYNC_AT
                ? 'flush before last mining sync update'
                : 'flush after last mining sync update';
        });

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $event = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
            unset($event['timestamp']);
            $this->publishedSyncEvents[] = $event;

            return 'urn:uuid:test';
        });

        $this->user = $this->userWithCharacters(Uuid::v4());

        $this->service = new MiningSyncService(
            $this->esiClient,
            $this->tokenManager,
            $this->marketService,
            $this->miningEntryRepository,
            $this->settingsRepository,
            $typeNameResolver,
            $solarSystemRepository,
            $this->entityManager,
            new NullLogger(),
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    public function testSyncAllPersistsEachNewLedgerLineWithOreSystemDateQuantityAndUsage(): void
    {
        $this->settings->setDefaultSoldTypeIds([self::SCORDITE]);
        $this->respondWith([
            self::TESSA_LEDGER => [
                self::ledgerLine('2026-10-09', self::VELDSPAR, self::ALDRANETTE, 7_413),
                self::ledgerLine('2026-10-10', self::SCORDITE, self::TAMA, 2_906),
            ],
            self::DORIN_LEDGER => [
                self::ledgerLine('2026-10-08', self::UNKNOWN_ORE, self::UNKNOWN_SYSTEM, 15_587),
                self::ledgerLine('2026-10-07', self::PYROXERES, self::ALDRANETTE, 431),
            ],
        ]);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $results = $this->service->syncAll($this->user);

        $this->assertSyncCounts(['imported' => 4, 'updated' => 0, 'pricesUpdated' => 0, 'errors' => []], $results);
        $this->assertSame([
            $this->entryRow(self::DORIN_ID, self::DORIN_NAME, '2026-10-07', self::PYROXERES, 'Pyroxeres', self::ALDRANETTE, 'Aldranette', 431, MiningEntry::USAGE_UNKNOWN),
            $this->entryRow(self::DORIN_ID, self::DORIN_NAME, '2026-10-08', self::UNKNOWN_ORE, 'Type #62568', self::UNKNOWN_SYSTEM, 'System #30045349', 15_587, MiningEntry::USAGE_UNKNOWN),
            $this->entryRow(self::TESSA_ID, self::TESSA_NAME, '2026-10-09', self::VELDSPAR, 'Veldspar', self::ALDRANETTE, 'Aldranette', 7_413, MiningEntry::USAGE_UNKNOWN),
            $this->entryRow(self::TESSA_ID, self::TESSA_NAME, '2026-10-10', self::SCORDITE, 'Scordite', self::TAMA, 'Tama', 2_906, MiningEntry::USAGE_SOLD),
        ], $this->persistedEntryRows());
        foreach ($this->persisted as $entry) {
            $this->assertSame($this->user, $entry->getUser());
        }
    }

    public function testSyncAllUpdatesQuantityAndResolvableTypeNameOfExistingEntriesAndLeavesTheOthersUntouched(): void
    {
        $previousSyncedAt = new \DateTimeImmutable('2026-10-05 06:00:00+00:00');
        $pricedVeldspar = $this->existingEntry('2026-10-09', self::VELDSPAR, 'Veldspar', self::ALDRANETTE, 6_002, $previousSyncedAt);
        $pricedVeldspar->setUnitPrice(17.25);
        $unresolvedPyroxeres = $this->existingEntry('2026-10-09', self::PYROXERES, 'Type #1224', self::TAMA, 839, $previousSyncedAt);
        $stillUnknownOre = $this->existingEntry('2026-10-08', self::UNKNOWN_ORE, 'Type #62568', self::UNKNOWN_SYSTEM, 12_044, $previousSyncedAt);
        $unchangedKernite = $this->existingEntry('2026-10-07', self::KERNITE, 'Kernite', self::ALDRANETTE, 517, $previousSyncedAt);
        $existingByKey = [
            '2026-10-09|1230|30003504' => $pricedVeldspar,
            '2026-10-09|1224|30002813' => $unresolvedPyroxeres,
            '2026-10-08|62568|30045349' => $stillUnknownOre,
            '2026-10-07|20|30003504' => $unchangedKernite,
        ];
        $this->miningEntryRepository->method('findByUniqueKey')->willReturnCallback(
            static fn (User $user, int $characterId, \DateTimeImmutable $date, int $typeId, int $solarSystemId): ?MiningEntry
                => $existingByKey[$date->format('Y-m-d') . "|{$typeId}|{$solarSystemId}"] ?? null
        );
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->respondWith([
            self::TESSA_LEDGER => [
                self::ledgerLine('2026-10-09', self::VELDSPAR, self::ALDRANETTE, 7_413),
                self::ledgerLine('2026-10-09', self::PYROXERES, self::TAMA, 839),
                self::ledgerLine('2026-10-08', self::UNKNOWN_ORE, self::UNKNOWN_SYSTEM, 12_044),
                self::ledgerLine('2026-10-07', self::KERNITE, self::ALDRANETTE, 517),
            ],
            self::DORIN_LEDGER => [],
        ]);
        $before = new \DateTimeImmutable();

        $results = $this->service->syncAll($this->user);

        $this->assertSyncCounts(['imported' => 0, 'updated' => 2, 'pricesUpdated' => 0, 'errors' => []], $results);
        $this->assertSame([], $this->persisted);
        $this->assertSame([7_413, 'Veldspar', 17.25, 127_874.25], [$pricedVeldspar->getQuantity(), $pricedVeldspar->getTypeName(), $pricedVeldspar->getUnitPrice(), $pricedVeldspar->getTotalValue()]);
        $this->assertSame([839, 'Pyroxeres', null, null], [$unresolvedPyroxeres->getQuantity(), $unresolvedPyroxeres->getTypeName(), $unresolvedPyroxeres->getUnitPrice(), $unresolvedPyroxeres->getTotalValue()]);
        $this->assertSame([12_044, 'Type #62568'], [$stillUnknownOre->getQuantity(), $stillUnknownOre->getTypeName()]);
        $this->assertSame([517, 'Kernite'], [$unchangedKernite->getQuantity(), $unchangedKernite->getTypeName()]);
        $this->assertGreaterThanOrEqual($before, $pricedVeldspar->getSyncedAt());
        $this->assertGreaterThanOrEqual($before, $unresolvedPyroxeres->getSyncedAt());
        $this->assertSame($previousSyncedAt, $stillUnknownOre->getSyncedAt());
        $this->assertSame($previousSyncedAt, $unchangedKernite->getSyncedAt());
    }

    public function testSyncAllPricesOnlyTypesWithAPositiveJitaPriceAndSumsTheUpdatedEntries(): void
    {
        $this->respondWith([self::TESSA_LEDGER => [], self::DORIN_LEDGER => []]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn(
            [self::VELDSPAR, self::SCORDITE, self::PYROXERES, self::UNKNOWN_ORE]
        );
        $this->marketService->expects($this->once())->method('getJitaPrices')
            ->with([self::VELDSPAR, self::SCORDITE, self::PYROXERES, self::UNKNOWN_ORE])
            ->willReturn([self::VELDSPAR => 17.0, self::SCORDITE => 12.0, self::PYROXERES => 0.0, self::UNKNOWN_ORE => null]);
        $priceUpdates = [];
        $updatedEntriesByType = [self::VELDSPAR => 6, self::SCORDITE => 11];
        $this->miningEntryRepository->method('updatePriceByTypeId')->willReturnCallback(
            function (User $user, int $typeId, float $unitPrice) use (&$priceUpdates, $updatedEntriesByType): int {
                $this->assertSame($this->user, $user);
                $priceUpdates[$typeId] = $unitPrice;

                return $updatedEntriesByType[$typeId];
            }
        );

        $results = $this->service->syncAll($this->user);

        ksort($priceUpdates);
        $this->assertSame([self::SCORDITE => 12.0, self::VELDSPAR => 17.0], $priceUpdates);
        $this->assertSyncCounts(['imported' => 0, 'updated' => 0, 'pricesUpdated' => 17, 'errors' => []], $results);
    }

    public function testSyncAllDoesNotAskJitaPricesWhenEveryEntryIsAlreadyPriced(): void
    {
        $this->respondWith([self::TESSA_LEDGER => [], self::DORIN_LEDGER => []]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->marketService->expects($this->never())->method('getJitaPrices');
        $this->miningEntryRepository->expects($this->never())->method('updatePriceByTypeId');

        $results = $this->service->syncAll($this->user);

        $this->assertSyncCounts(['imported' => 0, 'updated' => 0, 'pricesUpdated' => 0, 'errors' => []], $results);
    }

    public function testSyncAllFlushesNewEntriesBeforePricingThemThenFlushesTheLastMiningSyncTime(): void
    {
        $this->respondWith([
            self::TESSA_LEDGER => [self::ledgerLine('2026-10-10', self::KERNITE, self::TAMA, 2_281)],
            self::DORIN_LEDGER => [],
        ]);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturnCallback(function (): array {
            $this->journal[] = 'read types without price';

            return [self::KERNITE];
        });
        $this->marketService->method('getJitaPrices')->willReturn([self::KERNITE => 52.0]);
        $this->miningEntryRepository->method('updatePriceByTypeId')->willReturnCallback(function (User $user, int $typeId): int {
            $this->journal[] = "update price {$typeId}";

            return 1;
        });
        $before = new \DateTimeImmutable();

        $results = $this->service->syncAll($this->user);

        $this->assertSame([
            'persist 20',
            'flush before last mining sync update',
            'read types without price',
            'update price 20',
            'flush after last mining sync update',
        ], $this->journal);
        $this->assertSyncCounts(['imported' => 1, 'updated' => 0, 'pricesUpdated' => 1, 'errors' => []], $results);
        $this->assertGreaterThanOrEqual($before, $this->settings->getLastMiningSyncAt());
        $this->assertLessThanOrEqual(new \DateTimeImmutable(), $this->settings->getLastMiningSyncAt());
    }

    public function testSyncAllPublishesStartedProgressAndCompletedEventsWithTheCounts(): void
    {
        $this->respondWith([
            self::TESSA_LEDGER => [
                self::ledgerLine('2026-10-10', self::VELDSPAR, self::ALDRANETTE, 7_413),
                self::ledgerLine('2026-10-09', self::SCORDITE, self::TAMA, 2_906),
            ],
            self::DORIN_LEDGER => [],
        ]);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([self::VELDSPAR, self::SCORDITE]);
        $this->marketService->method('getJitaPrices')->willReturn([self::VELDSPAR => 17.0, self::SCORDITE => 12.0]);
        $this->miningEntryRepository->method('updatePriceByTypeId')->willReturnCallback(
            static fn (User $user, int $typeId): int => [self::VELDSPAR => 9, self::SCORDITE => 4][$typeId]
        );

        $this->service->syncAll($this->user);

        $this->assertSame([
            self::syncEvent('started', 0, 'Syncing mining ledger...'),
            self::syncEvent('in_progress', 10, 'Fetching mining data...'),
            self::syncEvent('in_progress', 70, 'Updating Jita prices...'),
            self::syncEvent('completed', 100, '2 imported, 0 updated, 13 prices refreshed', [
                'imported' => 2,
                'updated' => 0,
                'pricesUpdated' => 13,
                'errors' => 0,
                'failedCharacters' => 0,
            ]),
        ], $this->publishedSyncEvents);
    }

    public function testSyncAllKeepsTheOtherCharacterWhenOneLedgerFailsAndReportsTheFailure(): void
    {
        $this->respondWith([
            self::TESSA_LEDGER => [self::ledgerLine('2026-10-10', self::PYROXERES, self::TAMA, 3_377)],
            self::DORIN_LEDGER => new \RuntimeException('ESI 504 Gateway Timeout'),
        ]);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $before = new \DateTimeImmutable();

        $results = $this->service->syncAll($this->user);

        $this->assertSyncCounts([
            'imported' => 1,
            'updated' => 0,
            'pricesUpdated' => 0,
            'errors' => ['Character Dorin Halk: ESI 504 Gateway Timeout'],
        ], $results);
        $this->assertSame([
            $this->entryRow(self::TESSA_ID, self::TESSA_NAME, '2026-10-10', self::PYROXERES, 'Pyroxeres', self::TAMA, 'Tama', 3_377, MiningEntry::USAGE_UNKNOWN),
        ], $this->persistedEntryRows());
        $this->assertGreaterThanOrEqual($before, $this->settings->getLastMiningSyncAt());
        $this->assertSame(
            self::syncEvent('completed', 100, '1 imported, 0 updated, 0 prices refreshed (1 of 2 characters failed)', [
                'imported' => 1,
                'updated' => 0,
                'pricesUpdated' => 0,
                'errors' => 1,
                'failedCharacters' => 1,
            ]),
            end($this->publishedSyncEvents)
        );
    }

    public function testSyncAllReportsAnErrorAndKeepsTheLastMiningSyncTimeWhenEveryLedgerFails(): void
    {
        $this->respondWith([
            self::TESSA_LEDGER => new \RuntimeException('ESI 502 Bad Gateway'),
            self::DORIN_LEDGER => new \RuntimeException('ESI 503 Service Unavailable'),
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $results = $this->service->syncAll($this->user);

        $this->assertSyncCounts([
            'imported' => 0,
            'updated' => 0,
            'pricesUpdated' => 0,
            'errors' => [
                'Character Tessa Orvik: ESI 502 Bad Gateway',
                'Character Dorin Halk: ESI 503 Service Unavailable',
            ],
        ], $results);
        $this->assertSame(self::PREVIOUS_MINING_SYNC_AT, $this->settings->getLastMiningSyncAt()?->format('Y-m-d H:i:sP'));
        $this->assertSame(['flush before last mining sync update'], $this->journal);
        $this->assertSame([
            self::syncEvent('started', 0, 'Syncing mining ledger...'),
            self::syncEvent('in_progress', 10, 'Fetching mining data...'),
            self::syncEvent('in_progress', 70, 'Updating Jita prices...'),
            self::syncEvent('error', null, 'Sync failed for all 2 characters'),
        ], $this->publishedSyncEvents);
    }

    public function testSyncAllReturnsTheEntriesAlreadyImportedAndAGlobalErrorWhenPricingFails(): void
    {
        $this->respondWith([
            self::TESSA_LEDGER => [self::ledgerLine('2026-10-10', self::SCORDITE, self::ALDRANETTE, 4_129)],
            self::DORIN_LEDGER => [],
        ]);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willThrowException(new \RuntimeException('SQLSTATE[08006] connection lost'));

        $results = $this->service->syncAll($this->user);

        $this->assertSyncCounts([
            'imported' => 1,
            'updated' => 0,
            'pricesUpdated' => 0,
            'errors' => ['Global: SQLSTATE[08006] connection lost'],
        ], $results);
        $this->assertSame(self::PREVIOUS_MINING_SYNC_AT, $this->settings->getLastMiningSyncAt()?->format('Y-m-d H:i:sP'));
        $this->assertSame(self::syncEvent('error', null, 'SQLSTATE[08006] connection lost'), end($this->publishedSyncEvents));
    }

    public function testSyncAllForAUserWithoutIdentifierPublishesNothingAndStillRecordsTheSync(): void
    {
        $this->respondWith([
            self::TESSA_LEDGER => [self::ledgerLine('2026-10-10', self::VELDSPAR, self::TAMA, 1_093)],
            self::DORIN_LEDGER => [],
        ]);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $before = new \DateTimeImmutable();

        $results = $this->service->syncAll($this->userWithCharacters(null));

        $this->assertSyncCounts(['imported' => 1, 'updated' => 0, 'pricesUpdated' => 0, 'errors' => []], $results);
        $this->assertSame([], $this->publishedSyncEvents);
        $this->assertGreaterThanOrEqual($before, $this->settings->getLastMiningSyncAt());
    }

    #[Group('mining-audit-P3-8')]
    public function testShouldSyncWithoutSettingsRowAllowsTheAutoSync(): void
    {
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn(null);

        $this->assertTrue($this->serviceWithSettingsRepository($settingsRepository)->shouldSync($this->user));
    }

    public function testShouldSyncRefusesJustBeforeThirtyMinutes(): void
    {
        $this->settings->setLastMiningSyncAt(new \DateTimeImmutable('@' . (time() - 1_776)));

        $this->assertFalse($this->service->shouldSync($this->user));
    }

    public function testShouldSyncAcceptsAtExactlyThirtyMinutes(): void
    {
        $this->settings->setLastMiningSyncAt(new \DateTimeImmutable('@' . (time() - 1_800)));

        $this->assertTrue($this->service->shouldSync($this->user));
    }

    public function testShouldSyncAcceptsJustAfterThirtyMinutes(): void
    {
        $this->settings->setLastMiningSyncAt(new \DateTimeImmutable('@' . (time() - 1_818)));

        $this->assertTrue($this->service->shouldSync($this->user));
    }

    private function assertSyncCounts(array $expected, array $results): void
    {
        $this->assertSame($expected, array_intersect_key($results, $expected));
    }

    private function respondWith(array $ledgersByEndpoint): void
    {
        $this->esiClient->method('getPaginated')->willReturnCallback(
            function (string $endpoint) use ($ledgersByEndpoint): array {
                if (str_contains($endpoint, 'from_id')) {
                    return [];
                }
                if (!array_key_exists($endpoint, $ledgersByEndpoint)) {
                    throw new \LogicException("Unexpected ESI endpoint {$endpoint}");
                }
                if ($ledgersByEndpoint[$endpoint] instanceof \Throwable) {
                    throw $ledgersByEndpoint[$endpoint];
                }

                return $ledgersByEndpoint[$endpoint];
            }
        );
    }

    private function userWithCharacters(?Uuid $userId): User&Stub
    {
        $characterWithoutToken = $this->createStub(Character::class);
        $characterWithoutToken->method('getEveCharacterId')->willReturn(91000003);
        $characterWithoutToken->method('getName')->willReturn('Ilse Marrow');
        $characterWithoutToken->method('getEveToken')->willReturn(null);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getCharacters')->willReturn(new ArrayCollection([
            $characterWithoutToken,
            $this->tokenizedCharacter(self::TESSA_ID, self::TESSA_NAME),
            $this->tokenizedCharacter(self::DORIN_ID, self::DORIN_NAME),
        ]));

        return $user;
    }

    private function tokenizedCharacter(int $eveCharacterId, string $name): Character&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getName')->willReturn($name);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));

        return $character;
    }

    private function serviceWithSettingsRepository(UserLedgerSettingsRepository $settingsRepository): MiningSyncService
    {
        return new MiningSyncService(
            $this->esiClient,
            $this->tokenManager,
            $this->marketService,
            $this->miningEntryRepository,
            $settingsRepository,
            $this->createStub(TypeNameResolver::class),
            $this->createStub(MapSolarSystemRepository::class),
            $this->entityManager,
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    private function existingEntry(string $date, int $typeId, string $typeName, int $solarSystemId, int $quantity, \DateTimeImmutable $syncedAt): MiningEntry
    {
        return (new MiningEntry())
            ->setUser($this->user)
            ->setCharacterId(self::TESSA_ID)
            ->setCharacterName(self::TESSA_NAME)
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setSolarSystemId($solarSystemId)
            ->setSolarSystemName(self::SYSTEM_NAMES[$solarSystemId] ?? "System #{$solarSystemId}")
            ->setQuantity($quantity)
            ->setSyncedAt($syncedAt);
    }

    private static function ledgerLine(string $date, int $typeId, int $solarSystemId, int $quantity): array
    {
        return ['date' => $date, 'type_id' => $typeId, 'solar_system_id' => $solarSystemId, 'quantity' => $quantity];
    }

    private static function syncEvent(string $status, ?int $progress, string $message, ?array $data = null): array
    {
        return [
            'syncType' => 'mining',
            'status' => $status,
            'progress' => $progress,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function entryRow(
        int $characterId,
        string $characterName,
        string $date,
        int $typeId,
        string $typeName,
        int $solarSystemId,
        string $solarSystemName,
        int $quantity,
        string $usage,
    ): array {
        return [
            'characterId' => $characterId,
            'characterName' => $characterName,
            'date' => "{$date} 00:00:00",
            'typeId' => $typeId,
            'typeName' => $typeName,
            'solarSystemId' => $solarSystemId,
            'solarSystemName' => $solarSystemName,
            'quantity' => $quantity,
            'unitPrice' => null,
            'totalValue' => null,
            'usage' => $usage,
            'linkedProjectId' => null,
            'linkedCorpProjectId' => null,
        ];
    }

    private function persistedEntryRows(): array
    {
        $rows = array_map(static fn (MiningEntry $entry): array => [
            'characterId' => $entry->getCharacterId(),
            'characterName' => $entry->getCharacterName(),
            'date' => $entry->getDate()->format('Y-m-d H:i:s'),
            'typeId' => $entry->getTypeId(),
            'typeName' => $entry->getTypeName(),
            'solarSystemId' => $entry->getSolarSystemId(),
            'solarSystemName' => $entry->getSolarSystemName(),
            'quantity' => $entry->getQuantity(),
            'unitPrice' => $entry->getUnitPrice(),
            'totalValue' => $entry->getTotalValue(),
            'usage' => $entry->getUsage(),
            'linkedProjectId' => $entry->getLinkedProjectId(),
            'linkedCorpProjectId' => $entry->getLinkedCorpProjectId(),
        ], $this->persisted);
        usort($rows, static fn (array $left, array $right): int => $left['date'] <=> $right['date']);

        return $rows;
    }
}
