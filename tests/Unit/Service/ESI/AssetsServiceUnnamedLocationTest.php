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
class AssetsServiceUnnamedLocationTest extends TestCase
{
    use StubsSdeTypeClassification;

    private const int CORPORATION_ID = 98057377;
    private const string CORPORATION_ASSETS_ENDPOINT = '/corporations/98057377/assets/';
    private const string CORPORATION_ASSET_NAMES_ENDPOINT = '/corporations/98057377/assets/names/';

    private const int SYSTEM_ID = 30004000;
    private const string SYSTEM_NAME = '0UBC-R';

    private const int BLANK_OWNED_STRUCTURE_ID = 1050000000011;
    private const int BLANK_FOREIGN_STRUCTURE_ID = 1060000000011;
    private const int NAMED_FOREIGN_STRUCTURE_ID = 1060000000012;
    private const string NAMED_FOREIGN_STRUCTURE_NAME = '0UBC-R - The Crystal Coconut - T2 Repro';
    private const int UNRESOLVABLE_FOREIGN_STRUCTURE_ID = 1060000000013;

    private const int TYPE_FORTIZAR = 35833;
    private const int TYPE_OFFICE = 27;
    private const int TYPE_ASSET_SAFETY_WRAP = 60;
    private const int TYPE_TRITANIUM = 34;

    private const array TYPE_NAMES = [
        self::TYPE_FORTIZAR => 'Fortizar',
        self::TYPE_OFFICE => 'Office',
        self::TYPE_ASSET_SAFETY_WRAP => 'Asset Safety Wrap',
        self::TYPE_TRITANIUM => 'Tritanium',
    ];

    private const int OFFICE_IN_BLANK_OWNED_STRUCTURE = 3100000011;
    private const int OFFICE_IN_BLANK_FOREIGN_STRUCTURE = 3100000012;
    private const int WRAP_IN_SOLAR_SYSTEM = 1070000000011;
    private const int WRAP_AT_BLANK_STRUCTURE = 3200000012;
    private const int WRAP_AT_NAMED_STRUCTURE = 3200000013;
    private const int WRAP_AT_UNRESOLVABLE_STRUCTURE = 3200000014;

    private const int TRITANIUM_IN_OFFICE_OF_BLANK_OWNED = 3300000011;
    private const int TRITANIUM_IN_OFFICE_OF_BLANK_FOREIGN = 3300000012;
    private const int TRITANIUM_DIRECTLY_IN_BLANK_OWNED = 3300000013;
    private const int TRITANIUM_IN_WRAP_IN_SOLAR_SYSTEM = 3300000014;
    private const int TRITANIUM_IN_WRAP_AT_BLANK_STRUCTURE = 3300000015;
    private const int TRITANIUM_IN_WRAP_AT_NAMED_STRUCTURE = 3300000016;
    private const int TRITANIUM_IN_WRAP_AT_UNRESOLVABLE_STRUCTURE = 3300000017;

    /** @var array<int, AssetDto> */
    private array $assetsByItemId;

    protected function setUp(): void
    {
        $this->assetsByItemId = [];
        foreach ($this->fetchCorporationAssets($this->rawAssets()) as $asset) {
            $this->assetsByItemId[$asset->itemId] = $asset;
        }
    }

    public function testOfficeOfOwnedStructureWithoutNameIsLocatedAtUnknownStructureInItsSystem(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_OFFICE_OF_BLANK_OWNED];

        $this->assertSame('Office — Unknown structure (' . self::SYSTEM_NAME . ')', $asset->locationName);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testOfficeOfForeignStructureWithoutNameIsLocatedAtUnknownStructureInItsSystem(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_OFFICE_OF_BLANK_FOREIGN];

        $this->assertSame('Office — Unknown structure (' . self::SYSTEM_NAME . ')', $asset->locationName);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testItemDirectlyInsideOwnedStructureWithoutNameIsLocatedAtUnknownStructureInItsSystem(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_DIRECTLY_IN_BLANK_OWNED];

        $this->assertSame('Unknown structure (' . self::SYSTEM_NAME . ')', $asset->locationName);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testAssetSafetyWrapInSolarSystemWithUnknownOriginIsNamedAfterItsSystem(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_WRAP_IN_SOLAR_SYSTEM];

        $this->assertSame('Asset Safety (' . self::SYSTEM_NAME . ')', $asset->locationName);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testAssetSafetyWrapAtStructureWithoutNameIsNamedAfterItsSystem(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_WRAP_AT_BLANK_STRUCTURE];

