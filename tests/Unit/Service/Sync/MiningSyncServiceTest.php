<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\MiningEntry;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
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

        $this->mercurePublisher = new MercurePublisherService(
            $this->createStub(HubInterface::class),
            new NullLogger(),
        );

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
    // syncAll — new entries created
    // ===========================================

    public function testSyncAllCreatesNewMiningEntries(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->esiClient->method('get')->willReturn([
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

        $this->esiClient->method('get')->willReturn([
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

        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturnCallback(
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

        $this->esiClient->method('get')->willReturn([]);
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

        $this->esiClient->method('get')->willReturn([]);

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

        $this->esiClient->method('get')->willReturn([]);
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

        $this->esiClient->method('get')->willReturn([
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

        $this->esiClient->method('get')->willReturn([
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

        $this->esiClient->method('get')->willReturn([
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
    // refreshAllPrices
    // ===========================================

    public function testRefreshAllPricesUpdatesAllTypes(): void
    {
        $user = $this->createStub(User::class);

        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getScalarResult')->willReturn([
            ['typeId' => 17459],
            ['typeId' => 17460],
        ]);

        $this->em->method('createQuery')->willReturn($query);

        $this->marketService->method('getJitaPrices')->willReturn([
            17459 => 5.50,
            17460 => 12.00,
        ]);

        $this->miningEntryRepository->method('updatePriceByTypeId')
            ->willReturnCallback(function (User $user, int $typeId, float $price): int {
                return match ($typeId) {
                    17459 => 20,
                    17460 => 15,
                    default => 0,
                };
            });

        $updated = $this->service->refreshAllPrices($user);

        $this->assertSame(35, $updated);
    }

    public function testRefreshAllPricesReturnsZeroWhenNoEntries(): void
    {
        $user = $this->createStub(User::class);

        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getScalarResult')->willReturn([]);

        $this->em->method('createQuery')->willReturn($query);

        $updated = $this->service->refreshAllPrices($user);

        $this->assertSame(0, $updated);
    }

    public function testRefreshAllPricesSkipsZeroPrices(): void
    {
        $user = $this->createStub(User::class);

        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getScalarResult')->willReturn([
            ['typeId' => 17459],
        ]);

        $this->em->method('createQuery')->willReturn($query);

        $this->marketService->method('getJitaPrices')->willReturn([
            17459 => 0.0,
        ]);

        $this->miningEntryRepository->expects($this->never())->method('updatePriceByTypeId');

        $updated = $this->service->refreshAllPrices($user);

        $this->assertSame(0, $updated);
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
}
