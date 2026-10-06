<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Dto\AssetDto;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\CorporationService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AssetsSyncService;
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

#[CoversClass(AssetsSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class AssetsSyncServiceTest extends TestCase
{
    private AssetsService&Stub $assetsService;
    private CorporationService&Stub $corporationService;
    private CachedAssetRepository&MockObject $cachedAssetRepository;
    private CharacterRepository&Stub $characterRepository;
    private CorpAssetVisibilityRepository&Stub $visibilityRepository;
    private EntityManagerInterface&MockObject $em;
    private AssetsSyncService $service;

    /** @var list<CachedAsset> */
    private array $persistedAssets = [];

    protected function setUp(): void
    {
        $this->assetsService = $this->createStub(AssetsService::class);
        $this->corporationService = $this->createStub(CorporationService::class);
        $this->cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $this->characterRepository = $this->createStub(CharacterRepository::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $mercurePublisher = new MercurePublisherService(
            $this->createStub(HubInterface::class),
            new NullLogger(),
        );

        $this->service = new AssetsSyncService(
            $this->assetsService,
            $this->corporationService,
            $this->cachedAssetRepository,
            $this->characterRepository,
            $this->visibilityRepository,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
        );
    }

    // ===========================================
    // syncCharacterAssets — full replace strategy
    // ===========================================

    public function testSyncCharacterAssetsDeletesExistingThenPersistsNew(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->cachedAssetRepository->expects($this->once())
            ->method('deleteByCharacter')
            ->with($character);

        $assets = [
            new AssetDto(
                itemId: 1001,
                typeId: 34,
                typeName: 'Tritanium',
                quantity: 50000,
                locationId: 60003760,
                locationName: 'Jita IV - Moon 4',
                locationType: 'station',
                locationFlag: 'Hangar',
                solarSystemId: 30000142,
                solarSystemName: 'Jita',
                itemName: null,
            ),
            new AssetDto(
                itemId: 1002,
                typeId: 35,
                typeName: 'Pyerite',
                quantity: 30000,
                locationId: 60003760,
                locationName: 'Jita IV - Moon 4',
                locationType: 'station',
                locationFlag: 'Hangar',
                solarSystemId: 30000142,
                solarSystemName: 'Jita',
                itemName: null,
            ),
        ];

        $this->assetsService->method('getCharacterAssets')->willReturn($assets);

        // 2 assets persisted
        $this->em->expects($this->exactly(2))->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterAssets($character);
    }

    public function testSyncCharacterAssetsWithEmptyResultDeletesAndFlushes(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->cachedAssetRepository->expects($this->once())
            ->method('deleteByCharacter')
            ->with($character);

        $this->assetsService->method('getCharacterAssets')->willReturn([]);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterAssets($character);
    }

    // ===========================================
    // shouldSync — timing logic
    // ===========================================

    public function testShouldSyncReturnsTrueWhenNeverSynced(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getLastSyncAt')->willReturn(null);

        $this->assertTrue($this->service->shouldSync($character));
    }

    public function testShouldSyncReturnsFalseWhenRecentlySynced(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getLastSyncAt')->willReturn(new \DateTimeImmutable('-10 minutes'));

        $this->assertFalse($this->service->shouldSync($character));
    }

    public function testShouldSyncReturnsTrueWhenSyncIntervalElapsed(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getLastSyncAt')->willReturn(new \DateTimeImmutable('-35 minutes'));

        $this->assertTrue($this->service->shouldSync($character));
    }

    // ===========================================
    // canSync — token and user checks
    // ===========================================

    public function testCanSyncReturnsFalseWhenNoToken(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);

        $this->assertFalse($this->service->canSync($character));
    }

    public function testCanSyncReturnsFalseWhenUserAuthInvalid(): void
    {
        $user = $this->createStub(User::class);
        $user->method('isAuthValid')->willReturn(false);

        $character = $this->createStub(Character::class);
        $token = $this->createStub(\App\Entity\EveToken::class);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getUser')->willReturn($user);

        $this->assertFalse($this->service->canSync($character));
    }

    public function testCanSyncReturnsTrueWhenTokenAndAuthValid(): void
    {
        $user = $this->createStub(User::class);
        $user->method('isAuthValid')->willReturn(true);

        $character = $this->createStub(Character::class);
        $token = $this->createStub(\App\Entity\EveToken::class);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getUser')->willReturn($user);

        $this->assertTrue($this->service->canSync($character));
    }

    // ===========================================
    // syncCorporationAssetsForCorp — character lookup
    // ===========================================

    public function testSyncCorporationReturnsFalseWhenNoCharacterWithAccess(): void
    {
        $this->characterRepository->method('findWithCorpAssetsAccess')->willReturn(null);

        $result = $this->service->syncCorporationAssetsForCorp(98000001);

        $this->assertFalse($result);
    }

    // ===========================================
    // canSyncCorporationAssets
    // ===========================================

    public function testCanSyncCorporationAssetsReturnsTrueWhenCharacterFound(): void
    {
        $character = $this->createStub(Character::class);
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->characterRepository->method('findWithCorpAssetsAccess')->willReturn($character);

        $this->assertTrue($this->service->canSyncCorporationAssets(98000001));
    }

    public function testCanSyncCorporationAssetsReturnsFalseWhenNoCharacterFound(): void
    {
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->characterRepository->method('findWithCorpAssetsAccess')->willReturn(null);

        $this->assertFalse($this->service->canSyncCorporationAssets(98000001));
    }

    // ===========================================
    // getCorpAssetsCharacter — Director priority
    // ===========================================

    public function testGetCorpAssetsCharacterPrefersDirectorFromVisibilityConfig(): void
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);

        $directorCharacter = $this->createStub(Character::class);
        $directorCharacter->method('getCorporationId')->willReturn(98000001);
        $directorCharacter->method('getEveToken')->willReturn($token);

        $directorUser = $this->createStub(User::class);
        $directorUser->method('getCharacters')->willReturn(new ArrayCollection([$directorCharacter]));

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1]);
        $visibility->setConfiguredBy($directorUser);

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);

        // The fallback should NOT be used
        $fallbackCharacter = $this->createStub(Character::class);
        $this->characterRepository->method('findWithCorpAssetsAccess')->willReturn($fallbackCharacter);

        $result = $this->service->getCorpAssetsCharacter(98000001);

        $this->assertSame($directorCharacter, $result);
    }

    public function testGetCorpAssetsCharacterFallsBackWhenNoVisibilityConfig(): void
    {
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);

        $fallbackCharacter = $this->createStub(Character::class);
        $this->characterRepository->method('findWithCorpAssetsAccess')->willReturn($fallbackCharacter);

        $result = $this->service->getCorpAssetsCharacter(98000001);

        $this->assertSame($fallbackCharacter, $result);
    }

    public function testGetCorpAssetsCharacterFallsBackWhenDirectorLacksScope(): void
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(false);

        $directorCharacter = $this->createStub(Character::class);
        $directorCharacter->method('getCorporationId')->willReturn(98000001);
        $directorCharacter->method('getEveToken')->willReturn($token);

        $directorUser = $this->createStub(User::class);
        $directorUser->method('getCharacters')->willReturn(new ArrayCollection([$directorCharacter]));

        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(98000001);
        $visibility->setVisibleDivisions([1]);
        $visibility->setConfiguredBy($directorUser);

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);

        $fallbackCharacter = $this->createStub(Character::class);
        $this->characterRepository->method('findWithCorpAssetsAccess')->willReturn($fallbackCharacter);

        $result = $this->service->getCorpAssetsCharacter(98000001);

        $this->assertSame($fallbackCharacter, $result);
    }

    // ===========================================
    // ESI failure — cached assets are kept (issue #14)
    // ===========================================

    public function testSyncCharacterAssetsKeepsCachedAssetsWhenEsiFetchFails(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $esiFailure = EsiApiException::fromResponse(502, 'Bad Gateway', '/characters/12345/assets/');
        $this->assetsService->method('getCharacterAssets')->willThrowException($esiFailure);

        $syncCalls = $this->recordCacheDeletionsAndWrites();

        $caught = $this->catchThrowable(fn () => $this->service->syncCharacterAssets($character));

        $this->assertSame($esiFailure, $caught);
        $this->assertSame([], $syncCalls->getArrayCopy());
    }

    public function testSyncCorporationAssetsKeepsCachedAssetsWhenEsiFetchFails(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals']);
        $esiFailure = EsiApiException::fromResponse(502, 'Bad Gateway', '/corporations/98000001/assets/');
        $this->assetsService->method('getCorporationAssets')->willThrowException($esiFailure);

        $syncCalls = $this->recordCacheDeletionsAndWrites();

        $caught = $this->catchThrowable(fn () => $this->service->syncCorporationAssets($character));

        $this->assertSame($esiFailure, $caught);
        $this->assertSame([], $syncCalls->getArrayCopy());
    }

    public function testSyncCorporationAssetsFailsAndKeepsCachedAssetsWhenDivisionsFetchFails(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $divisionsFailure = EsiApiException::fromResponse(503, 'Service Unavailable', '/corporations/98000001/divisions/');
        $this->corporationService->method('getDivisions')->willThrowException($divisionsFailure);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2001, locationFlag: 'CorpSAG1'),
        ]);

        $syncCalls = $this->recordCacheDeletionsAndWrites();

        $caught = $this->catchThrowable(fn () => $this->service->syncCorporationAssets($character));

        $this->assertSame($divisionsFailure, $caught);
        $this->assertSame([], $syncCalls->getArrayCopy());
    }

    // ===========================================
    // ESI success — old assets replaced by new ones (guard)
    // ===========================================

    public function testSyncCharacterAssetsReplacesCachedAssetsOnSuccess(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->assetsService->method('getCharacterAssets')->willReturn([
            $this->createAsset(itemId: 1001, locationFlag: 'Hangar'),
        ]);

        $syncCalls = $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);

        $this->assertSame(['deleteByCharacter', 'persist:1001', 'flush'], $syncCalls->getArrayCopy());
    }

    public function testSyncCorporationAssetsReplacesCachedAssetsWithDivisionNamesOnSuccess(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals', 2 => 'Ships']);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2001, locationFlag: 'CorpSAG1'),
            $this->createAsset(itemId: 2002, locationFlag: 'CorpSAG2'),
        ]);

        $syncCalls = $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $this->assertSame(
            ['deleteByCorporationId:98000001', 'persist:2001', 'persist:2002', 'flush'],
            $syncCalls->getArrayCopy(),
        );
        $this->assertSame(['Minerals', 'Ships'], array_map(
            static fn (CachedAsset $cachedAsset): ?string => $cachedAsset->getDivisionName(),
            $this->persistedAssets,
        ));
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * Records, in call order, every cached-asset deletion, persist and flush.
     *
     * @return \ArrayObject<int, string>
     */
    private function recordCacheDeletionsAndWrites(): \ArrayObject
    {
        $syncCalls = new \ArrayObject();

        $this->cachedAssetRepository->method('deleteByCharacter')->willReturnCallback(
            static function () use ($syncCalls): int {
                $syncCalls[] = 'deleteByCharacter';

                return 3;
            },
        );
        $this->cachedAssetRepository->method('deleteByCorporationId')->willReturnCallback(
            static function (int $corporationId) use ($syncCalls): int {
                $syncCalls[] = 'deleteByCorporationId:' . $corporationId;

                return 3;
            },
        );
        $this->em->method('persist')->willReturnCallback(
            function (object $entity) use ($syncCalls): void {
                \assert($entity instanceof CachedAsset);
                $syncCalls[] = 'persist:' . $entity->getItemId();
                $this->persistedAssets[] = $entity;
            },
        );
        $this->em->method('flush')->willReturnCallback(
            static function () use ($syncCalls): void {
                $syncCalls[] = 'flush';
            },
        );

        return $syncCalls;
    }

    private function catchThrowable(callable $sync): ?\Throwable
    {
        try {
            $sync();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function createAsset(int $itemId, string $locationFlag): AssetDto
    {
        return new AssetDto(
            itemId: $itemId,
            typeId: 34,
            typeName: 'Tritanium',
            quantity: 50000,
            locationId: 60003760,
            locationName: 'Jita IV - Moon 4',
            locationType: 'station',
            locationFlag: $locationFlag,
            solarSystemId: 30000142,
            solarSystemName: 'Jita',
            itemName: null,
        );
    }

    private function createCharacterWithUser(int $eveCharacterId): Character
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getUser')->willReturn($user);
        $character->method('getCorporationId')->willReturn(98000001);
        $character->method('getName')->willReturn('TestChar');

        return $character;
    }
}
