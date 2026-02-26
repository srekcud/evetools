<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\User;
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
    // Helpers
    // ===========================================

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
