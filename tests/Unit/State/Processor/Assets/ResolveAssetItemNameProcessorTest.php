<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Assets;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Assets\AssetItemNameResource;
use App\ApiResource\Input\EmptyInput;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\Sync\AssetsSyncService;
use App\Service\TypeNameResolver;
use App\State\Processor\Assets\ResolveAssetItemNameProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ResolveAssetItemNameProcessor::class)]
#[Group('issue-784')]
final class ResolveAssetItemNameProcessorTest extends TestCase
{
    private const string ASSET_ID = '0199a1f0-0000-7000-8000-000000784001';
    private const string UNKNOWN_ASSET_ID = '0199a1f0-0000-7000-8000-000000784999';
    private const string MALFORMED_ASSET_ID = 'not-an-asset-uuid';

    private const int CORPORATION_ID = 98_784_001;
    private const int OTHER_CORPORATION_ID = 98_784_002;
    private const int REQUESTER_EVE_CHARACTER_ID = 2_113_784_001;
    private const int OTHER_PILOT_EVE_CHARACTER_ID = 2_113_784_002;
    private const int DIRECTOR_EVE_CHARACTER_ID = 2_113_784_003;

    private const int RIFTER_TYPE_ID = 587;
    private const int STATION_CONTAINER_TYPE_ID = 17_366;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int OFFICE_TYPE_ID = 27;

    private const int RIFTER_ITEM_ID = 1_050_000_784_001;
    private const int STATION_CONTAINER_ITEM_ID = 1_050_000_784_002;
    private const int TRITANIUM_ITEM_ID = 1_050_000_784_003;
    private const int OFFICE_ITEM_ID = 1_050_000_784_004;

    private const string ESI_REJECTION_MESSAGE = 'Requested item is not a nameable asset';

    private Security&Stub $security;
    private CachedAssetRepository&Stub $cachedAssetRepository;
    private ResolveAssetItemNameProcessor $processor;

    private ?User $requester;
    private Character $requesterCharacter;
    private EveToken $requesterToken;
    private ?CachedAsset $storedAsset = null;
    private int $flushCount = 0;
    private ?Character $connectedDirector = null;

    /** @var list<CachedAsset> */
    private array $visibleCorporationAssets = [];

    /** @var list<array{int, ?string, ?User}> */
    private array $visibilityLookups = [];

    /** @var list<array{endpoint: string, body: array<int|string, mixed>, token: ?EveToken}> */
    private array $namesRequests = [];

    /** @var list<array<string, mixed>>|\Throwable */
    private array|\Throwable $namesAnswer = [];

