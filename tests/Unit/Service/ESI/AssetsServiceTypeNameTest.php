<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapSolarSystem;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-115')]
final class AssetsServiceTypeNameTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2112000001;
    private const string ASSETS_ENDPOINT = '/characters/2112000001/assets/';

    private const int JITA_STATION_ID = 60003760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int JITA_SYSTEM_ID = 30000142;

    private const int TYPE_TRITANIUM = 34;
    private const int TYPE_PYERITE = 35;
    private const int TYPE_STATION_CONTAINER = 17366;
    private const int TYPE_MISSING_FROM_ESI_AND_SDE = 9848;

    private const int TRITANIUM_ITEM_ID = 2000000001;
    private const int PYERITE_ITEM_ID = 2000000002;
    private const int UNKNOWN_TYPE_ITEM_ID = 2000000003;
    private const int UNKNOWN_CONTAINER_ITEM_ID = 1000000001;
    private const int SDE_NAMED_CONTAINER_ITEM_ID = 1000000002;
    private const int TRITANIUM_IN_UNKNOWN_CONTAINER_ITEM_ID = 2000000004;
    private const int PYERITE_IN_SDE_NAMED_CONTAINER_ITEM_ID = 2000000005;

    private const array ESI_TYPE_NAMES = [
        self::TYPE_TRITANIUM => 'Tritanium',
    ];

    private const array SDE_TYPE_NAMES = [
        self::TYPE_TRITANIUM => 'Tritanium',
        self::TYPE_PYERITE => 'Pyerite',
        self::TYPE_STATION_CONTAINER => 'Station Container',
    ];

    /** @var array<int, AssetDto> */
    private array $assetsByItemId;

    protected function setUp(): void
    {
        $this->assetsByItemId = [];
        foreach ($this->fetchCharacterAssets() as $asset) {
            $this->assetsByItemId[$asset->itemId] = $asset;
        }
    }

    public function testAssetTypeNamedByEsiKeepsItsEsiName(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_ITEM_ID];

        self::assertSame('Tritanium', $tritanium->typeName);
        self::assertSame(self::TYPE_TRITANIUM, $tritanium->typeId);
        self::assertSame(5000, $tritanium->quantity);
    }

    public function testAssetTypeMissingFromEsiIsNamedBySde(): void
    {
        $pyerite = $this->assetsByItemId[self::PYERITE_ITEM_ID];

        self::assertSame('Pyerite', $pyerite->typeName);
        self::assertSame(self::TYPE_PYERITE, $pyerite->typeId);
        self::assertSame(3200, $pyerite->quantity);
    }

    public function testAssetTypeMissingFromEsiAndSdeHasNoName(): void
    {
        $unknown = $this->assetsByItemId[self::UNKNOWN_TYPE_ITEM_ID];

        self::assertNull($unknown->typeName);
        self::assertSame(self::TYPE_MISSING_FROM_ESI_AND_SDE, $unknown->typeId);
        self::assertSame(77, $unknown->quantity);
    }

    public function testItemInContainerWhoseTypeIsMissingFromEsiAndSdeIsLocatedAtTheUnknownTypeNameLabelAndStation(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_IN_UNKNOWN_CONTAINER_ITEM_ID];

        self::assertSame(
            'type 9848 (name missing from the SDE) — ' . self::JITA_STATION_NAME,
            $tritanium->locationName,
        );
        self::assertSame(1250, $tritanium->quantity);
    }

    public function testItemInContainerWhoseTypeIsMissingFromEsiIsLocatedAtTheSdeTypeNameAndStation(): void
    {
        $pyerite = $this->assetsByItemId[self::PYERITE_IN_SDE_NAMED_CONTAINER_ITEM_ID];

        self::assertSame('Station Container — ' . self::JITA_STATION_NAME, $pyerite->locationName);
        self::assertSame(410, $pyerite->quantity);
    }

    /**
     * @return AssetDto[]
     */
    private function fetchCharacterAssets(): array
    {
        $token = $this->createStub(EveToken::class);

        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);

        $assetsService = new AssetsService(
            $this->createEsiClient(),
            $this->createSolarSystemRepository(),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createSdeTypeRepository()),
        );

        return $assetsService->getCharacterAssets($character);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawAssets(): array
    {
        return [
            $this->rawAsset(self::TRITANIUM_ITEM_ID, self::TYPE_TRITANIUM, 5000, self::JITA_STATION_ID, 'station'),
            $this->rawAsset(self::PYERITE_ITEM_ID, self::TYPE_PYERITE, 3200, self::JITA_STATION_ID, 'station'),
            $this->rawAsset(self::UNKNOWN_TYPE_ITEM_ID, self::TYPE_MISSING_FROM_ESI_AND_SDE, 77, self::JITA_STATION_ID, 'station'),
            $this->rawAsset(self::UNKNOWN_CONTAINER_ITEM_ID, self::TYPE_MISSING_FROM_ESI_AND_SDE, 1, self::JITA_STATION_ID, 'station'),
            $this->rawAsset(self::TRITANIUM_IN_UNKNOWN_CONTAINER_ITEM_ID, self::TYPE_TRITANIUM, 1250, self::UNKNOWN_CONTAINER_ITEM_ID, 'item'),
            $this->rawAsset(self::SDE_NAMED_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, self::JITA_STATION_ID, 'station'),
            $this->rawAsset(self::PYERITE_IN_SDE_NAMED_CONTAINER_ITEM_ID, self::TYPE_PYERITE, 410, self::SDE_NAMED_CONTAINER_ITEM_ID, 'item'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'location_id' => $locationId,
            'location_type' => $locationType,
            'location_flag' => $locationType === 'item' ? 'Unlocked' : 'Hangar',
            'is_singleton' => $quantity === 1,
        ];
    }

    private function createEsiClient(): EsiClient
    {
        $rawAssets = $this->rawAssets();
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::ASSETS_ENDPOINT ? $rawAssets : [],
        );

        $esiClient->method('get')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === '/universe/stations/' . self::JITA_STATION_ID . '/'
                ? ['name' => self::JITA_STATION_NAME, 'system_id' => self::JITA_SYSTEM_ID]
                : throw new EsiApiException('Forbidden', 403),
        );

        $esiClient->method('post')->willReturnCallback(static function (string $endpoint, array $body): array {
            if ($endpoint !== '/universe/names/') {
                return [];
            }

            return array_values(array_map(
                static fn (int $typeId): array => ['id' => $typeId, 'name' => self::ESI_TYPE_NAMES[$typeId], 'category' => 'inventory_type'],
                array_filter($body, static fn (int $id): bool => isset(self::ESI_TYPE_NAMES[$id])),
            ));
        });

        return $esiClient;
    }

    private function createSdeTypeRepository(): InvTypeRepository
    {
        $knownTypes = [];
        foreach (self::SDE_TYPE_NAMES as $typeId => $typeName) {
            $knownTypes[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($typeName);
        }

        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($knownTypes, array_flip($typeIds)),
        );

        return $repository;
    }

    private function createSolarSystemRepository(): MapSolarSystemRepository
    {
        $solarSystem = $this->createStub(MapSolarSystem::class);
        $solarSystem->method('getSolarSystemName')->willReturn('Jita');

        $repository = $this->createStub(MapSolarSystemRepository::class);
        $repository->method('findBySolarSystemId')->willReturn($solarSystem);

        return $repository;
    }
}
