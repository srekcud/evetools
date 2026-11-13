<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Support\StubsSdeTypeClassification;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-863')]
final class AssetsServiceNameableTypesTest extends TestCase
{
    use StubsSdeTypeClassification;

    private const int CORPORATION_ID = 98000001;
    private const string ASSETS_ENDPOINT = '/corporations/98000001/assets/';
    private const string NAMES_ENDPOINT = '/corporations/98000001/assets/names/';

    private const int JITA_STATION_ID = 60003760;
    private const int TYPE_RIFTER = 587;
    private const int TYPE_STATION_WAREHOUSE_CONTAINER = 17366;
    private const int TYPE_RIFTER_BLUEPRINT = 691;
    private const int TYPE_DAMAGE_CONTROL = 2048;
    private const int TYPE_CALDARI_CONTROL_TOWER = 16213;
    private const int TYPE_ASTRAHUS = 35832;
    private const int TYPE_ABSENT_FROM_SDE = 888888;

    private const int NAMEABLE_FIRST_ITEM_ID = 1000000001;
    private const int BLUEPRINT_FIRST_ITEM_ID = 2000000001;
    private const int SINGLE_ITEM_ID = 3000000001;
    private const int NAMES_CHUNK_SIZE = 1000;
    private const int BISECTION_CALL_BUDGET = 50;

    private array $namesRequestBodies = [];

    private \Closure $rejectionStatusCodeOf;

    protected function setUp(): void
    {
        $this->namesRequestBodies = [];
        $this->rejectionStatusCodeOf = static fn (array $body, int $callNumber): ?int => null;
    }

    public static function rawAssetsOfTheSpecTable(): iterable
    {
        yield 'Rifter' => [self::TYPE_RIFTER, true, 'Hangar', true];
        yield 'Station Warehouse Container' => [self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'CorpSAG1', true];
        yield 'singleton blueprint' => [self::TYPE_RIFTER_BLUEPRINT, true, 'CorpSAG1', false];
        yield 'fitted module' => [self::TYPE_DAMAGE_CONTROL, true, 'MedSlot0', false];
        yield 'starbase tower' => [self::TYPE_CALDARI_CONTROL_TOWER, true, 'Hangar', true];
        yield 'Upwell structure' => [self::TYPE_ASTRAHUS, true, 'Hangar', true];
        yield 'Rifter stack' => [self::TYPE_RIFTER, false, 'Hangar', false];
        yield 'container in OfficeFolder' => [self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'OfficeFolder', false];
        yield 'type absent from the SDE' => [self::TYPE_ABSENT_FROM_SDE, true, 'Hangar', false];
    }