    protected function setUp(): void
    {
        $this->namesRequests = [];
        $this->namesAnswer = [];
        $this->storedAsset = null;
        $this->flushCount = 0;
        $this->connectedDirector = null;
        $this->visibleCorporationAssets = [];
        $this->visibilityLookups = [];

        $this->requesterToken = new EveToken();
        $this->requester = $this->pilotUser(self::REQUESTER_EVE_CHARACTER_ID, self::CORPORATION_ID, $this->requesterToken);
        $this->requesterCharacter = $this->requester->getMainCharacter();

        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturnCallback(fn (): ?User => $this->requester);

        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->cachedAssetRepository->method('find')->willReturnCallback(
            fn (mixed $id): ?CachedAsset => $this->storedAsset !== null && (string) $id === self::ASSET_ID ? $this->storedAsset : null,
        );
        $this->cachedAssetRepository->method('save')->willReturnCallback(
            function (CachedAsset $asset, bool $flush = false): void {
                if ($flush) {
                    ++$this->flushCount;
                }
            },
        );

        $visibleAssetsFinder = $this->createStub(VisibleCorporationAssetsFinder::class);
        $visibleAssetsFinder->method('findVisibleAssets')->willReturnCallback(
            function (int $corporationId, ?string $divisionName = null, ?User $viewer = null): array {
                $this->visibilityLookups[] = [$corporationId, $divisionName, $viewer];

                return $this->visibleCorporationAssets;
            },
        );

        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('getCorpAssetsCharacter')->willReturnCallback(
            fn (int $corporationId): ?Character => $corporationId === self::CORPORATION_ID ? $this->connectedDirector : null,
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        $this->processor = new ResolveAssetItemNameProcessor(
            $this->security,
            $this->cachedAssetRepository,
            $visibleAssetsFinder,
            $assetsSyncService,
            $this->createAssetsService(),
            $entityManager,
        );
    }

    public function testNamedPersonalShipStoresTheNameAndPostsOnlyItsItemIdWithTheTokenOfItsCharacter(): void
    {
        $ship = $this->personalAsset(self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true, null);
        $this->namesAnswer = [['item_id' => self::RIFTER_ITEM_ID, 'name' => 'Hauler One']];

        $result = $this->resolve();

        self::assertSame(['named', 'Hauler One', self::ASSET_ID], [$result->status, $result->itemName, $result->id]);
        self::assertSame('Hauler One', $ship->getItemName());
        self::assertSame(1, $this->flushCount);
        self::assertSame(
            [[
                'endpoint' => '/characters/' . self::REQUESTER_EVE_CHARACTER_ID . '/assets/names/',
                'body' => [self::RIFTER_ITEM_ID],
                'token' => $this->requesterToken,
            ]],
            $this->namesRequests,
        );
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function unnamedEsiAnswers(): iterable
    {
        yield 'name None' => [[['item_id' => self::STATION_CONTAINER_ITEM_ID, 'name' => 'None']]];
        yield 'empty name' => [[['item_id' => self::STATION_CONTAINER_ITEM_ID, 'name' => '']]];
        yield 'no entry' => [[]];
    }

    /**
     * @param list<array<string, mixed>> $esiAnswer
     */
    #[DataProvider('unnamedEsiAnswers')]
    public function testEsiAnswerWithoutACustomNameReportsUnnamedAndStoresNothing(array $esiAnswer): void
    {
        $container = $this->personalAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, true, null);
        $this->namesAnswer = $esiAnswer;

        $result = $this->resolve();

        self::assertSame('unnamed', $result->status);
        self::assertNull($result->itemName);
        self::assertNull($container->getItemName());
        self::assertSame(0, $this->flushCount);
    }

    public function testEsiFailureAnswers502WithTheEsiMessageAndStoresNothing(): void
    {
        $container = $this->personalAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, true, null);
        $this->namesAnswer = new EsiApiException(self::ESI_REJECTION_MESSAGE, 404);

        $failure = $this->failureOf();

        self::assertSame(502, $failure->getStatusCode());
        self::assertStringContainsString(self::ESI_REJECTION_MESSAGE, $failure->getMessage());
        self::assertNull($container->getItemName());
        self::assertSame(0, $this->flushCount);
    }

    public function testCorporationContainerIsResolvedWithTheTokenOfTheDirectorAndTheCorporationNamesEndpoint(): void
    {
        $directorToken = new EveToken();
        $this->connectedDirector = $this->pilotUser(self::DIRECTOR_EVE_CHARACTER_ID, self::CORPORATION_ID, $directorToken)->getMainCharacter();
        $container = $this->corporationAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', true, null, self::CORPORATION_ID);
        $this->visibleCorporationAssets = [$container];
        $this->namesAnswer = [['item_id' => self::STATION_CONTAINER_ITEM_ID, 'name' => 'Corp Ammo Box']];

        $result = $this->resolve();

        self::assertSame(['named', 'Corp Ammo Box'], [$result->status, $result->itemName]);
        self::assertSame('Corp Ammo Box', $container->getItemName());
        self::assertSame(
            [[
                'endpoint' => '/corporations/' . self::CORPORATION_ID . '/assets/names/',
                'body' => [self::STATION_CONTAINER_ITEM_ID],
                'token' => $directorToken,
            ]],
            $this->namesRequests,
        );
        self::assertNotSame($this->requesterToken, $this->namesRequests[0]['token']);
    }