        $this->assertSame('Asset Safety (' . self::SYSTEM_NAME . ')', $asset->locationName);
        $this->assertSame(self::SYSTEM_NAME, $asset->solarSystemName);
    }

    public function testAssetSafetyWrapAtNamedStructureKeepsTheOriginStructureName(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_WRAP_AT_NAMED_STRUCTURE];

        $this->assertSame('Asset Safety Wrap — ' . self::NAMED_FOREIGN_STRUCTURE_NAME, $asset->locationName);
    }

    public function testAssetSafetyWrapAtUnresolvableStructureWithoutSystemKeepsTheStructureIdLabel(): void
    {
        $asset = $this->assetsByItemId[self::TRITANIUM_IN_WRAP_AT_UNRESOLVABLE_STRUCTURE];

        $this->assertSame('Asset Safety Wrap — Structure #' . self::UNRESOLVABLE_FOREIGN_STRUCTURE_ID, $asset->locationName);
    }

    public function testNoStoredLocationNameIsBlankOrEndsWithADanglingSeparator(): void
    {
        foreach ($this->assetsByItemId as $asset) {
            $this->assertNotSame('', trim($asset->locationName), "asset {$asset->itemId}");
            $this->assertDoesNotMatchRegularExpression('/—\s*$/u', $asset->locationName, "asset {$asset->itemId}");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawAssets(): array
    {
        return [
            $this->rawAsset(self::BLANK_OWNED_STRUCTURE_ID, self::TYPE_FORTIZAR, 1, self::SYSTEM_ID, 'solar_system', 'Unlocked'),
            $this->rawAsset(self::OFFICE_IN_BLANK_OWNED_STRUCTURE, self::TYPE_OFFICE, 1, self::BLANK_OWNED_STRUCTURE_ID, 'item', 'OfficeFolder'),
            $this->rawAsset(self::OFFICE_IN_BLANK_FOREIGN_STRUCTURE, self::TYPE_OFFICE, 1, self::BLANK_FOREIGN_STRUCTURE_ID, 'other', 'OfficeFolder'),
            $this->rawAsset(self::WRAP_IN_SOLAR_SYSTEM, self::TYPE_ASSET_SAFETY_WRAP, 1, self::SYSTEM_ID, 'solar_system', 'AssetSafety'),
            $this->rawAsset(self::WRAP_AT_BLANK_STRUCTURE, self::TYPE_ASSET_SAFETY_WRAP, 1, self::BLANK_FOREIGN_STRUCTURE_ID, 'other', 'AssetSafety'),
            $this->rawAsset(self::WRAP_AT_NAMED_STRUCTURE, self::TYPE_ASSET_SAFETY_WRAP, 1, self::NAMED_FOREIGN_STRUCTURE_ID, 'other', 'AssetSafety'),
            $this->rawAsset(self::WRAP_AT_UNRESOLVABLE_STRUCTURE, self::TYPE_ASSET_SAFETY_WRAP, 1, self::UNRESOLVABLE_FOREIGN_STRUCTURE_ID, 'other', 'AssetSafety'),

            $this->rawAsset(self::TRITANIUM_IN_OFFICE_OF_BLANK_OWNED, self::TYPE_TRITANIUM, 500, self::OFFICE_IN_BLANK_OWNED_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_IN_OFFICE_OF_BLANK_FOREIGN, self::TYPE_TRITANIUM, 400, self::OFFICE_IN_BLANK_FOREIGN_STRUCTURE, 'item', 'CorpSAG1'),
            $this->rawAsset(self::TRITANIUM_DIRECTLY_IN_BLANK_OWNED, self::TYPE_TRITANIUM, 300, self::BLANK_OWNED_STRUCTURE_ID, 'item', 'Hangar'),
            $this->rawAsset(self::TRITANIUM_IN_WRAP_IN_SOLAR_SYSTEM, self::TYPE_TRITANIUM, 200, self::WRAP_IN_SOLAR_SYSTEM, 'item', 'AssetSafety'),
            $this->rawAsset(self::TRITANIUM_IN_WRAP_AT_BLANK_STRUCTURE, self::TYPE_TRITANIUM, 150, self::WRAP_AT_BLANK_STRUCTURE, 'item', 'AssetSafety'),
            $this->rawAsset(self::TRITANIUM_IN_WRAP_AT_NAMED_STRUCTURE, self::TYPE_TRITANIUM, 120, self::WRAP_AT_NAMED_STRUCTURE, 'item', 'AssetSafety'),
            $this->rawAsset(self::TRITANIUM_IN_WRAP_AT_UNRESOLVABLE_STRUCTURE, self::TYPE_TRITANIUM, 110, self::WRAP_AT_UNRESOLVABLE_STRUCTURE, 'item', 'AssetSafety'),
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

        $esiClient->method('get')->willReturnCallback(static fn (string $endpoint): array => match ($endpoint) {
            '/universe/structures/' . self::BLANK_OWNED_STRUCTURE_ID . '/' => [
                'name' => '',
                'solar_system_id' => self::SYSTEM_ID,
                'owner_id' => self::CORPORATION_ID,
                'type_id' => self::TYPE_FORTIZAR,
            ],
            '/universe/structures/' . self::BLANK_FOREIGN_STRUCTURE_ID . '/' => [
                'name' => '   ',
                'solar_system_id' => self::SYSTEM_ID,
                'owner_id' => 98000001,
                'type_id' => self::TYPE_FORTIZAR,
            ],
            '/universe/structures/' . self::NAMED_FOREIGN_STRUCTURE_ID . '/' => [
                'name' => self::NAMED_FOREIGN_STRUCTURE_NAME,
                'solar_system_id' => self::SYSTEM_ID,
                'owner_id' => 98000001,
                'type_id' => self::TYPE_FORTIZAR,
            ],
            default => throw new EsiApiException('Forbidden', 403),
        });

        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            if ($endpoint === '/universe/names/') {
                return array_values(array_map(
                    static fn (int $id): array => ['id' => $id, 'name' => self::TYPE_NAMES[$id] ?? self::SYSTEM_NAME, 'category' => 'inventory_type'],
                    array_filter($body, static fn (int $id): bool => isset(self::TYPE_NAMES[$id]) || $id === self::SYSTEM_ID),
                ));
            }

            if ($endpoint === self::CORPORATION_ASSET_NAMES_ENDPOINT) {
                return array_map(static fn (int $itemId): array => ['item_id' => $itemId, 'name' => 'None'], $body);
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
        $repository = $this->createStub(MapSolarSystemRepository::class);
        $repository->method('findBySolarSystemId')->willReturnCallback(
            function (int $solarSystemId): ?MapSolarSystem {
                if ($solarSystemId !== self::SYSTEM_ID) {
                    return null;
                }
                $solarSystem = $this->createStub(MapSolarSystem::class);
                $solarSystem->method('getSolarSystemName')->willReturn(self::SYSTEM_NAME);

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