    #[DataProvider('rawAssetsOfTheSpecTable')]
    public function testAnItemIsSentToTheNamesEndpointOnlyWhenItIsASingletonOutsideAnOfficeOfANameableType(
        int $typeId,
        bool $isSingleton,
        string $locationFlag,
        bool $expectedSent,
    ): void {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::SINGLE_ITEM_ID, $typeId, $isSingleton, $locationFlag),
        ]);

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        if ($expectedSent) {
            self::assertSame([[self::SINGLE_ITEM_ID]], $this->namesRequestBodies);
            self::assertSame('Name of ' . self::SINGLE_ITEM_ID, $assets[self::SINGLE_ITEM_ID]->itemName);

            return;
        }

        self::assertSame([], $this->namesRequestBodies);
        self::assertNull($assets[self::SINGLE_ITEM_ID]->itemName);
    }

    public function testTheNamesBodyHoldsOnlyTheNameableIdsOfAMixedAssetList(): void
    {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(1000000001, self::TYPE_RIFTER, true, 'Hangar'),
            $this->rawAsset(1000000002, self::TYPE_RIFTER_BLUEPRINT, true, 'CorpSAG1'),
            $this->rawAsset(1000000003, self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'CorpSAG1'),
            $this->rawAsset(1000000004, self::TYPE_DAMAGE_CONTROL, true, 'MedSlot0'),
            $this->rawAsset(1000000005, self::TYPE_CALDARI_CONTROL_TOWER, true, 'Hangar'),
            $this->rawAsset(1000000006, self::TYPE_RIFTER, false, 'Hangar'),
            $this->rawAsset(1000000007, self::TYPE_ASTRAHUS, true, 'Hangar'),
            $this->rawAsset(1000000008, self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'OfficeFolder'),
            $this->rawAsset(1000000009, self::TYPE_ABSENT_FROM_SDE, true, 'Hangar'),
        ]);

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertSame([[1000000001, 1000000003, 1000000005, 1000000007]], $this->namesRequestBodies);
        self::assertSame(0, $assetsService->lastNameBatchFailures());
    }

    public function testNoNamesCallIsMadeWhenNoItemIsOfANameableType(): void
    {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(1000000001, self::TYPE_RIFTER_BLUEPRINT, true, 'CorpSAG1'),
            $this->rawAsset(1000000002, self::TYPE_DAMAGE_CONTROL, true, 'MedSlot0'),
            $this->rawAsset(1000000003, self::TYPE_ABSENT_FROM_SDE, true, 'Hangar'),
        ]);

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertSame([], $this->namesRequestBodies);
        self::assertSame(0, $assetsService->lastNameBatchFailures());
    }

    public function testChunksOfOneThousandAreCutAfterTheNameableFilter(): void
    {
        $rawAssets = [];
        $blueprintItemIds = [];
        for ($offset = 0; $offset < 1500; ++$offset) {
            $rawAssets[] = $this->rawAsset(self::NAMEABLE_FIRST_ITEM_ID + $offset, self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'CorpSAG1');
        }
        for ($offset = 0; $offset < 5000; ++$offset) {
            $blueprintItemId = self::BLUEPRINT_FIRST_ITEM_ID + $offset;
            $blueprintItemIds[] = $blueprintItemId;
            $rawAssets[] = $this->rawAsset($blueprintItemId, self::TYPE_RIFTER_BLUEPRINT, true, 'CorpSAG2');
        }
        $assetsService = $this->createAssetsService($rawAssets);

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertSame([self::NAMES_CHUNK_SIZE, 500], array_map('count', $this->namesRequestBodies));
        self::assertSame([], array_intersect(array_merge(...$this->namesRequestBodies), $blueprintItemIds));
        self::assertSame(0, $assetsService->lastNameBatchFailures());
    }

    public function testTheSdeIsQueriedOnceForTheTypesOfAllTheAssets(): void
    {
        $sdeTypes = $this->createMock(InvTypeRepository::class);
        $sdeTypes->expects(self::once())->method('findByTypeIds')->with([self::TYPE_RIFTER, self::TYPE_RIFTER_BLUEPRINT])->willReturn([]);
        $assetsService = $this->createAssetsService([
            $this->rawAsset(1000000001, self::TYPE_RIFTER, true, 'Hangar'),
            $this->rawAsset(1000000002, self::TYPE_RIFTER, true, 'Hangar'),
            $this->rawAsset(1000000003, self::TYPE_RIFTER_BLUEPRINT, true, 'CorpSAG1'),
        ], $sdeTypes);

        $assetsService->getCorporationAssets($this->createCharacter());
    }

    public function testTheSdeIsNotQueriedWhenNoItemIsASingletonOutsideAnOffice(): void
    {
        $sdeTypes = $this->createMock(InvTypeRepository::class);
        $sdeTypes->expects(self::never())->method('findByTypeIds');
        $assetsService = $this->createAssetsService([
            $this->rawAsset(1000000001, self::TYPE_RIFTER, false, 'Hangar'),
            $this->rawAsset(1000000002, self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'OfficeFolder'),
        ], $sdeTypes);

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertSame([], $this->namesRequestBodies);
    }

    public function testARejectedBatchDoesNotStarveTheBisectionOfTheNextBatch(): void
    {
        $rawAssets = [];
        for ($offset = 0; $offset < 1500; ++$offset) {
            $rawAssets[] = $this->rawAsset(self::NAMEABLE_FIRST_ITEM_ID + $offset, self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'CorpSAG1');
        }
        $firstBatchItemIds = range(self::NAMEABLE_FIRST_ITEM_ID, self::NAMEABLE_FIRST_ITEM_ID + 999);
        $unnameableItemId = self::NAMEABLE_FIRST_ITEM_ID + 1499;
        $this->rejectionStatusCodeOf = static fn (array $body, int $callNumber): ?int => array_intersect($body, [...$firstBatchItemIds, $unnameableItemId]) !== [] ? 404 : null;
        $assetsService = $this->createAssetsService($rawAssets);

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        self::assertNull($assets[self::NAMEABLE_FIRST_ITEM_ID]->itemName);
        self::assertNull($assets[$unnameableItemId]->itemName);
        self::assertSame('Name of ' . (self::NAMEABLE_FIRST_ITEM_ID + 1000), $assets[self::NAMEABLE_FIRST_ITEM_ID + 1000]->itemName);
        self::assertSame('Name of ' . (self::NAMEABLE_FIRST_ITEM_ID + 1498), $assets[self::NAMEABLE_FIRST_ITEM_ID + 1498]->itemName);
        self::assertSame(2, $assetsService->lastNameBatchFailures());
        self::assertSame(
            self::BISECTION_CALL_BUDGET + 1,
            \count(array_filter($this->namesRequestBodies, static fn (array $body): bool => $body[0] <= $firstBatchItemIds[999])),
        );
    }

    public function testALimitedResponseDuringTheBisectionOfTheFirstBatchStopsTheNextBatch(): void
    {
        $rawAssets = [];
        for ($offset = 0; $offset < 1500; ++$offset) {
            $rawAssets[] = $this->rawAsset(self::NAMEABLE_FIRST_ITEM_ID + $offset, self::TYPE_STATION_WAREHOUSE_CONTAINER, true, 'CorpSAG1');
        }
        $this->rejectionStatusCodeOf = static fn (array $body, int $callNumber): ?int => $callNumber === 1 ? 404 : 420;
        $assetsService = $this->createAssetsService($rawAssets);

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        self::assertSame([self::NAMES_CHUNK_SIZE, 500], array_map('count', $this->namesRequestBodies));
        self::assertSame(range(self::NAMEABLE_FIRST_ITEM_ID, self::NAMEABLE_FIRST_ITEM_ID + 499), $this->namesRequestBodies[1]);
        self::assertNull($assets[self::NAMEABLE_FIRST_ITEM_ID + 1000]->itemName);
        self::assertSame(2, $assetsService->lastNameBatchFailures());
    }

    private function createAssetsService(array $rawAssets, ?InvTypeRepository $sdeTypes = null): AssetsService
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::ASSETS_ENDPOINT ? $rawAssets : [],
        );
        $esiClient->method('get')->willReturn(['name' => 'Jita IV', 'system_id' => 30000142]);
        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            if ($endpoint !== self::NAMES_ENDPOINT) {
                return [];
            }

            $this->namesRequestBodies[] = $body;

            $statusCode = ($this->rejectionStatusCodeOf)($body, \count($this->namesRequestBodies));
            if ($statusCode !== null) {
                throw new EsiApiException('rejected', $statusCode, $endpoint);
            }

            return array_map(
                static fn (int $itemId): array => ['item_id' => $itemId, 'name' => 'Name of ' . $itemId],
                $body,
            );
        });

        return new AssetsService(
            $esiClient,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
            $this->createStub(StaStationRepository::class),
            $sdeTypes ?? $this->createSdeClassification([
                self::TYPE_RIFTER => self::shipClassification(),
                self::TYPE_STATION_WAREHOUSE_CONTAINER => self::cargoContainerClassification(),
                self::TYPE_RIFTER_BLUEPRINT => self::blueprintClassification(),
                self::TYPE_DAMAGE_CONTROL => self::moduleClassification(),
                self::TYPE_CALDARI_CONTROL_TOWER => self::starbaseClassification(),
                self::TYPE_ASTRAHUS => self::structureClassification(),
            ]),
        );
    }

    private function createCharacter(): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $character;
    }

    private function rawAsset(int $itemId, int $typeId, bool $isSingleton, string $locationFlag): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'quantity' => $isSingleton ? 1 : 25,
            'location_id' => self::JITA_STATION_ID,
            'location_type' => 'station',
            'location_flag' => $locationFlag,
            'is_singleton' => $isSingleton,
        ];
    }

    private function indexByItemId(array $assets): array
    {
        $indexed = [];
        foreach ($assets as $asset) {
            $indexed[$asset->itemId] = $asset;
        }

        return $indexed;
    }
}
