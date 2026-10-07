<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\MapSolarSystem;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #55 : le contenu des containers personnels est compté dans le stock
 * mais affiché comme "Location #<item_id du container>".
 *
 * Attendu : "<nom du container> — <station/structure racine>", le nom du container
 * étant le nom personnalisé ESI s'il existe, sinon le nom du type du container.
 */
#[CoversClass(AssetsService::class)]
class AssetsServiceContainerLocationTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2112000001;
    private const string ASSETS_ENDPOINT = '/characters/2112000001/assets/';
    private const string ASSET_NAMES_ENDPOINT = '/characters/2112000001/assets/names/';

    private const int JITA_STATION_ID = 60003760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int JITA_SYSTEM_ID = 30000142;
    private const string JITA_SYSTEM_NAME = 'Jita';

    private const int ACCESSIBLE_STRUCTURE_ID = 1035466617946;
    private const string ACCESSIBLE_STRUCTURE_NAME = 'C-J6MT - Goonswarm Keepstar';
    private const int ACCESSIBLE_STRUCTURE_SYSTEM_ID = 30004759;
    private const string ACCESSIBLE_STRUCTURE_SYSTEM_NAME = 'C-J6MT';

    private const int FORBIDDEN_STRUCTURE_ID = 1040000000001;

    private const int TYPE_TRITANIUM = 34;
    private const int TYPE_PYERITE = 35;
    private const int TYPE_MEXALLON = 36;
    private const int TYPE_ISOGEN = 37;
    private const int TYPE_STATION_CONTAINER = 17366;
    private const int TYPE_MEDIUM_STANDARD_CONTAINER = 3296;
    private const int TYPE_SMALL_STANDARD_CONTAINER = 3293;
    private const int TYPE_VENTURE = 32880;

    private const array TYPE_NAMES = [
        self::TYPE_TRITANIUM => 'Tritanium',
        self::TYPE_PYERITE => 'Pyerite',
        self::TYPE_MEXALLON => 'Mexallon',
        self::TYPE_ISOGEN => 'Isogen',
        self::TYPE_STATION_CONTAINER => 'Station Container',
        self::TYPE_MEDIUM_STANDARD_CONTAINER => 'Medium Standard Container',
        self::TYPE_SMALL_STANDARD_CONTAINER => 'Small Standard Container',
        self::TYPE_VENTURE => 'Venture',
    ];

    // item_id des containers / vaisseaux
    private const int NAMED_CONTAINER_IN_STATION = 1000000001;
    private const int UNNAMED_CONTAINER_IN_STATION = 1000000002;
    private const int SHIP_IN_STRUCTURE = 1000000003;
    private const int CONTAINER_IN_SHIP = 1000000004;
    private const int CONTAINER_IN_FORBIDDEN_STRUCTURE = 1000000005;
    private const int SHIP_IN_STATION = 1000000006;
    private const int OUTER_CONTAINER_IN_SHIP_IN_STATION = 1000000007;
    private const int INNER_CONTAINER_IN_OUTER_CONTAINER = 1000000008;
    private const int CONTAINER_WITH_EMPTY_CUSTOM_NAME = 1000000009;
    private const int CYCLIC_CONTAINER_A = 1000000010;
    private const int CYCLIC_CONTAINER_B = 1000000011;
    private const int CONTAINER_IN_MISSING_PARENT = 1000000012;
    /** item_id absent de la liste d'assets ESI (parent non renvoyé) */
    private const int MISSING_PARENT_ID = 1000000099;

    // item_id des matériaux
    private const int TRITANIUM_IN_HANGAR = 2000000001;
    private const int TRITANIUM_IN_NAMED_CONTAINER = 2000000002;
    private const int PYERITE_IN_UNNAMED_CONTAINER = 2000000003;
    private const int MEXALLON_IN_CONTAINER_IN_SHIP = 2000000004;
    private const int ISOGEN_IN_SHIP_CARGO = 2000000005;
    private const int ISOGEN_IN_CONTAINER_IN_FORBIDDEN_STRUCTURE = 2000000006;
    private const int PYERITE_IN_STRUCTURE_HANGAR = 2000000007;
    private const int TRITANIUM_IN_INNER_CONTAINER = 2000000008;
    private const int MEXALLON_IN_EMPTY_NAMED_CONTAINER = 2000000009;
    private const int ISOGEN_IN_CYCLIC_CONTAINER_A = 2000000010;
    private const int PYERITE_IN_MISSING_PARENT = 2000000011;
    private const int TRITANIUM_IN_CONTAINER_IN_MISSING_PARENT = 2000000012;

    /** Noms personnalisés renvoyés par /characters/{id}/assets/names/ */
    private const array CUSTOM_ITEM_NAMES = [
        self::NAMED_CONTAINER_IN_STATION => 'Minerais',
        self::SHIP_IN_STRUCTURE => 'Mineur 01',
        self::CONTAINER_IN_SHIP => 'Cargo T1',
        self::CONTAINER_IN_FORBIDDEN_STRUCTURE => 'Stock nul-sec',
        self::SHIP_IN_STATION => 'Transport 02',
        self::OUTER_CONTAINER_IN_SHIP_IN_STATION => 'Coffre',
        self::INNER_CONTAINER_IN_OUTER_CONTAINER => 'Tiroir',
        self::CONTAINER_WITH_EMPTY_CUSTOM_NAME => '',
    ];

    /** @var array<int, AssetDto> indexés par item_id */
    private array $assetsByItemId;

    /** @var list<string> endpoints ESI appelés en GET */
    private array $requestedGetEndpoints = [];

    protected function setUp(): void
    {
        $this->assetsByItemId = [];
        foreach ($this->fetchCharacterAssets($this->rawAssets()) as $asset) {
            $this->assetsByItemId[$asset->itemId] = $asset;
        }
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     * @return AssetDto[]
     */
    private function fetchCharacterAssets(array $rawAssets): array
    {
        $token = $this->createStub(EveToken::class);

        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);

        $assetsService = new AssetsService(
            $this->createEsiClient($rawAssets),
            $this->createSolarSystemRepository(),
            $this->createCachedStructureRepository(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        return $assetsService->getCharacterAssets($character);
    }

    // ===========================================
    // Guards : items posés directement dans une station / structure
    // ===========================================

    public function testItemInStationHangarKeepsStationName(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_IN_HANGAR];

        $this->assertSame(self::JITA_STATION_NAME, $tritanium->locationName);
        $this->assertSame(self::JITA_STATION_ID, $tritanium->locationId);
        $this->assertSame(self::JITA_SYSTEM_NAME, $tritanium->solarSystemName);
        $this->assertSame(5000, $tritanium->quantity);
    }

    public function testItemInStructureHangarKeepsStructureName(): void
    {
        $pyerite = $this->assetsByItemId[self::PYERITE_IN_STRUCTURE_HANGAR];

        $this->assertSame(self::ACCESSIBLE_STRUCTURE_NAME, $pyerite->locationName);
        $this->assertSame(self::ACCESSIBLE_STRUCTURE_SYSTEM_NAME, $pyerite->solarSystemName);
    }

    public function testContainerItselfInStationKeepsStationNameAndCustomItemName(): void
    {
        $container = $this->assetsByItemId[self::NAMED_CONTAINER_IN_STATION];

        $this->assertSame(self::JITA_STATION_NAME, $container->locationName);
        $this->assertSame('Minerais', $container->itemName);
    }

    // ===========================================
    // Issue #55 : contenu des containers
    // ===========================================

    public function testItemInNamedContainerInStationIsLocatedAtContainerCustomNameAndStation(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_IN_NAMED_CONTAINER];

        $this->assertSame(
            'Minerais — Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            $tritanium->locationName,
        );
        $this->assertSame(12000, $tritanium->quantity);
    }

    public function testItemInUnnamedContainerIsLocatedAtContainerTypeNameAndStation(): void
    {
        $pyerite = $this->assetsByItemId[self::PYERITE_IN_UNNAMED_CONTAINER];

        $this->assertSame(
            'Medium Standard Container — Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            $pyerite->locationName,
        );
    }

    public function testItemInContainerInShipCargoResolvesRootStructure(): void
    {
        $mexallon = $this->assetsByItemId[self::MEXALLON_IN_CONTAINER_IN_SHIP];

        $this->assertSame('Cargo T1 — C-J6MT - Goonswarm Keepstar', $mexallon->locationName);
    }

    public function testItemInShipCargoIsLocatedAtShipCustomNameAndRootStructure(): void
    {
        $isogen = $this->assetsByItemId[self::ISOGEN_IN_SHIP_CARGO];

        $this->assertSame('Mineur 01 — C-J6MT - Goonswarm Keepstar', $isogen->locationName);
    }

    public function testItemInContainerInUnresolvableStructureKeepsStructureFallbackWithContainerName(): void
    {
        $isogen = $this->assetsByItemId[self::ISOGEN_IN_CONTAINER_IN_FORBIDDEN_STRUCTURE];

        $this->assertSame('Stock nul-sec — Structure #1040000000001', $isogen->locationName);
    }

    public function testItemInContainerInheritsSolarSystemOfRootLocation(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_IN_NAMED_CONTAINER];
        $mexallon = $this->assetsByItemId[self::MEXALLON_IN_CONTAINER_IN_SHIP];

        $this->assertSame(self::JITA_SYSTEM_ID, $tritanium->solarSystemId);
        $this->assertSame(self::JITA_SYSTEM_NAME, $tritanium->solarSystemName);
        $this->assertSame(self::ACCESSIBLE_STRUCTURE_SYSTEM_ID, $mexallon->solarSystemId);
        $this->assertSame(self::ACCESSIBLE_STRUCTURE_SYSTEM_NAME, $mexallon->solarSystemName);
    }

    public function testItemInDeeplyNestedContainerIsLocatedAtImmediateContainerAndRootStation(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_IN_INNER_CONTAINER];

        $this->assertSame('Tiroir — Jita IV - Moon 4 - Caldari Navy Assembly Plant', $tritanium->locationName);
        $this->assertSame(self::INNER_CONTAINER_IN_OUTER_CONTAINER, $tritanium->locationId);
        $this->assertSame(self::JITA_SYSTEM_ID, $tritanium->solarSystemId);
        $this->assertSame(self::JITA_SYSTEM_NAME, $tritanium->solarSystemName);
        $this->assertSame(777, $tritanium->quantity);
    }

    public function testContainerInShipInStationIsLocatedAtShipCustomNameAndStation(): void
    {
        $outerContainer = $this->assetsByItemId[self::OUTER_CONTAINER_IN_SHIP_IN_STATION];

        $this->assertSame('Transport 02 — Jita IV - Moon 4 - Caldari Navy Assembly Plant', $outerContainer->locationName);
    }

    public function testItemInContainerWithEmptyCustomNameIsLocatedAtContainerTypeName(): void
    {
        $mexallon = $this->assetsByItemId[self::MEXALLON_IN_EMPTY_NAMED_CONTAINER];

        $this->assertSame(
            'Medium Standard Container — Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            $mexallon->locationName,
        );
        $this->assertNull($this->assetsByItemId[self::CONTAINER_WITH_EMPTY_CUSTOM_NAME]->itemName);
    }

    public function testItemWhoseParentIsMissingFromAssetListFallsBackToGenericLocationLabel(): void
    {
        $pyerite = $this->assetsByItemId[self::PYERITE_IN_MISSING_PARENT];

        $this->assertSame('Location #1000000099', $pyerite->locationName);
        $this->assertNull($pyerite->solarSystemId);
        $this->assertNull($pyerite->solarSystemName);
    }

    public function testItemInContainerWhoseParentIsMissingFallsBackToContainerLocationLabel(): void
    {
        $tritanium = $this->assetsByItemId[self::TRITANIUM_IN_CONTAINER_IN_MISSING_PARENT];

        $this->assertSame('Location #1000000012', $tritanium->locationName);
        $this->assertNull($tritanium->solarSystemId);
        $this->assertSame(330, $tritanium->quantity);
    }

    public function testCyclicContainerChainFallsBackToGenericLocationLabelWithoutLooping(): void
    {
        $isogen = $this->assetsByItemId[self::ISOGEN_IN_CYCLIC_CONTAINER_A];
        $containerA = $this->assetsByItemId[self::CYCLIC_CONTAINER_A];
        $containerB = $this->assetsByItemId[self::CYCLIC_CONTAINER_B];

        $this->assertSame('Location #1000000010', $isogen->locationName);
        $this->assertSame('Location #1000000011', $containerA->locationName);
        $this->assertSame('Location #1000000010', $containerB->locationName);
        $this->assertNull($isogen->solarSystemId);
    }

    public function testContainerItemIdsAreNeverLookedUpAsStationsOrStructures(): void
    {
        $locationLookups = array_values(array_filter(
            $this->requestedGetEndpoints,
            static fn (string $endpoint): bool => str_starts_with($endpoint, '/universe/stations/') || str_starts_with($endpoint, '/universe/structures/'),
        ));
        sort($locationLookups);

        $this->assertSame([
            '/universe/stations/' . self::JITA_STATION_ID . '/',
            '/universe/structures/' . self::ACCESSIBLE_STRUCTURE_ID . '/',
            '/universe/structures/' . self::FORBIDDEN_STRUCTURE_ID . '/',
        ], $locationLookups);
    }

    public function testCyclicChainIsStillCountedInStock(): void
    {
        $this->assertSame(40, $this->assetsByItemId[self::ISOGEN_IN_CYCLIC_CONTAINER_A]->quantity);
    }

    /**
     * Le champ location_type est requis par le schéma ESI. Un asset qui en est dépourvu
     * fait échouer toute la récupération (AssetDto::$locationType non nullable) :
     * le `?? ''` de la remontée de chaîne n'est donc jamais atteint en pratique.
     */
    public function testParentWithoutLocationTypeMakesWholeAssetFetchFail(): void
    {
        $containerWithoutLocationType = $this->rawAsset(self::NAMED_CONTAINER_IN_STATION, self::TYPE_STATION_CONTAINER, 1, self::JITA_STATION_ID, 'station', 'Hangar');
        unset($containerWithoutLocationType['location_type']);

        $this->expectException(\TypeError::class);

        set_error_handler(static fn (): bool => true, \E_WARNING);
        try {
            // L'item contenu est traité avant son container : la remontée de chaîne s'arrête
            // sur le parent sans location_type, puis la construction du DTO du parent échoue.
            $this->fetchCharacterAssets([
                $this->rawAsset(self::TRITANIUM_IN_NAMED_CONTAINER, self::TYPE_TRITANIUM, 12000, self::NAMED_CONTAINER_IN_STATION, 'item', 'Unlocked'),
                $containerWithoutLocationType,
            ]);
        } finally {
            restore_error_handler();
        }
    }

    // ===========================================
    // Fixtures ESI
    // ===========================================

    /**
     * @return list<array<string, mixed>>
     */
    private function rawAssets(): array
    {
        return [
            // Jita : hangar, container nommé, container non nommé
            $this->rawAsset(self::TRITANIUM_IN_HANGAR, self::TYPE_TRITANIUM, 5000, self::JITA_STATION_ID, 'station', 'Hangar'),
            $this->rawAsset(self::NAMED_CONTAINER_IN_STATION, self::TYPE_STATION_CONTAINER, 1, self::JITA_STATION_ID, 'station', 'Hangar'),
            $this->rawAsset(self::TRITANIUM_IN_NAMED_CONTAINER, self::TYPE_TRITANIUM, 12000, self::NAMED_CONTAINER_IN_STATION, 'item', 'Unlocked'),
            $this->rawAsset(self::UNNAMED_CONTAINER_IN_STATION, self::TYPE_MEDIUM_STANDARD_CONTAINER, 1, self::JITA_STATION_ID, 'station', 'Hangar'),
            $this->rawAsset(self::PYERITE_IN_UNNAMED_CONTAINER, self::TYPE_PYERITE, 3000, self::UNNAMED_CONTAINER_IN_STATION, 'item', 'Unlocked'),
            // Structure accessible : hangar, vaisseau, container dans la soute
            $this->rawAsset(self::PYERITE_IN_STRUCTURE_HANGAR, self::TYPE_PYERITE, 800, self::ACCESSIBLE_STRUCTURE_ID, 'other', 'Hangar'),
            $this->rawAsset(self::SHIP_IN_STRUCTURE, self::TYPE_VENTURE, 1, self::ACCESSIBLE_STRUCTURE_ID, 'other', 'Hangar'),
            $this->rawAsset(self::CONTAINER_IN_SHIP, self::TYPE_SMALL_STANDARD_CONTAINER, 1, self::SHIP_IN_STRUCTURE, 'item', 'Cargo'),
            $this->rawAsset(self::MEXALLON_IN_CONTAINER_IN_SHIP, self::TYPE_MEXALLON, 450, self::CONTAINER_IN_SHIP, 'item', 'Unlocked'),
            $this->rawAsset(self::ISOGEN_IN_SHIP_CARGO, self::TYPE_ISOGEN, 90, self::SHIP_IN_STRUCTURE, 'item', 'Cargo'),
            // Structure sans accès (ESI 403)
            $this->rawAsset(self::CONTAINER_IN_FORBIDDEN_STRUCTURE, self::TYPE_STATION_CONTAINER, 1, self::FORBIDDEN_STRUCTURE_ID, 'other', 'Hangar'),
            $this->rawAsset(self::ISOGEN_IN_CONTAINER_IN_FORBIDDEN_STRUCTURE, self::TYPE_ISOGEN, 60, self::CONTAINER_IN_FORBIDDEN_STRUCTURE, 'item', 'Unlocked'),
            // Jita : chaîne profonde item -> container -> container -> vaisseau -> station
            $this->rawAsset(self::SHIP_IN_STATION, self::TYPE_VENTURE, 1, self::JITA_STATION_ID, 'station', 'Hangar'),
            $this->rawAsset(self::OUTER_CONTAINER_IN_SHIP_IN_STATION, self::TYPE_MEDIUM_STANDARD_CONTAINER, 1, self::SHIP_IN_STATION, 'item', 'Cargo'),
            $this->rawAsset(self::INNER_CONTAINER_IN_OUTER_CONTAINER, self::TYPE_SMALL_STANDARD_CONTAINER, 1, self::OUTER_CONTAINER_IN_SHIP_IN_STATION, 'item', 'Unlocked'),
            $this->rawAsset(self::TRITANIUM_IN_INNER_CONTAINER, self::TYPE_TRITANIUM, 777, self::INNER_CONTAINER_IN_OUTER_CONTAINER, 'item', 'Unlocked'),
            // Jita : container dont le nom personnalisé ESI est vide
            $this->rawAsset(self::CONTAINER_WITH_EMPTY_CUSTOM_NAME, self::TYPE_MEDIUM_STANDARD_CONTAINER, 1, self::JITA_STATION_ID, 'station', 'Hangar'),
            $this->rawAsset(self::MEXALLON_IN_EMPTY_NAMED_CONTAINER, self::TYPE_MEXALLON, 120, self::CONTAINER_WITH_EMPTY_CUSTOM_NAME, 'item', 'Unlocked'),
            // Chaîne cyclique (données ESI incohérentes) : A dans B, B dans A
            $this->rawAsset(self::CYCLIC_CONTAINER_A, self::TYPE_SMALL_STANDARD_CONTAINER, 1, self::CYCLIC_CONTAINER_B, 'item', 'Unlocked'),
            $this->rawAsset(self::CYCLIC_CONTAINER_B, self::TYPE_SMALL_STANDARD_CONTAINER, 1, self::CYCLIC_CONTAINER_A, 'item', 'Unlocked'),
            $this->rawAsset(self::ISOGEN_IN_CYCLIC_CONTAINER_A, self::TYPE_ISOGEN, 40, self::CYCLIC_CONTAINER_A, 'item', 'Unlocked'),
            // Parent absent de la liste d'assets
            $this->rawAsset(self::PYERITE_IN_MISSING_PARENT, self::TYPE_PYERITE, 250, self::MISSING_PARENT_ID, 'item', 'Unlocked'),
            $this->rawAsset(self::CONTAINER_IN_MISSING_PARENT, self::TYPE_SMALL_STANDARD_CONTAINER, 1, self::MISSING_PARENT_ID, 'item', 'Cargo'),
            $this->rawAsset(self::TRITANIUM_IN_CONTAINER_IN_MISSING_PARENT, self::TYPE_TRITANIUM, 330, self::CONTAINER_IN_MISSING_PARENT, 'item', 'Unlocked'),
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
     */
    private function createEsiClient(array $rawAssets): EsiClient&Stub
    {
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::ASSETS_ENDPOINT ? $rawAssets : [],
        );

        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->requestedGetEndpoints[] = $endpoint;

            return match ($endpoint) {
                '/universe/stations/' . self::JITA_STATION_ID . '/' => [
                    'name' => self::JITA_STATION_NAME,
                    'system_id' => self::JITA_SYSTEM_ID,
                ],
                '/universe/structures/' . self::ACCESSIBLE_STRUCTURE_ID . '/' => [
                    'name' => self::ACCESSIBLE_STRUCTURE_NAME,
                    'solar_system_id' => self::ACCESSIBLE_STRUCTURE_SYSTEM_ID,
                    'owner_id' => 98000001,
                    'type_id' => 35834,
                ],
                default => throw new EsiApiException('Forbidden', 403),
            };
        });

        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            if ($endpoint === '/universe/names/') {
                return array_values(array_map(
                    static fn (int $typeId): array => ['id' => $typeId, 'name' => self::TYPE_NAMES[$typeId], 'category' => 'inventory_type'],
                    array_filter($body, static fn (int $id): bool => isset(self::TYPE_NAMES[$id])),
                ));
            }

            if ($endpoint === self::ASSET_NAMES_ENDPOINT) {
                // ESI renvoie "None" pour les items sans nom personnalisé
                return array_map(
                    static fn (int $itemId): array => ['item_id' => $itemId, 'name' => self::CUSTOM_ITEM_NAMES[$itemId] ?? 'None'],
                    $body,
                );
            }

            return [];
        });

        return $esiClient;
    }

    private function createSolarSystemRepository(): MapSolarSystemRepository&Stub
    {
        $systemNames = [
            self::JITA_SYSTEM_ID => self::JITA_SYSTEM_NAME,
            self::ACCESSIBLE_STRUCTURE_SYSTEM_ID => self::ACCESSIBLE_STRUCTURE_SYSTEM_NAME,
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
