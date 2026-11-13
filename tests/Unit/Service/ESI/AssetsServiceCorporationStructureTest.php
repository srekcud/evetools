<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\MapSolarSystem;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
class AssetsServiceCorporationStructureTest extends TestCase
{
    use StubsSdeTypeClassification;

    private const int CORPORATION_ID = 98057377;
    private const string CORPORATION_ASSETS_ENDPOINT = '/corporations/98057377/assets/';
    private const string CORPORATION_ASSET_NAMES_ENDPOINT = '/corporations/98057377/assets/names/';

    private const int SYSTEM_ID = 30004000;
    private const string SYSTEM_NAME = '0UBC-R';

    private const int NAMED_OWNED_STRUCTURE_ID = 1050000000001;
    private const string NAMED_OWNED_STRUCTURE_NAME = '0UBC-R - Corp Fortizar';
    private const int RESOLVED_OWNED_STRUCTURE_ID = 1050000000002;
    private const string RESOLVED_OWNED_STRUCTURE_NAME = '0UBC-R - Corp Sotiyo';
    private const int UNRESOLVED_OWNED_STRUCTURE_ID = 1050000000003;
    private const int FOREIGN_STRUCTURE_ID = 1060000000001;
    private const string FOREIGN_STRUCTURE_NAME = '0UBC-R - The Crystal Coconut - T2 Repro';

    private const int JITA_STATION_ID = 60003760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int JITA_SYSTEM_ID = 30000142;
    private const string JITA_SYSTEM_NAME = 'Jita';

    private const int TYPE_FORTIZAR = 35833;
    private const int TYPE_SOTIYO = 35827;
    private const int TYPE_OFFICE = 27;
    private const int TYPE_STATION_CONTAINER = 17366;
    private const int TYPE_FUEL_BLOCK = 4247;
    private const int TYPE_TRITANIUM = 34;

    private const array TYPE_NAMES = [
        self::TYPE_FORTIZAR => 'Fortizar',
        self::TYPE_SOTIYO => 'Sotiyo',
        self::TYPE_OFFICE => 'Office',
        self::TYPE_STATION_CONTAINER => 'Station Container',
        self::TYPE_FUEL_BLOCK => 'Helium Fuel Block',
        self::TYPE_TRITANIUM => 'Tritanium',
    ];

    private const int OFFICE_IN_NAMED_STRUCTURE = 3100000001;
    private const int OFFICE_IN_RESOLVED_STRUCTURE = 3100000002;
    private const int OFFICE_IN_UNRESOLVED_STRUCTURE = 3100000003;
    private const int OFFICE_IN_FOREIGN_STRUCTURE = 3100000004;
    private const int CONTAINER_IN_NAMED_OFFICE = 3200000001;
    private const int TRITANIUM_IN_OFFICE = 3300000001;
    private const int TRITANIUM_IN_OFFICE_OF_RESOLVED_STRUCTURE = 3300000002;
    private const int TRITANIUM_IN_OFFICE_OF_UNRESOLVED_STRUCTURE = 3300000003;
    private const int TRITANIUM_IN_CONTAINER = 3300000004;
    private const int FUEL_IN_NAMED_STRUCTURE = 3300000005;
    private const int TRITANIUM_IN_OFFICE_OF_FOREIGN_STRUCTURE = 3300000006;
    private const int TRITANIUM_IN_JITA_HANGAR = 3300000007;

    private const array CUSTOM_ITEM_NAMES = [
        self::NAMED_OWNED_STRUCTURE_ID => self::NAMED_OWNED_STRUCTURE_NAME,
    ];

    /** @var array<int, AssetDto> */
    private array $assetsByItemId;

    /** @var list<string> */
    private array $requestedGetEndpoints = [];

    protected function setUp(): void
    {
        $this->assetsByItemId = [];
        foreach ($this->fetchCorporationAssets($this->rawAssets()) as $asset) {
            $this->assetsByItemId[$asset->itemId] = $asset;
        }
    }

    public function testItemInOfficeOfOwnedStructureIsLocatedAtOfficeAndStructureNamedByAssetName(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_OFFICE];

