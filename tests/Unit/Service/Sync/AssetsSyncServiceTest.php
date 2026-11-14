<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Dto\AssetDto;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Service\Assets\AssetSyncChangeRecorder;
use App\Service\ESI\AssetsService;
use App\Service\ESI\CharacterService;
use App\Service\ESI\CorporationRoleReader;
use App\Service\ESI\CorporationService;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AssetsSyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AssetsSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class AssetsSyncServiceTest extends TestCase
{
    private const int MEMBER_ID = 2_112_729_001;
    private const int DIRECTOR_ID = 2_112_729_002;
    private const string ASSETS_SCOPE = 'esi-assets.read_corporation_assets.v1';
    private const string ROLES_SCOPE = 'esi-characters.read_corporation_roles.v1';

    private AssetsService&Stub $assetsService;
    private CorporationService&Stub $corporationService;
    private CachedAssetRepository&MockObject $cachedAssetRepository;
    private CharacterRepository&Stub $characterRepository;
    private AssetSyncChangeRecorder&MockObject $changeRecorder;
    private EntityManagerInterface&MockObject $em;
    private AssetsSyncService $service;

    /** @var list<CachedAsset> */
    private array $persistedAssets = [];

    private array $publishedSyncEvents = [];

    private CorporationRoleReader $corporationRoleReader;

    private array $rolesByEveCharacterId = [];

    private array $rolesFailures = [];

    private array $rolesReadFor = [];

    protected function setUp(): void
    {
        $this->assetsService = $this->createStub(AssetsService::class);
        $this->corporationService = $this->createStub(CorporationService::class);
        $this->cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $this->characterRepository = $this->createStub(CharacterRepository::class);
        $this->changeRecorder = $this->createMock(AssetSyncChangeRecorder::class);
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $eveCharacterId = (int) preg_replace('#\D#', '', $endpoint);
            $this->rolesReadFor[] = $eveCharacterId;
            if (isset($this->rolesFailures[$eveCharacterId])) {
                throw $this->rolesFailures[$eveCharacterId];
            }

            return ['roles' => $this->rolesByEveCharacterId[$eveCharacterId] ?? []];
        });
        $this->corporationRoleReader = new CorporationRoleReader(new CharacterService($esiClient), new ArrayAdapter(), new NullLogger());
        $this->em = $this->createMock(EntityManagerInterface::class);

        $mercureHub = $this->createStub(HubInterface::class);
        $mercureHub->method('publish')->willReturnCallback(
            function (Update $update): string {
                $payload = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
                $this->publishedSyncEvents[] = [
                    $update->getTopics()[0],
                    $payload['status'],
                    $payload['progress'],
                    $payload['message'],
                    $payload['data'],
                ];

                return 'published';
            },
        );

        $mercurePublisher = new MercurePublisherService($mercureHub, new NullLogger());

        $this->service = new AssetsSyncService(
            $this->assetsService,
            $this->corporationService,
            $this->cachedAssetRepository,
            $this->characterRepository,
            $this->corporationRoleReader,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
            $this->changeRecorder,
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

    public function testCanSyncRefusesTheRevokedAltOfAUserWhoseMainIsValid(): void
    {
        $user = new User();
        $mainCharacter = $this->characterOfWithToken($user, 2_112_000_002, 'Main Pilot');
        $user->setMainCharacter($mainCharacter);
        $revokedAlt = $this->characterOfWithToken($user, 2_112_000_001, 'Alt Pilot')->markAuthInvalid();

        $this->assertFalse($this->service->canSync($revokedAlt));
    }

    public function testCanSyncAcceptsTheValidMainOfAUserWhoseAltIsRevoked(): void
    {
        $user = new User();
        $mainCharacter = $this->characterOfWithToken($user, 2_112_000_002, 'Main Pilot');
        $user->setMainCharacter($mainCharacter);
        $this->characterOfWithToken($user, 2_112_000_001, 'Alt Pilot')->markAuthInvalid();

        $this->assertTrue($this->service->canSync($mainCharacter));
    }

    public function testCanSyncReturnsTrueWhenTheCharacterHasATokenAndAValidAuth(): void
    {
        $character = $this->characterOfWithToken(new User(), 2_112_000_003, 'Solo Pilot');

        $this->assertTrue($this->service->canSync($character));
    }

    // ===========================================
    // syncCorporationAssetsForCorp — character lookup
    // ===========================================

    public function testSyncCorporationReturnsFalseWhenNoCharacterWithAccess(): void
    {
        $this->corporationMembersAre([]);

        $result = $this->service->syncCorporationAssetsForCorp(98000001);

        $this->assertFalse($result);
    }

    // ===========================================
    // canSyncCorporationAssets
    // ===========================================

    public function testCanSyncCorporationAssetsReturnsTrueWhenADirectorIsConnected(): void
    {
        $this->corporationMembersAre([$this->member(self::MEMBER_ID, ['Director'])]);

        $this->assertTrue($this->service->canSyncCorporationAssets(98000001));
    }

    public function testCanSyncCorporationAssetsReturnsFalseWhenNoDirectorIsConnected(): void
    {
        $this->corporationMembersAre([$this->member(self::MEMBER_ID, ['Accountant'])]);

        $this->assertFalse($this->service->canSyncCorporationAssets(98000001));
    }

    // ===========================================
    // getCorpAssetsCharacter — in-game Director role (#729)
    // ===========================================

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterSkipsTheMembersWithoutTheDirectorRoleEvenWhenTriedFirst(): void
    {
        $plainMember = $this->member(self::MEMBER_ID, []);
        $director = $this->member(self::DIRECTOR_ID, ['Director']);
        $this->corporationMembersAre([$plainMember, $director]);

        $this->assertSame($director, $this->service->getCorpAssetsCharacter(98000001));
        $this->assertSame([self::MEMBER_ID, self::DIRECTOR_ID], $this->rolesReadFor);
    }

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterReturnsNullWhenNoMemberHoldsTheDirectorRole(): void
    {
        $this->corporationMembersAre([
            $this->member(self::MEMBER_ID, ['Accountant']),
            $this->member(self::DIRECTOR_ID, ['Station_Manager']),
        ]);

        $this->assertNull($this->service->getCorpAssetsCharacter(98000001));
    }

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterIgnoresADirectorWhoseAuthIsInvalid(): void
    {
        $director = $this->member(self::DIRECTOR_ID, ['Director']);
        $director->markAuthInvalid();
        $this->corporationMembersAre([$director]);

        $this->assertNull($this->service->getCorpAssetsCharacter(98000001));
    }

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterIgnoresADirectorWithoutTheRolesScope(): void
    {
        $this->corporationMembersAre([$this->member(self::DIRECTOR_ID, ['Director'], [self::ASSETS_SCOPE])]);

        $this->assertNull($this->service->getCorpAssetsCharacter(98000001));
    }

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterIgnoresADirectorWithoutTheCorporationAssetsScope(): void
    {
        $this->corporationMembersAre([$this->member(self::DIRECTOR_ID, ['Director'], [self::ROLES_SCOPE])]);

        $this->assertNull($this->service->getCorpAssetsCharacter(98000001));
    }

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterTriesTheNextMemberWhenTheRolesReadOfTheFirstIsForbidden(): void
    {
        $this->corporationMembersAre([
            $this->member(self::MEMBER_ID, ['Director']),
            $director = $this->member(self::DIRECTOR_ID, ['Director']),
        ]);
        $this->rolesFailures[self::MEMBER_ID] = EsiApiException::forbidden();

        $this->assertSame($director, $this->service->getCorpAssetsCharacter(98000001));
    }

    #[Group('issue-729')]
    public function testGetCorpAssetsCharacterReadsTheRolesOnceForTwoLookups(): void
    {
        $director = $this->member(self::DIRECTOR_ID, ['Director']);
        $this->corporationMembersAre([$director]);

        $first = $this->service->getCorpAssetsCharacter(98000001);
        $second = $this->service->getCorpAssetsCharacter(98000001);

        $this->assertSame([$director, $director, [self::DIRECTOR_ID]], [$first, $second, $this->rolesReadFor]);
    }

    #[Group('issue-729')]
    public function testAForbiddenCorporationAssetsReadForgetsTheRememberedDirector(): void
    {
        $director = $this->member(self::DIRECTOR_ID, ['Director']);
        $this->corporationMembersAre([$director]);
        $this->corporationService->method('getDivisions')->willThrowException(EsiApiException::forbidden());
        $this->service->getCorpAssetsCharacter(98000001);

        $this->assertFalse($this->service->syncCorporationAssetsForCorp(98000001));
        $this->assertSame($director, $this->service->getCorpAssetsCharacter(98000001));

        $this->assertSame([self::DIRECTOR_ID, self::DIRECTOR_ID], $this->rolesReadFor);
    }

    #[Group('issue-728')]
    public function testAForbiddenCorporationAssetsReadTellsTheUsersThatADirectorMustBeConnected(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willThrowException(EsiApiException::forbidden());

        $caught = $this->catchThrowable(fn () => $this->service->syncCorporationAssets($character));

        $this->assertInstanceOf(EsiApiException::class, $caught);
        $errors = array_values(array_filter($this->publishedSyncEvents, static fn (array $event): bool => $event[1] === 'error'));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Director', $errors[0][3]);
        $this->assertStringNotContainsString('Access forbidden', $errors[0][3]);
    }

    #[Group('issue-728')]
    public function testANonForbiddenCorporationAssetsFailureKeepsItsOwnMessageOnTheErrorEvent(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willThrowException(EsiApiException::fromResponse(502, 'Bad Gateway'));

        $this->catchThrowable(fn () => $this->service->syncCorporationAssets($character));

        $errors = array_values(array_filter($this->publishedSyncEvents, static fn (array $event): bool => $event[1] === 'error'));
        $this->assertSame(['Bad Gateway'], array_column($errors, 3));
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

    #[Group('issue-888')]
    public function testSyncCharacterAssetsRecordsTheChangesBetweenTheCachedAndTheFreshAssets(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $before = [(new CachedAsset())->setItemId(1)->setTypeId(34)->setQuantity(5)];
        $this->cachedAssetRepository->method('findByCharacter')->with($character)->willReturn($before);
        $this->assetsService->method('getCharacterAssets')->willReturn([
            $this->createAsset(itemId: 1001, locationFlag: 'Hangar'),
        ]);

        $this->changeRecorder->expects($this->once())->method('recordForCharacter')->with(
            $character,
            $before,
            $this->callback(fn (array $after): bool => count($after) === 1 && $after[0] === $this->persistedAssets[0]),
            $this->isInstanceOf(\DateTimeImmutable::class),
        );
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);
    }

    #[Group('issue-888')]
    public function testSyncCorporationAssetsRecordsTheChangesBetweenTheCachedAndTheFreshAssets(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $before = [(new CachedAsset())->setItemId(1)->setTypeId(34)->setQuantity(5)];
        $this->cachedAssetRepository->method('findByCorporationId')->with(98000001)->willReturn($before);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals']);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2001, locationFlag: 'CorpSAG1'),
        ]);

        $this->changeRecorder->expects($this->once())->method('recordForCorporation')->with(
            98000001,
            $before,
            $this->callback(fn (array $after): bool => count($after) === 1 && $after[0]->getDivisionName() === 'Minerals'),
            $this->isInstanceOf(\DateTimeImmutable::class),
        );
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);
    }

    #[Group('issue-888')]
    public function testAnEsiFailureRecordsNoChange(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->assetsService->method('getCharacterAssets')->willThrowException(new \RuntimeException('ESI down'));

        $this->changeRecorder->expects($this->never())->method('recordForCharacter');

        $this->catchThrowable(fn () => $this->service->syncCharacterAssets($character));
    }

    // ===========================================
    // Container contents — division of the root container (issue #54)
    // ===========================================

    public function testSyncCorporationAssetsGivesContainerContentsTheDivisionNameOfTheirRootContainer(): void
    {
        $officeItemId = 1_040_000_000_001;
        $rootContainerItemId = 1_040_000_000_201;
        $nestedContainerItemId = 1_040_000_000_202;
        $tritaniumInRootContainerItemId = 1_040_000_000_211;
        $pyeriteInNestedContainerItemId = 1_040_000_000_221;

        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals', 2 => 'Production Stock']);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAssetInLocation($pyeriteInNestedContainerItemId, $nestedContainerItemId, 'item', 'Unlocked'),
            $this->createAssetInLocation($tritaniumInRootContainerItemId, $rootContainerItemId, 'item', 'Unlocked'),
            $this->createAssetInLocation($nestedContainerItemId, $rootContainerItemId, 'item', 'Locked'),
            $this->createAssetInLocation($rootContainerItemId, $officeItemId, 'item', 'CorpSAG2'),
        ]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $this->assertSame(
            [
                $pyeriteInNestedContainerItemId => 'Production Stock',
                $tritaniumInRootContainerItemId => 'Production Stock',
                $nestedContainerItemId => 'Production Stock',
                $rootContainerItemId => 'Production Stock',
            ],
            $this->divisionNamesByItemId(),
        );
    }

    #[Group('issue-115')]
    public function testSyncCharacterAssetsCachesAnAssetWithAnUnknownTypeNameWithoutName(): void
    {
        $character = new Character();
        $this->assetsService->method('getCharacterAssets')->willReturn([
            new AssetDto(
                itemId: 1_050_000_000_501,
                typeId: 9848,
                typeName: null,
                quantity: 4321,
                locationId: 60003760,
                locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                locationType: 'station',
                locationFlag: 'Hangar',
                solarSystemId: 30000142,
                solarSystemName: 'Jita',
            ),
        ]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);

        self::assertCount(1, $this->persistedAssets);
        self::assertNull($this->persistedAssets[0]->getTypeName());
        self::assertSame(9848, $this->persistedAssets[0]->getTypeId());
        self::assertSame(4321, $this->persistedAssets[0]->getQuantity());
    }

    public function testSyncCharacterAssetsCachesEveryEsiFieldAsAPersonalAsset(): void
    {
        $character = new Character();
        $this->assetsService->method('getCharacterAssets')->willReturn([$this->isogenCanInJita()]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);

        $this->assertSame([$this->isogenCanInJitaFields()], array_map($this->cachedAssetFields(...), $this->persistedAssets));
        $this->assertSame($character, $this->persistedAssets[0]->getCharacter());
        $this->assertFalse($this->persistedAssets[0]->isCorporationAsset());
        $this->assertNull($this->persistedAssets[0]->getCorporationId());
        $this->assertNotNull($character->getLastSyncAt());
        $this->assertSame([], $this->publishedSyncEvents);
    }

    public function testSyncCorporationAssetsCachesEveryEsiFieldAsACorporationAsset(): void
    {
        $character = new Character();
        $character->setCorporationId(98000777);
        $this->corporationService->method('getDivisions')->willReturn([3 => 'Moon Goo']);
        $this->assetsService->method('getCorporationAssets')->willReturn([$this->isogenCanInJita()]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $this->assertSame([$this->isogenCanInJitaFields()], array_map($this->cachedAssetFields(...), $this->persistedAssets));
        $this->assertNull($this->persistedAssets[0]->getCharacter());
        $this->assertTrue($this->persistedAssets[0]->isCorporationAsset());
        $this->assertSame(98000777, $this->persistedAssets[0]->getCorporationId());
        $this->assertSame('Moon Goo', $this->persistedAssets[0]->getDivisionName());
        $this->assertSame([], $this->publishedSyncEvents);
    }

    #[Group('issue-784')]
    public function testSyncCharacterAssetsPersistsTheSingletonFlagOfEveryAsset(): void
    {
        $this->assetsService->method('getCharacterAssets')->willReturn($this->singletonFlagAssets());
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets(new Character());

        $this->assertSame([true, false], array_map(static fn (CachedAsset $asset): ?bool => $asset->isSingleton(), $this->persistedAssets));
    }

    #[Group('issue-784')]
    public function testSyncCorporationAssetsPersistsTheSingletonFlagOfEveryAsset(): void
    {
        $character = new Character();
        $character->setCorporationId(98000784);
        $this->corporationService->method('getDivisions')->willReturn([]);
        $this->assetsService->method('getCorporationAssets')->willReturn($this->singletonFlagAssets());
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $this->assertSame([true, false], array_map(static fn (CachedAsset $asset): ?bool => $asset->isSingleton(), $this->persistedAssets));
    }

    public function testSyncOfACharacterWhoseUserHasNoIdPublishesNoSyncEvent(): void
    {
        $userWithoutId = $this->createStub(User::class);
        $userWithoutId->method('getId')->willReturn(null);
        $character = new Character();
        $character->setUser($userWithoutId);
        $character->setCorporationId(98000777);
        $this->assetsService->method('getCharacterAssets')->willReturn([$this->isogenCanInJita()]);
        $this->assetsService->method('getCorporationAssets')->willReturn([$this->isogenCanInJita()]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);
        $this->service->syncCorporationAssets($character);

        $this->assertCount(2, $this->persistedAssets);
        $this->assertSame([], $this->publishedSyncEvents);
    }

    public function testSyncCharacterAssetsPublishesStartProgressAndCompletionToTheUser(): void
    {
        $userId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b');
        $character = $this->createCharacterOfUser($userId);
        $this->assetsService->method('getCharacterAssets')->willReturn([
            $this->createAsset(itemId: 1001, locationFlag: 'Hangar'),
            $this->createAsset(itemId: 1002, locationFlag: 'Hangar'),
            $this->createAsset(itemId: 1003, locationFlag: 'Cargo'),
        ]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);

        $topic = '/user/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b/sync/character-assets';
        $this->assertSame([
            [$topic, 'started', 0, 'Fetching character assets...', null],
            [$topic, 'in_progress', 50, 'Processing 3 assets...', null],
            [$topic, 'completed', 100, 'Sync completed', ['count' => 3]],
        ], $this->publishedSyncEvents);
    }

    public function testSyncCorporationAssetsPublishesStartProgressAndCompletionToTheUser(): void
    {
        $userId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c');
        $character = $this->createCharacterOfUser($userId);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2001, locationFlag: 'CorpSAG1'),
            $this->createAsset(itemId: 2002, locationFlag: 'CorpSAG2'),
        ]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $topic = '/user/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c/sync/corporation-assets';
        $this->assertSame([
            [$topic, 'started', 0, 'Fetching corporation assets...', null],
            [$topic, 'in_progress', 50, 'Processing 2 assets...', null],
            [$topic, 'completed', 100, 'Sync completed', ['count' => 2]],
        ], $this->publishedSyncEvents);
    }

    #[Group('issue-670')]
    public function testCharacterAssetsSyncReportsTheRejectedItemNameBatchesInItsCompletion(): void
    {
        $userId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6d');
        $character = $this->createCharacterOfUser($userId);
        $this->assetsService->method('getCharacterAssets')->willReturn([
            $this->createAsset(itemId: 1001, locationFlag: 'Hangar'),
        ]);
        $this->assetsService->method('lastNameBatchFailures')->willReturn(2);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCharacterAssets($character);

        $topic = '/user/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6d/sync/character-assets';
        $this->assertSame(
            [$topic, 'completed', 100, 'Sync completed, item name batches rejected by ESI: 2', ['count' => 1, 'nameBatchFailures' => 2]],
            $this->publishedSyncEvents[2],
        );
    }

    #[Group('issue-670')]
    public function testCorporationAssetsSyncReportsTheRejectedItemNameBatchesInItsCompletion(): void
    {
        $userId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6e');
        $character = $this->createCharacterOfUser($userId);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2001, locationFlag: 'CorpSAG1'),
        ]);
        $this->assetsService->method('lastNameBatchFailures')->willReturn(1);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $topic = '/user/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6e/sync/corporation-assets';
        $this->assertSame(
            [$topic, 'completed', 100, 'Sync completed, item name batches rejected by ESI: 1', ['count' => 1, 'nameBatchFailures' => 1]],
            $this->publishedSyncEvents[2],
        );
    }

    public function testSyncCorporationAssetsPublishesStartProgressAndCompletionToTheRequestingUserAndTheSyncCharacterOwner(): void
    {
        $ownerId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a71');
        $requesterId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a72';
        $character = $this->createCharacterOfUser($ownerId);
        $this->assetsService->method('getCorporationAssets')->willReturn([$this->createAsset(itemId: 2301, locationFlag: 'CorpSAG1')]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character, $requesterId);

        $ownerTopic = '/user/' . $ownerId->toRfc4122() . '/sync/corporation-assets';
        $requesterTopic = '/user/' . $requesterId . '/sync/corporation-assets';
        $this->assertSame([
            [$requesterTopic, 'started', 0, 'Fetching corporation assets...', null],
            [$requesterTopic, 'in_progress', 50, 'Processing 1 assets...', null],
            [$requesterTopic, 'completed', 100, 'Sync completed', ['count' => 1]],
        ], $this->eventsOnTopic($requesterTopic));
        $this->assertCount(3, $this->eventsOnTopic($ownerTopic));
    }

    public function testFailedCorporationAssetsSyncPublishesTheErrorToTheRequestingUser(): void
    {
        $ownerId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a73');
        $requesterId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a74';
        $character = $this->createCharacterOfUser($ownerId);
        $this->assetsService->method('getCorporationAssets')->willThrowException(EsiApiException::fromResponse(503, 'Service Unavailable'));

        $this->catchThrowable(fn () => $this->service->syncCorporationAssets($character, $requesterId));

        $requesterTopic = '/user/' . $requesterId . '/sync/corporation-assets';
        $this->assertSame([
            [$requesterTopic, 'started', 0, 'Fetching corporation assets...', null],
            [$requesterTopic, 'error', null, 'Service Unavailable', null],
        ], $this->eventsOnTopic($requesterTopic));
    }

    public function testSyncCorporationAssetsPublishesOnceWhenTheRequestingUserOwnsTheSyncCharacter(): void
    {
        $userId = Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a75');
        $character = $this->createCharacterOfUser($userId);
        $this->assetsService->method('getCorporationAssets')->willReturn([$this->createAsset(itemId: 2302, locationFlag: 'CorpSAG1')]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character, $userId->toRfc4122());

        $this->assertCount(3, $this->publishedSyncEvents);
    }

    public function testFailedCharacterAssetsSyncPublishesTheErrorToTheUser(): void
    {
        $character = $this->createCharacterOfUser(Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6d'));
        $this->assetsService->method('getCharacterAssets')->willThrowException(EsiApiException::fromResponse(502, 'Bad Gateway'));

        $this->catchThrowable(fn () => $this->service->syncCharacterAssets($character));

        $topic = '/user/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6d/sync/character-assets';
        $this->assertSame([
            [$topic, 'started', 0, 'Fetching character assets...', null],
            [$topic, 'error', null, 'Bad Gateway', null],
        ], $this->publishedSyncEvents);
    }

    public function testFailedCorporationAssetsSyncPublishesTheErrorToTheUser(): void
    {
        $character = $this->createCharacterOfUser(Uuid::fromString('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6e'));
        $this->assetsService->method('getCorporationAssets')->willThrowException(EsiApiException::fromResponse(503, 'Service Unavailable'));

        $this->catchThrowable(fn () => $this->service->syncCorporationAssets($character));

        $topic = '/user/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6e/sync/corporation-assets';
        $this->assertSame([
            [$topic, 'started', 0, 'Fetching corporation assets...', null],
            [$topic, 'error', null, 'Service Unavailable', null],
        ], $this->publishedSyncEvents);
    }

    #[DataProvider('locationFlagsWithoutDivision')]
    public function testCorporationAssetOutsideAnyCorporationHangarHasNoDivisionName(string $locationFlag): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals', 2 => 'Ships']);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2101, locationFlag: $locationFlag),
        ]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $this->assertSame([2101 => null], $this->divisionNamesByItemId());
    }

    public static function locationFlagsWithoutDivision(): iterable
    {
        yield 'deliveries hangar' => ['CorpDeliveries'];
        yield 'flag with a prefix before the hangar' => ['XCorpSAG1'];
        yield 'flag with a suffix after the hangar' => ['CorpSAG2Old'];
        yield 'hangar of a division the corporation does not name' => ['CorpSAG7'];
    }

    public function testCorporationAssetsInAContainerLoopWithoutHangarHaveNoDivisionName(): void
    {
        $firstContainerItemId = 1_040_000_000_301;
        $secondContainerItemId = 1_040_000_000_302;
        $character = $this->createCharacterWithUser(12345);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals']);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAssetInLocation($firstContainerItemId, $secondContainerItemId, 'item', 'Unlocked'),
            $this->createAssetInLocation($secondContainerItemId, $firstContainerItemId, 'item', 'Locked'),
        ]);
        $this->recordCacheDeletionsAndWrites();

        $this->service->syncCorporationAssets($character);

        $this->assertSame([$firstContainerItemId => null, $secondContainerItemId => null], $this->divisionNamesByItemId());
    }

    public function testSyncCorporationAssetsForCorpReportsSuccessWithTheCharacterHavingAccess(): void
    {
        $character = $this->member(self::DIRECTOR_ID, ['Director']);
        $this->corporationMembersAre([$character]);
        $this->corporationService->method('getDivisions')->willReturn([1 => 'Minerals']);
        $this->assetsService->method('getCorporationAssets')->willReturn([
            $this->createAsset(itemId: 2201, locationFlag: 'CorpSAG1'),
        ]);
        $syncCalls = $this->recordCacheDeletionsAndWrites();

        $this->assertTrue($this->service->syncCorporationAssetsForCorp(98000001));
        $this->assertSame(['deleteByCorporationId:98000001', 'persist:2201', 'flush'], $syncCalls->getArrayCopy());
    }

    public function testSyncCorporationAssetsForCorpReportsFailureWhenEsiFails(): void
    {
        $character = $this->member(self::DIRECTOR_ID, ['Director']);
        $this->corporationMembersAre([$character]);
        $this->assetsService->method('getCorporationAssets')->willThrowException(EsiApiException::fromResponse(502, 'Bad Gateway'));

        $this->assertFalse($this->service->syncCorporationAssetsForCorp(98000001));
    }

    public function testCanSyncReturnsFalseWhenNoTokenEvenWithAValidCharacterAuth(): void
    {
        $character = (new Character())->setEveCharacterId(2_112_000_004)->setName('Tokenless Pilot');
        (new User())->addCharacter($character);

        $this->assertTrue($character->isAuthValid());
        $this->assertFalse($this->service->canSync($character));
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @return array<int, ?string>
     */
    private function divisionNamesByItemId(): array
    {
        $divisionNames = [];
        foreach ($this->persistedAssets as $cachedAsset) {
            $divisionNames[$cachedAsset->getItemId()] = $cachedAsset->getDivisionName();
        }

        return $divisionNames;
    }

    private function createAssetInLocation(int $itemId, int $locationId, string $locationType, string $locationFlag): AssetDto
    {
        return new AssetDto(
            itemId: $itemId,
            typeId: 34,
            typeName: 'Tritanium',
            quantity: 1,
            locationId: $locationId,
            locationName: 'Jita IV - Moon 4',
            locationType: $locationType,
            locationFlag: $locationFlag,
            solarSystemId: 30000142,
            solarSystemName: 'Jita',
            itemName: null,
        );
    }

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

    /** @return list<AssetDto> */
    private function singletonFlagAssets(): array
    {
        return [
            new AssetDto(
                itemId: 1_050_000_784_001,
                typeId: 587,
                typeName: 'Rifter',
                quantity: 1,
                locationId: 60003760,
                locationName: 'Jita IV - Moon 4',
                locationType: 'station',
                locationFlag: 'Hangar',
                itemName: null,
                isSingleton: true,
            ),
            new AssetDto(
                itemId: 1_050_000_784_002,
                typeId: 34,
                typeName: 'Tritanium',
                quantity: 500,
                locationId: 60003760,
                locationName: 'Jita IV - Moon 4',
                locationType: 'station',
                locationFlag: 'Hangar',
                itemName: null,
                isSingleton: false,
            ),
        ];
    }

    private function isogenCanInJita(): AssetDto
    {
        return new AssetDto(
            itemId: 1_050_000_000_401,
            typeId: 37,
            typeName: 'Isogen',
            quantity: 12750,
            locationId: 1_050_000_000_400,
            locationName: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            locationType: 'item',
            locationFlag: 'CorpSAG3',
            solarSystemId: 30000142,
            solarSystemName: 'Jita',
            itemName: 'Isogen stock',
        );
    }

    private function isogenCanInJitaFields(): array
    {
        return [
            'itemId' => 1_050_000_000_401,
            'typeId' => 37,
            'typeName' => 'Isogen',
            'quantity' => 12750,
            'locationId' => 1_050_000_000_400,
            'locationName' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'locationType' => 'item',
            'locationFlag' => 'CorpSAG3',
            'solarSystemId' => 30000142,
            'solarSystemName' => 'Jita',
            'itemName' => 'Isogen stock',
        ];
    }

    private function cachedAssetFields(CachedAsset $cachedAsset): array
    {
        return [
            'itemId' => $cachedAsset->getItemId(),
            'typeId' => $cachedAsset->getTypeId(),
            'typeName' => $cachedAsset->getTypeName(),
            'quantity' => $cachedAsset->getQuantity(),
            'locationId' => $cachedAsset->getLocationId(),
            'locationName' => $cachedAsset->getLocationName(),
            'locationType' => $cachedAsset->getLocationType(),
            'locationFlag' => $cachedAsset->getLocationFlag(),
            'solarSystemId' => $cachedAsset->getSolarSystemId(),
            'solarSystemName' => $cachedAsset->getSolarSystemName(),
            'itemName' => $cachedAsset->getItemName(),
        ];
    }

    /** @return list<array<int, mixed>> */
    private function eventsOnTopic(string $topic): array
    {
        return array_values(array_filter(
            $this->publishedSyncEvents,
            static fn (array $event): bool => $event[0] === $topic,
        ));
    }

    private function createCharacterOfUser(Uuid $userId): Character
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($userId);

        $character = $this->createStub(Character::class);
        $character->method('getUser')->willReturn($user);
        $character->method('getCorporationId')->willReturn(98000001);

        return $character;
    }

    private function corporationMembersAre(array $members): void
    {
        $this->characterRepository->method('findByCorporationId')->willReturn($members);
    }

    private function member(int $eveCharacterId, array $roles, array $scopes = [self::ASSETS_SCOPE, self::ROLES_SCOPE]): Character
    {
        $user = new User();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Member ' . $eveCharacterId)
            ->setCorporationId(98000001);
        $character->setEveToken((new EveToken())
            ->setAccessToken('access-token-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes($scopes));
        $user->addCharacter($character);
        $this->rolesByEveCharacterId[$eveCharacterId] = $roles;

        return $character;
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

    private function characterOfWithToken(User $user, int $eveCharacterId, string $name): Character
    {
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
        $character->setEveToken((new EveToken())
            ->setAccessToken('access-token-' . $eveCharacterId)
            ->setRefreshTokenEncrypted('refresh-token-' . $eveCharacterId)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes(['esi-assets.read_assets.v1']));
        $user->addCharacter($character);

        return $character;
    }
}