    public function testCorporationAssetWithoutAConnectedDirectorAnswers409AndCallsNoEsi(): void
    {
        $container = $this->corporationAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', true, null, self::CORPORATION_ID);
        $this->visibleCorporationAssets = [$container];

        $failure = $this->failureOf();

        self::assertSame(409, $failure->getStatusCode());
        self::assertStringContainsStringIgnoringCase('director', $failure->getMessage());
        self::assertSame([], $this->namesRequests);
        self::assertNull($container->getItemName());
    }

    public function testPersonalAssetOfACharacterWithoutTokenAnswers409AndCallsNoEsi(): void
    {
        $this->requesterCharacter->setEveToken(null);
        $ship = $this->personalAsset(self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true, null);

        $failure = $this->failureOf();

        self::assertSame(409, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
        self::assertNull($ship->getItemName());
    }

    public function testAssetOfAnotherUsersCharacterAnswers404EvenWhenItsFlagsMakeItIneligible(): void
    {
        $otherPilot = $this->pilotUser(self::OTHER_PILOT_EVE_CHARACTER_ID, self::CORPORATION_ID, new EveToken())->getMainCharacter();
        $stack = $this->assetOf($otherPilot, self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, false, null);

        $failure = $this->failureOf();

        self::assertSame(404, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
        self::assertNull($stack->getItemName());
    }

    public function testCorporationAssetOfAnotherCorporationAnswers404EvenWhenTheFinderListsIt(): void
    {
        $foreignContainer = $this->corporationAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', true, null, self::OTHER_CORPORATION_ID);
        $this->visibleCorporationAssets = [$foreignContainer];

        $failure = $this->failureOf();

        self::assertSame(404, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
        self::assertNull($foreignContainer->getItemName());
    }

    public function testCorporationAssetInAHiddenDivisionAnswers404(): void
    {
        $hiddenContainer = $this->corporationAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', true, null, self::CORPORATION_ID);

        $failure = $this->failureOf();

        self::assertSame(404, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
        self::assertNull($hiddenContainer->getItemName());
    }

    public function testCorporationAssetVisibilityIsCheckedForTheRequestingUserInTheirCorporation(): void
    {
        $container = $this->corporationAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', true, null, self::CORPORATION_ID);
        $this->visibleCorporationAssets = [$container];

        $this->failureOf();

        self::assertSame([[self::CORPORATION_ID, null, $this->requester]], $this->visibilityLookups);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableAssetIds(): iterable
    {
        yield 'malformed uuid' => [self::MALFORMED_ASSET_ID];
        yield 'unknown uuid' => [self::UNKNOWN_ASSET_ID];
    }

    #[DataProvider('unreachableAssetIds')]
    public function testMalformedOrUnknownAssetIdAnswers404AndCallsNoEsi(string $assetId): void
    {
        $this->personalAsset(self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true, null);

        $failure = $this->failureOf($assetId);

        self::assertSame(404, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
    }

    public function testCorporationAssetWithoutMainCharacterAnswers403(): void
    {
        $this->corporationAsset(self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', true, null, self::CORPORATION_ID);
        $this->requester = new User();

        $failure = $this->failureOf();

        self::assertSame(403, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
    }

    public function testAnonymousRequesterAnswers401(): void
    {
        $this->personalAsset(self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, true, null);
        $this->requester = null;

        $failure = $this->failureOf();

        self::assertSame(401, $failure->getStatusCode());
        self::assertSame([], $this->namesRequests);
    }

    /**
     * @return iterable<string, array{int, int, string, int, ?bool, ?string, string, string}>
     */
    public static function ineligibleAssets(): iterable
    {
        yield 'stack of Tritanium' => [self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 'Tritanium', 500, false, null, 'Hangar', 'not a singleton'];
        yield 'singleton flag unknown' => [self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 'Rifter', 1, null, null, 'Hangar', 'singleton flag unknown'];
        yield 'already named' => [self::STATION_CONTAINER_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 'Station Container', 1, true, 'Ammo Box', 'Hangar', 'already named'];
        yield 'office' => [self::OFFICE_ITEM_ID, self::OFFICE_TYPE_ID, 'Office', 1, true, null, 'OfficeFolder', 'office'];
    }

    #[DataProvider('ineligibleAssets')]
    public function testIneligibleAssetAnswers422NamingTheReasonAndCallsNoEsi(
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        ?bool $isSingleton,
        ?string $itemName,
        string $locationFlag,
        string $expectedReason,
    ): void {
        $asset = $this->personalAsset($itemId, $typeId, $typeName, $quantity, $isSingleton, $itemName, $locationFlag);

        $failure = $this->failureOf();

        self::assertSame(422, $failure->getStatusCode());
        self::assertStringContainsStringIgnoringCase($expectedReason, $failure->getMessage());
        self::assertSame([], $this->namesRequests);
        self::assertSame($itemName, $asset->getItemName());
        self::assertSame(0, $this->flushCount);
    }

    private function resolve(string $assetId = self::ASSET_ID): AssetItemNameResource
    {
        return $this->processor->process(new EmptyInput(), new Post(), ['id' => $assetId]);
    }

    private function failureOf(string $assetId = self::ASSET_ID): HttpExceptionInterface
    {
        try {
            $this->resolve($assetId);
        } catch (HttpExceptionInterface $failure) {
            return $failure;
        }

        self::fail('The resolution of the item name did not fail');
    }

    private function createAssetsService(): AssetsService
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('post')->willReturnCallback(
            function (string $endpoint, array $body, ?EveToken $token = null): array {
                if ($endpoint === '/universe/names/') {
                    return [];
                }

                $this->namesRequests[] = ['endpoint' => $endpoint, 'body' => $body, 'token' => $token];

                if ($this->namesAnswer instanceof \Throwable) {
                    throw $this->namesAnswer;
                }

                return $this->namesAnswer;
            },
        );

        return new AssetsService(
            $esiClient,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
            $this->createStub(StaStationRepository::class),
        );
    }

    private function pilotUser(int $eveCharacterId, int $corporationId, EveToken $token): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId($corporationId);
        $character->setEveToken($token);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function personalAsset(
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        ?bool $isSingleton,
        ?string $itemName,
        string $locationFlag = 'Hangar',
    ): CachedAsset {
        return $this->assetOf($this->requesterCharacter, $itemId, $typeId, $typeName, $quantity, $isSingleton, $itemName, $locationFlag);
    }

    private function assetOf(
        Character $character,
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        ?bool $isSingleton,
        ?string $itemName,
        string $locationFlag = 'Hangar',
    ): CachedAsset {
        $asset = $this->cachedAsset($itemId, $typeId, $typeName, $quantity, $isSingleton, $itemName, $locationFlag)
            ->setCharacter($character)
            ->setIsCorporationAsset(false);
        $this->storedAsset = $asset;

        return $asset;
    }

    private function corporationAsset(
        int $itemId,
        int $typeId,
        string $typeName,
        ?bool $isSingleton,
        ?string $itemName,
        int $corporationId,
    ): CachedAsset {
        $asset = $this->cachedAsset($itemId, $typeId, $typeName, 1, $isSingleton, $itemName, 'CorpSAG1')
            ->setCorporationId($corporationId)
            ->setIsCorporationAsset(true);
        $this->storedAsset = $asset;

        return $asset;
    }

    private function cachedAsset(
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        ?bool $isSingleton,
        ?string $itemName,
        string $locationFlag,
    ): CachedAsset {
        $asset = (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId(60_003_760)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag($locationFlag)
            ->setIsSingleton($isSingleton)
            ->setItemName($itemName);
        (new \ReflectionProperty($asset, 'id'))->setValue($asset, Uuid::fromString(self::ASSET_ID));

        return $asset;
    }
}
