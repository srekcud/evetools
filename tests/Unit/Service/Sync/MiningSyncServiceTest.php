<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\MiningEntry;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Exception\EveAuthRequiredException;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MiningSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class MiningSyncServiceTest extends TestCase
{
    private EsiClient&Stub $esiClient;
    private TokenManager&Stub $tokenManager;
    private MarketService&Stub $marketService;
    private MiningEntryRepository&MockObject $miningEntryRepository;
    private UserLedgerSettingsRepository&MockObject $settingsRepository;
    private TypeNameResolver&Stub $typeNameResolver;
    private MapSolarSystemRepository&Stub $solarSystemRepository;
    private EntityManagerInterface&MockObject $em;
    private MercurePublisherService $mercurePublisher;
    private MiningSyncService $service;

    /** @var list<array<string, mixed>> Mercure sync payloads, in publication order */
    private array $publishedSyncEvents = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->tokenManager = $this->createStub(TokenManager::class);
        $this->marketService = $this->createStub(MarketService::class);
        $this->miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $this->settingsRepository = $this->createMock(UserLedgerSettingsRepository::class);
        $this->typeNameResolver = $this->createStub(TypeNameResolver::class);
        $this->solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->publishedSyncEvents = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedSyncEvents[] = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
            return 'urn:uuid:test';
        });

        $this->mercurePublisher = new MercurePublisherService($hub, new NullLogger());

        $this->service = new MiningSyncService(
            $this->esiClient,
            $this->tokenManager,
            $this->marketService,
            $this->miningEntryRepository,
            $this->settingsRepository,
            $this->typeNameResolver,
            $this->solarSystemRepository,
            $this->em,
            new NullLogger(),
            $this->mercurePublisher,
        );
    }

    // ===========================================
    // shouldSync — timing logic
    // ===========================================

    public function testShouldSyncReturnsTrueWhenNoSettings(): void
    {
        $user = $this->createStub(User::class);
        $this->settingsRepository->method('findByUser')->willReturn(null);

        $this->assertTrue($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsFalseWhenAutoSyncDisabled(): void
    {
        $user = $this->createStub(User::class);
        $settings = new UserLedgerSettings();
        $settings->setAutoSyncEnabled(false);
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertFalse($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsFalseWhenRecentlySynced(): void
    {
        $user = $this->createStub(User::class);
        $settings = new UserLedgerSettings();
        $settings->setAutoSyncEnabled(true);
        $settings->setLastMiningSyncAt(new \DateTimeImmutable('-10 minutes'));
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertFalse($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsTrueWhenNeverSynced(): void
    {
        $user = $this->createStub(User::class);
        $settings = new UserLedgerSettings();
        $settings->setAutoSyncEnabled(true);
        // lastMiningSyncAt is null by default
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertTrue($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsTrueWhenSyncIntervalElapsed(): void
    {
        $user = $this->createStub(User::class);
        $settings = new UserLedgerSettings();
        $settings->setAutoSyncEnabled(true);
        $settings->setLastMiningSyncAt(new \DateTimeImmutable('-35 minutes'));
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertTrue($this->service->shouldSync($user));
    }

    // ===========================================
    // canSync — token availability
    // ===========================================

    public function testCanSyncReturnsFalseWhenNoCharacters(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([]));

        $this->assertFalse($this->service->canSync($user));
    }

    public function testCanSyncReturnsFalseWhenNoCharacterHasToken(): void
    {
        $user = $this->createStub(User::class);
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->assertFalse($this->service->canSync($user));
    }

    public function testCanSyncReturnsTrueWhenCharacterHasToken(): void
    {
        $user = $this->createStub(User::class);
        $token = $this->createStub(EveToken::class);
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->assertTrue($this->service->canSync($user));
    }

    // ===========================================
    // syncAll — mining ledger pagination (issue #11)
    // ===========================================

    public function testSyncAllImportsMiningLedgerEntriesFromSecondPage(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $ledgerPage1 = [[
            'date' => '2026-02-20',
            'type_id' => 17459,
            'solar_system_id' => 30004759,
            'quantity' => 50000,
        ]];
        $ledgerPage2 = [[
            'date' => '2026-01-15',
            'type_id' => 17460,
            'solar_system_id' => 30004759,
            'quantity' => 30000,
        ]];
        // get() only ever sees page 1 (no X-Pages handling); getPaginated() merges all pages
        $this->esiClient->method('get')->willReturn($ledgerPage1);
        $this->esiClient->method('getPaginated')->willReturn([...$ledgerPage1, ...$ledgerPage2]);

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->solarSystemRepository->method('find')->willReturn(null);
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $persistedQuantities = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $entry) use (&$persistedQuantities): void {
                if ($entry instanceof MiningEntry) {
                    $persistedQuantities[$entry->getTypeId()] = $entry->getQuantity();
                }
            }
        );

        $result = $this->service->syncAll($user);

        $this->assertSame([17459 => 50000, 17460 => 30000], $persistedQuantities);
        $this->assertSame(2, $result['imported']);
    }

    // ===========================================
    // syncAll — new entries created
    // ===========================================

    public function testSyncAllCreatesNewMiningEntries(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([
            [
                'date' => '2026-02-20',
                'type_id' => 17459,
                'solar_system_id' => 30004759,
                'quantity' => 50000,
            ],
            [
                'date' => '2026-02-20',
                'type_id' => 17460,
                'solar_system_id' => 30004759,
                'quantity' => 30000,
            ],
        ]);

        // No existing entries
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->solarSystemRepository->method('find')->willReturn(null);

        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $this->em->expects($this->exactly(2))->method('persist');

        $result = $this->service->syncAll($user);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['updated']);
        $this->assertEmpty($result['errors']);
    }

    public function testSyncAllUpdatesExistingEntryQuantity(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([
            [
                'date' => '2026-02-20',
                'type_id' => 17459,
                'solar_system_id' => 30004759,
                'quantity' => 75000,
            ],
        ]);

        // Existing entry with different quantity
        $existing = new MiningEntry();
        $existing->setUser($user);
        $existing->setCharacterId(12345);
        $existing->setDate(new \DateTimeImmutable('2026-02-20'));
        $existing->setTypeId(17459);
        $existing->setTypeName('Scordite');
        $existing->setSolarSystemId(30004759);
        $existing->setSolarSystemName('1DQ1-A');
        $existing->setQuantity(50000);

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn($existing);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        // No new persist expected since we update an existing entry
        $this->em->expects($this->never())->method('persist');

        $result = $this->service->syncAll($user);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(75000, $existing->getQuantity());
    }

    public function testSyncAllSkipsUnchangedEntries(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([
            [
                'date' => '2026-02-20',
                'type_id' => 17459,
                'solar_system_id' => 30004759,
                'quantity' => 50000,
            ],
        ]);

        // Existing entry with same quantity
        $existing = new MiningEntry();
        $existing->setUser($user);
        $existing->setCharacterId(12345);
        $existing->setDate(new \DateTimeImmutable('2026-02-20'));
        $existing->setTypeId(17459);
        $existing->setTypeName('Scordite');
        $existing->setSolarSystemId(30004759);
        $existing->setSolarSystemName('1DQ1-A');
        $existing->setQuantity(50000);

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn($existing);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $result = $this->service->syncAll($user);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['updated']);
    }

    // ===========================================
    // syncAll — error handling per character
    // ===========================================

    public function testSyncAllHandlesEsiErrorPerCharacterWithoutCrashing(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);

        $charOk = $this->createStub(Character::class);
        $charOk->method('getEveCharacterId')->willReturn(11111);
        $charOk->method('getEveToken')->willReturn($token);
        $charOk->method('getName')->willReturn('CharOk');

        $charFail = $this->createStub(Character::class);
        $charFail->method('getEveCharacterId')->willReturn(22222);
        $charFail->method('getEveToken')->willReturn($token);
        $charFail->method('getName')->willReturn('CharFail');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$charFail, $charOk]));

        $callCount = 0;
        $this->esiClient->method('getPaginated')->willReturnCallback(
            function (string $url) use (&$callCount): array {
                $callCount++;
                if (str_contains($url, '22222')) {
                    throw new \RuntimeException('ESI 502 Bad Gateway');
                }
                return [
                    [
                        'date' => '2026-02-20',
                        'type_id' => 17459,
                        'solar_system_id' => 30004759,
                        'quantity' => 10000,
                    ],
                ];
            }
        );

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->solarSystemRepository->method('find')->willReturn(null);
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $result = $this->service->syncAll($user);

        $this->assertSame(1, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('CharFail', $result['errors'][0]);
        $this->assertStringContainsString('ESI 502', $result['errors'][0]);
    }

    // ===========================================
    // syncAll — token refresh
    // ===========================================

    public function testSyncAllRefreshesExpiringSoonToken(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createMock(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(true);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(12345);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->esiClient->method('getPaginated')->willReturn([]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        // TokenManager should be called to refresh
        $tokenManager = $this->createMock(TokenManager::class);
        $tokenManager->expects($this->once())
            ->method('refreshAccessToken')
            ->with($token);

        // Rebuild the service with mock instead of stub for tokenManager
        $service = new MiningSyncService(
            $this->esiClient,
            $tokenManager,
            $this->marketService,
            $this->miningEntryRepository,
            $this->settingsRepository,
            $this->typeNameResolver,
            $this->solarSystemRepository,
            $this->em,
            new NullLogger(),
            $this->mercurePublisher,
        );

        $service->syncAll($user);
    }

    // ===========================================
    // syncAll — skips characters without token
    // ===========================================

    public function testSyncAllSkipsCharacterWithoutToken(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $charNoToken = $this->createStub(Character::class);
        $charNoToken->method('getEveToken')->willReturn(null);
        $charNoToken->method('getName')->willReturn('NoTokenChar');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$charNoToken]));

        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $result = $this->service->syncAll($user);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['updated']);
        $this->assertEmpty($result['errors']);
    }

    // ===========================================
    // syncAll — price update
    // ===========================================

    public function testSyncAllUpdatesPricesForEntriesWithoutPrice(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([]);

        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([17459, 17460]);
        $this->marketService->method('getJitaPrices')->willReturn([
            17459 => 5.50,
            17460 => 12.00,
        ]);
        $this->miningEntryRepository->method('updatePriceByTypeId')
            ->willReturnCallback(function (User $user, int $typeId, float $price): int {
                return match ($typeId) {
                    17459 => 10,
                    17460 => 5,
                    default => 0,
                };
            });

        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $result = $this->service->syncAll($user);

        $this->assertSame(15, $result['pricesUpdated']);
    }

    // ===========================================
    // syncAll — updates lastSyncTime
    // ===========================================

    public function testSyncAllUpdatesLastSyncTime(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $settings = new UserLedgerSettings();
        $this->assertNull($settings->getLastMiningSyncAt());

        $this->settingsRepository->method('getOrCreate')->willReturn($settings);

        $this->service->syncAll($user);

        $this->assertNotNull($settings->getLastMiningSyncAt());
    }

    // ===========================================
    // syncAll — default sold usage applied
    // ===========================================

    public function testSyncAllAppliesDefaultSoldUsageForMatchingTypeIds(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([
            [
                'date' => '2026-02-20',
                'type_id' => 17459,
                'solar_system_id' => 30004759,
                'quantity' => 50000,
            ],
        ]);

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->solarSystemRepository->method('find')->willReturn(null);

        $settings = new UserLedgerSettings();
        $settings->setDefaultSoldTypeIds([17459]);
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);

        $persistedEntry = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedEntry): void {
                $persistedEntry = $entity;
            });

        $this->service->syncAll($user);

        $this->assertInstanceOf(MiningEntry::class, $persistedEntry);
        $this->assertSame(MiningEntry::USAGE_SOLD, $persistedEntry->getUsage());
    }

    public function testSyncAllSetsUnknownUsageWhenTypeNotInDefaultSold(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([
            [
                'date' => '2026-02-20',
                'type_id' => 17459,
                'solar_system_id' => 30004759,
                'quantity' => 50000,
            ],
        ]);

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->solarSystemRepository->method('find')->willReturn(null);

        // Settings exist but typeId 17459 is NOT in defaultSoldTypeIds
        $settings = new UserLedgerSettings();
        $settings->setDefaultSoldTypeIds([99999]);
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);

        $persistedEntry = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedEntry): void {
                $persistedEntry = $entity;
            });

        $this->service->syncAll($user);

        $this->assertInstanceOf(MiningEntry::class, $persistedEntry);
        $this->assertSame(MiningEntry::USAGE_UNKNOWN, $persistedEntry->getUsage());
    }

    // ===========================================
    // syncAll — type name resolution on update
    // ===========================================

    public function testSyncAllFixesUnresolvedTypeNameOnExistingEntry(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('getPaginated')->willReturn([
            [
                'date' => '2026-02-20',
                'type_id' => 17459,
                'solar_system_id' => 30004759,
                'quantity' => 50000,
            ],
        ]);

        // Existing entry with unresolved name (from SDE gap)
        $existing = new MiningEntry();
        $existing->setUser($user);
        $existing->setCharacterId(12345);
        $existing->setDate(new \DateTimeImmutable('2026-02-20'));
        $existing->setTypeId(17459);
        $existing->setTypeName('Type #17459');
        $existing->setSolarSystemId(30004759);
        $existing->setSolarSystemName('1DQ1-A');
        $existing->setQuantity(50000);

        $this->miningEntryRepository->method('findByUniqueKey')->willReturn($existing);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->settingsRepository->method('getOrCreate')->willReturn(new UserLedgerSettings());

        $result = $this->service->syncAll($user);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('Scordite', $existing->getTypeName());
    }

    // ===========================================
    // syncAll — failure reporting (issue #12)
    // ===========================================

    private const PREVIOUS_MINING_SYNC_AT = '2026-01-01 00:00:00';

    public function testSyncAllPublishesSyncErrorAndKeepsLastSyncWhenEveryCharacterFails(): void
    {
        $user = $this->createUserWithOkAndFailingCharacters();
        $this->esiClient->method('getPaginated')->willThrowException(new \RuntimeException('ESI 502 Bad Gateway'));
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $settings = $this->settingsSyncedAt(self::PREVIOUS_MINING_SYNC_AT);

        $this->syncAllIgnoringException($user);

        $this->assertNotContains('completed', $this->publishedStatuses());
        $this->assertSame('error', $this->lastSyncEvent()['status']);
        $this->assertSame('Sync failed for all 2 characters', $this->lastSyncEvent()['message']);
        $this->assertEquals(new \DateTimeImmutable(self::PREVIOUS_MINING_SYNC_AT), $settings->getLastMiningSyncAt());
    }

    public function testSyncAllPublishesSyncErrorAndKeepsLastSyncOnGlobalError(): void
    {
        $user = $this->createUserWithCharacter(12345);
        $this->esiClient->method('getPaginated')->willReturn([]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([17459]);
        $this->marketService->method('getJitaPrices')->willThrowException(new \RuntimeException('Jita prices unavailable'));
        $settings = $this->settingsSyncedAt(self::PREVIOUS_MINING_SYNC_AT);

        $this->syncAllIgnoringException($user);

        $this->assertNotContains('completed', $this->publishedStatuses());
        $this->assertSame('error', $this->lastSyncEvent()['status']);
        $this->assertSame('Jita prices unavailable', $this->lastSyncEvent()['message']);
        $this->assertEquals(new \DateTimeImmutable(self::PREVIOUS_MINING_SYNC_AT), $settings->getLastMiningSyncAt());
    }

    public function testSyncAllReportsFailedCharacterCountWhenOneCharacterFails(): void
    {
        $user = $this->createUserWithOkAndFailingCharacters();
        $this->esiClient->method('getPaginated')->willReturnCallback(
            function (string $endpoint): array {
                if (str_contains($endpoint, '22222')) {
                    throw new \RuntimeException('ESI 502 Bad Gateway');
                }
                return [$this->scorditeLedgerEntry()];
            }
        );
        $this->stubNewMiningEntryResolution();
        $settings = $this->settingsSyncedAt(self::PREVIOUS_MINING_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 imported, 0 updated, 0 prices refreshed (1 of 2 characters failed)', $completed['message']);
        $this->assertSame(1, $completed['data']['imported']);
        $this->assertSame(1, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastMiningSyncAt());
    }

    public function testSyncAllCountsRevokedCharacterAsFailedAndSyncsTheOthers(): void
    {
        $user = $this->createUserWithOkAndFailingCharacters(failingTokenExpiringSoon: true);
        $this->tokenManager->method('refreshAccessToken')->willThrowException(new EveAuthRequiredException('22222'));
        $this->esiClient->method('getPaginated')->willReturn([$this->scorditeLedgerEntry()]);
        $this->stubNewMiningEntryResolution();
        $settings = $this->settingsSyncedAt(self::PREVIOUS_MINING_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $result = $this->service->syncAll($user);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(['started', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 imported, 0 updated, 0 prices refreshed (1 of 2 characters failed)', $completed['message']);
        $this->assertSame(1, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastMiningSyncAt());
    }

    public function testSyncAllPublishesPlainCompletedMessageWhenEveryCharacterSucceeds(): void
    {
        $user = $this->createUserWithCharacter(12345);
        $this->esiClient->method('getPaginated')->willReturn([$this->scorditeLedgerEntry()]);
        $this->stubNewMiningEntryResolution();
        $settings = $this->settingsSyncedAt(self::PREVIOUS_MINING_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 imported, 0 updated, 0 prices refreshed', $completed['message']);
        $this->assertSame(1, $completed['data']['imported']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastMiningSyncAt());
    }

    public function testSyncAllPublishesCompletedWithoutFailureWhenNoCharacterHasToken(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $characterWithoutToken = $this->createStub(Character::class);
        $characterWithoutToken->method('getEveToken')->willReturn(null);
        $characterWithoutToken->method('getName')->willReturn('NoTokenChar');
        $user->method('getCharacters')->willReturn(new ArrayCollection([$characterWithoutToken]));
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $settings = $this->settingsSyncedAt(self::PREVIOUS_MINING_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('0 imported, 0 updated, 0 prices refreshed', $completed['message']);
        $this->assertSame(0, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastMiningSyncAt());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithCharacter(int $eveCharacterId): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function createUserWithOkAndFailingCharacters(bool $failingTokenExpiringSoon = false): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $okToken = $this->createStub(EveToken::class);
        $okToken->method('isExpiringSoon')->willReturn(false);
        $failingToken = $this->createStub(EveToken::class);
        $failingToken->method('isExpiringSoon')->willReturn($failingTokenExpiringSoon);

        $charOk = $this->createStub(Character::class);
        $charOk->method('getEveCharacterId')->willReturn(11111);
        $charOk->method('getEveToken')->willReturn($okToken);
        $charOk->method('getName')->willReturn('CharOk');

        $charFail = $this->createStub(Character::class);
        $charFail->method('getEveCharacterId')->willReturn(22222);
        $charFail->method('getEveToken')->willReturn($failingToken);
        $charFail->method('getName')->willReturn('CharFail');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$charFail, $charOk]));

        return $user;
    }

    private function settingsSyncedAt(string $lastMiningSyncAt): UserLedgerSettings
    {
        $settings = new UserLedgerSettings();
        $settings->setLastMiningSyncAt(new \DateTimeImmutable($lastMiningSyncAt));
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);

        return $settings;
    }

    private function stubNewMiningEntryResolution(): void
    {
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->typeNameResolver->method('resolve')->willReturn('Scordite');
        $this->solarSystemRepository->method('find')->willReturn(null);
    }

    /** @return array{date: string, type_id: int, solar_system_id: int, quantity: int} */
    private function scorditeLedgerEntry(): array
    {
        return ['date' => '2026-02-20', 'type_id' => 17459, 'solar_system_id' => 30004759, 'quantity' => 10000];
    }

    /** The outcome is asserted through Mercure and settings; whether syncAll rethrows is left open */
    private function syncAllIgnoringException(User $user): void
    {
        try {
            $this->service->syncAll($user);
        } catch (\Throwable) {
        }
    }

    /** @return list<string> */
    private function publishedStatuses(): array
    {
        return array_column($this->publishedSyncEvents, 'status');
    }

    /** @return array<string, mixed> */
    private function lastSyncEvent(): array
    {
        $this->assertNotEmpty($this->publishedSyncEvents);

        return $this->publishedSyncEvents[array_key_last($this->publishedSyncEvents)];
    }
}