        $this->assertSame('Office — ' . self::NAMED_OWNED_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame(self::SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testItemInOfficeOfOwnedStructureWithoutAssetNameUsesResolvedStructureName(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_OFFICE_OF_RESOLVED_STRUCTURE];

        $this->assertSame('Office — ' . self::RESOLVED_OWNED_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame(self::SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testOfficesOfDifferentOwnedStructuresAreNotMergedUnderOneLabel(): void
    {
        $this->assertNotSame(
            $this->assetsByItemId[self::TRITANIUM_IN_OFFICE]->locationName,
            $this->assetsByItemId[self::TRITANIUM_IN_OFFICE_OF_RESOLVED_STRUCTURE]->locationName,
        );
    }

    public function testItemInContainerInOfficeOfOwnedStructureIsLocatedAtContainerAndStructure(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_CONTAINER];

        $this->assertSame('Station Container — ' . self::NAMED_OWNED_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame(self::SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testItemDirectlyInsideOwnedStructureIsLocatedAtTheStructure(): void
    {
        $asset = $this->assetsByItemId[self::FUEL_IN_NAMED_STRUCTURE];

        $this->assertSame(self::NAMED_OWNED_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame(self::SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testOwnedStructureRowReceivesItsSolarSystem(): void
    {
        $structure = $this->assetsByItemId[self::NAMED_OWNED_STRUCTURE_ID];

        $this->assertSame(self::SYSTEM_ID, $structure->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $structure->solarSystemName);
    }

    public function testItemInOfficeOfUnresolvableOwnedStructureKeepsExplicitUnknownStructureLabelWithSystem(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_OFFICE_OF_UNRESOLVED_STRUCTURE];

        $this->assertSame('Office — Structure #' . self::UNRESOLVED_OWNED_STRUCTURE_ID, $asset->locationName);
        $this->assertSame(self::SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testSolarSystemIdIsNeverLookedUpAsAStation(): void
    {
        $this->assertNotContains('/universe/stations/' . self::SYSTEM_ID . '/', $this->requestedGetEndpoints);
    }

    public function testItemInOfficeOfStructureOutsideTheAssetListIsUnchanged(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_OFFICE_OF_FOREIGN_STRUCTURE];

        $this->assertSame('Office — ' . self::FOREIGN_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame(self::SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testItemInStationHangarIsUnchanged(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_JITA_HANGAR];

        $this->assertSame(self::JITA_STATION_NAME, $asset->locationName);
        $this->assertSame(self::JITA_SYSTEM_ID, $asset->solarSystemId);
        $this->assertSame(self::JITA_SYSTEM_NAME, $asset->solarSystemName);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawAssets(): array
    {
        return [
            $this->rawAsset(self::NAMED_OWNED_STRUCTURE_ID, self::TYPE_FORTIZAR, 1, self::SYSTEM_ID, 'solar_system', 'Unlocked'),
            $this->rawAsset(self::RESOLVED_OWNED_STRUCTURE_ID, self::TYPE_SOTIYO, 1, self::SYSTEM_ID, 'solar_system', 'Unlocked'),
            $this->rawAsset(self::UNRESOLVED_OWNED_STRUCTURE_ID, self::TYPE_FORTIZAR, 1, self::SYSTEM_ID, 'solar_system', 'Unlocked'),

            $this->rawAsset(self::OFFICE_IN_NAMED_STRUCTURE, self::TYPE_OFFICE, 1, self::NAMED_OWNED_STRUCTURE_ID, 'item', 'OfficeFolder'),
            $this->rawAsset(self::OFFICE_IN_RESOLVED_STRUCTURE, self::TYPE_OFFICE, 1, self::RESOLVED_OWNED_STRUCTURE_ID, 'item', 'OfficeFolder'),
            $this->rawAsset(self::OFFICE_IN_UNRESOLVED_STRUCTURE, self::TYPE_OFFICE, 1, self::UNRESOLVED_OWNED_STRUCTURE_ID, 'item', 'OfficeFolder'),
            $this->rawAsset(self::OFFICE_IN_FOREIGN_STRUCTURE, self::TYPE_OFFICE, 1, self::FOREIGN_STRUCTURE_ID, 'other', 'OfficeFolder'),

            $this->rawAsset(self::CONTAINER_IN_NAMED_OFFICE, self::TYPE_STATION_CONTAINER, 1, self::OFFICE_IN_NAMED_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_IN_OFFICE, self::TYPE_TRITANIUM, 5000, self::OFFICE_IN_NAMED_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_IN_OFFICE_OF_RESOLVED_STRUCTURE, self::TYPE_TRITANIUM, 700, self::OFFICE_IN_RESOLVED_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_IN_OFFICE_OF_UNRESOLVED_STRUCTURE, self::TYPE_TRITANIUM, 300, self::OFFICE_IN_UNRESOLVED_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_IN_CONTAINER, self::TYPE_TRITANIUM, 900, self::CONTAINER_IN_NAMED_OFFICE, 'item', 'Unlocked'),
            $this->rawAsset(self::FUEL_IN_NAMED_STRUCTURE, self::TYPE_FUEL_BLOCK, 4000, self::NAMED_OWNED_STRUCTURE_ID, 'item', 'StructureFuel'),
            $this->rawAsset(self::TRITANIUM_IN_OFFICE_OF_FOREIGN_STRUCTURE, self::TYPE_TRITANIUM, 100, self::OFFICE_IN_FOREIGN_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_IN_JITA_HANGAR, self::TYPE_TRITANIUM, 50, self::JITA_STATION_ID, 'station', 'Hangar'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, string $locationFlag): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'location_id' => $locationId,
            'location_type' => $locationType,
            'location_flag' => $locationFlag,
            'is_singleton' => $quantity === 1,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     * @return AssetDto[]
     */
    private function fetchCorporationAssets(array $rawAssets): array
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        $assetsService = new AssetsService(
            $this->createEsiClient($rawAssets),
            $this->createSolarSystemRepository(),
            $this->createCachedStructureRepository(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createSdeTypeRepositoryKnowingNoType()),
            $this->createStub(StaStationRepository::class),
            $this->createSdeClassification([
                self::TYPE_FORTIZAR => self::structureClassification(),
                self::TYPE_SOTIYO => self::structureClassification(),
                self::TYPE_STATION_CONTAINER => self::cargoContainerClassification(),
                self::TYPE_TRITANIUM => self::mineralClassification(),
            ]),
        );

        return $assetsService->getCorporationAssets($character);
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createEsiClient(array $rawAssets): EsiClient&Stub
    {
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::CORPORATION_ASSETS_ENDPOINT ? $rawAssets : [],
        );

        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->requestedGetEndpoints[] = $endpoint;

            return match ($endpoint) {
                '/universe/stations/' . self::JITA_STATION_ID . '/' => [
                    'name' => self::JITA_STATION_NAME,
                    'system_id' => self::JITA_SYSTEM_ID,
                ],
                '/universe/structures/' . self::RESOLVED_OWNED_STRUCTURE_ID . '/' => [
                    'name' => self::RESOLVED_OWNED_STRUCTURE_NAME,
                    'solar_system_id' => self::SYSTEM_ID,
                    'owner_id' => self::CORPORATION_ID,
                    'type_id' => self::TYPE_SOTIYO,
                ],
                '/universe/structures/' . self::FOREIGN_STRUCTURE_ID . '/' => [
                    'name' => self::FOREIGN_STRUCTURE_NAME,
                    'solar_system_id' => self::SYSTEM_ID,
                    'owner_id' => 98000001,
                    'type_id' => self::TYPE_FORTIZAR,
                ],
                default => throw new EsiApiException('Forbidden', 403),
            };
        });

        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            if ($endpoint === '/universe/names/') {
                return array_values(array_map(
                    static fn (int $id): array => ['id' => $id, 'name' => self::TYPE_NAMES[$id] ?? self::SYSTEM_NAME, 'category' => 'inventory_type'],
                    array_filter($body, static fn (int $id): bool => isset(self::TYPE_NAMES[$id]) || $id === self::SYSTEM_ID),
                ));
            }

            if ($endpoint === self::CORPORATION_ASSET_NAMES_ENDPOINT) {
                return array_map(
                    static fn (int $itemId): array => ['item_id' => $itemId, 'name' => self::CUSTOM_ITEM_NAMES[$itemId] ?? 'None'],
                    $body,
                );
            }

            return [];
        });

        return $esiClient;
    }

    private function createSdeTypeRepositoryKnowingNoType(): InvTypeRepository&Stub
    {
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByTypeIds')->willReturn([]);

        return $repository;
    }

    private function createSolarSystemRepository(): MapSolarSystemRepository&Stub
    {
        $systemNames = [
            self::SYSTEM_ID => self::SYSTEM_NAME,
            self::JITA_SYSTEM_ID => self::JITA_SYSTEM_NAME,
        ];

        $repository = $this->createStub(MapSolarSystemRepository::class);
        $repository->method('findBySolarSystemId')->willReturnCallback(
            function (int $solarSystemId) use ($systemNames): ?MapSolarSystem {
                if (!isset($systemNames[$solarSystemId])) {
                    return null;
                }
                $solarSystem = $this->createStub(MapSolarSystem::class);
                $solarSystem->method('getSolarSystemName')->willReturn($systemNames[$solarSystemId]);

                return $solarSystem;
            },
        );

        return $repository;
    }

    private function createCachedStructureRepository(): CachedStructureRepository&Stub
    {
        $repository = $this->createStub(CachedStructureRepository::class);
        $repository->method('findByStructureIds')->willReturn([]);
        $repository->method('findByStructureId')->willReturn(null);

        return $repository;
    }
}
