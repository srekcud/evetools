<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Ledger;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Ledger\MiningSyncResource;
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
use App\State\Processor\Ledger\SyncMiningProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncMiningProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
final class SyncMiningProcessorCharacterizationTest extends TestCase
{
    private const SELKA_ID = 92000001;
    private const SELKA_NAME = 'Selka Rhun';
    private const OREN_ID = 92000002;
    private const OREN_NAME = 'Oren Calder';
    private const SELKA_LEDGER = '/characters/92000001/mining/';
    private const OREN_LEDGER = '/characters/92000002/mining/';

    private const OMBER = 1227;
    private const JASPET = 1226;
    private const HEDBERGITE = 21;

    private const PREVIOUS_MINING_SYNC_AT = '2026-10-01T08:15:00+00:00';

    private Security&Stub $security;
    private EsiClient&MockObject $esiClient;
    private MarketService&Stub $marketService;
    private MiningEntryRepository&Stub $miningEntryRepository;
    private UserLedgerSettings $settings;
    private SyncMiningProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->marketService = $this->createStub(MarketService::class);
        $this->miningEntryRepository = $this->createStub(MiningEntryRepository::class);

        $this->settings = new UserLedgerSettings();
        $this->settings->setLastMiningSyncAt(new \DateTimeImmutable(self::PREVIOUS_MINING_SYNC_AT));
        $this->processor = $this->processorWithStoredSettings($this->settings);
    }

    public function testSuccessfulSyncReportsImportedUpdatedAndPricedCountsWithTheNewLastSyncTime(): void
    {
        $this->signedInAs($this->userWithCharacters());
        $existingJaspet = (new MiningEntry())->setTypeName('Jaspet')->setQuantity(611);
        $this->miningEntryRepository->method('findByUniqueKey')->willReturnCallback(
            static fn (User $user, int $characterId, \DateTimeImmutable $date, int $typeId): ?MiningEntry
                => $typeId === self::JASPET ? $existingJaspet : null
        );
        $this->respondWith([
            self::SELKA_LEDGER => [
                ['date' => '2026-10-10', 'type_id' => self::OMBER, 'solar_system_id' => 30002813, 'quantity' => 4_877],
                ['date' => '2026-10-10', 'type_id' => self::JASPET, 'solar_system_id' => 30002813, 'quantity' => 653],
            ],
            self::OREN_LEDGER => [
                ['date' => '2026-10-09', 'type_id' => self::HEDBERGITE, 'solar_system_id' => 30003504, 'quantity' => 1_309],
            ],
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([self::OMBER, self::HEDBERGITE]);
        $this->marketService->method('getJitaPrices')->willReturn([self::OMBER => 64.0, self::HEDBERGITE => 412.0]);
        $this->miningEntryRepository->method('updatePriceByTypeId')->willReturnCallback(
            static fn (User $user, int $typeId): int => [self::OMBER => 3, self::HEDBERGITE => 4][$typeId]
        );
        $before = time();

        $resource = $this->sync();

        $this->assertSame('success', $resource->status);
        $this->assertSame('2 entries imported, 1 updated, 7 prices refreshed', $resource->message);
        $this->assertSame(['entries' => 2, 'updated' => 1, 'pricesUpdated' => 7], $resource->imported);
        $this->assertSame([], $resource->errors);
        $this->assertSame($this->settings->getLastMiningSyncAt()?->format('c'), $resource->lastSyncAt);
        $this->assertGreaterThanOrEqual($before, strtotime((string) $resource->lastSyncAt));
        $this->assertLessThanOrEqual(time(), strtotime((string) $resource->lastSyncAt));
    }

    #[Group('mining-audit-P2-3')]
    public function testSyncWhereOneCharacterFailsIsPartialWithItsErrorAndTheNewLastSyncTime(): void
    {
        $this->signedInAs($this->userWithCharacters());
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->respondWith([
            self::SELKA_LEDGER => [
                ['date' => '2026-10-10', 'type_id' => self::OMBER, 'solar_system_id' => 30002813, 'quantity' => 4_877],
            ],
            self::OREN_LEDGER => new \RuntimeException('ESI 504 Gateway Timeout'),
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $resource = $this->sync();

        $this->assertSame('partial', $resource->status);
        $this->assertStringStartsWith('1 entries imported, 0 updated, 0 prices refreshed', $resource->message);
        $this->assertSame(['entries' => 1, 'updated' => 0, 'pricesUpdated' => 0], $resource->imported);
        $this->assertSame(['Character Oren Calder: ESI 504 Gateway Timeout'], $resource->errors);
        $this->assertNotSame(self::PREVIOUS_MINING_SYNC_AT, $resource->lastSyncAt);
        $this->assertSame($this->settings->getLastMiningSyncAt()?->format('c'), $resource->lastSyncAt);
    }

    #[Group('mining-audit-P2-3')]
    public function testSyncWhereEveryCharacterFailsIsCurrentlyReportedPartialMiningAuditP23(): void
    {
        $this->signedInAs($this->userWithCharacters());
        $this->respondWith([
            self::SELKA_LEDGER => new \RuntimeException('ESI 502 Bad Gateway'),
            self::OREN_LEDGER => new \RuntimeException('ESI 503 Service Unavailable'),
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $resource = $this->sync();

        $this->assertSame('partial', $resource->status);
        $this->assertSame('0 entries imported, 0 updated, 0 prices refreshed', $resource->message);
        $this->assertSame(['entries' => 0, 'updated' => 0, 'pricesUpdated' => 0], $resource->imported);
        $this->assertSame([
            'Character Selka Rhun: ESI 502 Bad Gateway',
            'Character Oren Calder: ESI 503 Service Unavailable',
        ], $resource->errors);
        $this->assertSame(self::PREVIOUS_MINING_SYNC_AT, $resource->lastSyncAt);
    }

    #[Group('mining-audit-P2-3')]
    public function testSyncWhosePricingFailsIsCurrentlyReportedPartialMiningAuditP23(): void
    {
        $this->signedInAs($this->userWithCharacters());
        $this->miningEntryRepository->method('findByUniqueKey')->willReturn(null);
        $this->respondWith([
            self::SELKA_LEDGER => [
                ['date' => '2026-10-10', 'type_id' => self::JASPET, 'solar_system_id' => 30002813, 'quantity' => 653],
            ],
            self::OREN_LEDGER => [],
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willThrowException(new \RuntimeException('SQLSTATE[08006] connection lost'));

        $resource = $this->sync();

        $this->assertSame('partial', $resource->status);
        $this->assertSame('1 entries imported, 0 updated, 0 prices refreshed', $resource->message);
        $this->assertSame(['entries' => 1, 'updated' => 0, 'pricesUpdated' => 0], $resource->imported);
        $this->assertSame(['Global: SQLSTATE[08006] connection lost'], $resource->errors);
        $this->assertSame(self::PREVIOUS_MINING_SYNC_AT, $resource->lastSyncAt);
    }

    #[Group('mining-audit-P2-3')]
    public function testFirstSyncWithoutSettingsRowWhereEveryCharacterFailsIsCurrentlyPartialWithoutLastSyncTimeMiningAuditP23(): void
    {
        $this->processor = $this->processorWithStoredSettings(null);
        $this->signedInAs($this->userWithCharacters());
        $this->respondWith([
            self::SELKA_LEDGER => new \RuntimeException('ESI 502 Bad Gateway'),
            self::OREN_LEDGER => new \RuntimeException('ESI 503 Service Unavailable'),
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $resource = $this->sync();

        $this->assertSame('partial', $resource->status);
        $this->assertSame('0 entries imported, 0 updated, 0 prices refreshed', $resource->message);
        $this->assertSame(['entries' => 0, 'updated' => 0, 'pricesUpdated' => 0], $resource->imported);
        $this->assertSame([
            'Character Selka Rhun: ESI 502 Bad Gateway',
            'Character Oren Calder: ESI 503 Service Unavailable',
        ], $resource->errors);
        $this->assertNull($resource->lastSyncAt);
    }

    #[Group('mining-audit-P2-3')]
    public function testNeverSyncedSettingsWhereEveryCharacterFailsIsCurrentlyPartialWithoutLastSyncTimeMiningAuditP23(): void
    {
        $this->processor = $this->processorWithStoredSettings(new UserLedgerSettings());
        $this->signedInAs($this->userWithCharacters());
        $this->respondWith([
            self::SELKA_LEDGER => new \RuntimeException('ESI 502 Bad Gateway'),
            self::OREN_LEDGER => new \RuntimeException('ESI 503 Service Unavailable'),
        ]);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);

        $resource = $this->sync();

        $this->assertSame('partial', $resource->status);
        $this->assertSame([
            'Character Selka Rhun: ESI 502 Bad Gateway',
            'Character Oren Calder: ESI 503 Service Unavailable',
        ], $resource->errors);
        $this->assertNull($resource->lastSyncAt);
    }

    #[Group('mining-proposal-sync-without-token')]
    public function testUserWithoutAnyTokenIsCurrentlyReportedSuccessWithNothingImported(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection([$this->character(92000003, 'Vey Ashlin', null)]));
        $this->signedInAs($user);
        $this->miningEntryRepository->method('getTypeIdsWithoutPrice')->willReturn([]);
        $this->esiClient->expects($this->never())->method('getPaginated');

        $resource = $this->sync();

        $this->assertSame('success', $resource->status);
        $this->assertSame('0 entries imported, 0 updated, 0 prices refreshed', $resource->message);
        $this->assertSame(['entries' => 0, 'updated' => 0, 'pricesUpdated' => 0], $resource->imported);
        $this->assertSame([], $resource->errors);
        $this->assertSame($this->settings->getLastMiningSyncAt()?->format('c'), $resource->lastSyncAt);
        $this->assertNotSame(self::PREVIOUS_MINING_SYNC_AT, $resource->lastSyncAt);
    }

    public function testAnonymousRequestIsRejectedBeforeAnyEsiCall(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->esiClient->expects($this->never())->method('getPaginated');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Unauthorized');

        $this->sync();
    }

    private function processorWithStoredSettings(?UserLedgerSettings $storedSettings): SyncMiningProcessor
    {
        $settingsRepository = $this->createStub(UserLedgerSettingsRepository::class);
        $settingsRepository->method('findByUser')->willReturn($storedSettings);
        $settingsRepository->method('getOrCreate')->willReturn($storedSettings ?? new UserLedgerSettings());

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('resolve')->willReturnCallback(
            static fn (int $typeId): string => [self::OMBER => 'Omber', self::JASPET => 'Jaspet', self::HEDBERGITE => 'Hedbergite'][$typeId]
        );

        $miningSyncService = new MiningSyncService(
            $this->esiClient,
            $this->createStub(TokenManager::class),
            $this->marketService,
            $this->miningEntryRepository,
            $settingsRepository,
            $typeNameResolver,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );

        return new SyncMiningProcessor($this->security, $miningSyncService, $settingsRepository);
    }

    private function sync(): MiningSyncResource
    {
        return $this->processor->process(null, new Post());
    }

    private function signedInAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
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

    private function userWithCharacters(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection([
            $this->character(self::SELKA_ID, self::SELKA_NAME, $this->createStub(EveToken::class)),
            $this->character(self::OREN_ID, self::OREN_NAME, $this->createStub(EveToken::class)),
        ]));

        return $user;
    }

    private function character(int $eveCharacterId, string $name, ?EveToken $token): Character&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getName')->willReturn($name);
        $character->method('getEveToken')->willReturn($token);

        return $character;
    }
}
