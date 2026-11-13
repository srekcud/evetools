<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapSolarSystem;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-815')]
final class AssetsServiceTypeNameSourceTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2112000001;
    private const int CORPORATION_ID = 98000001;
    private const string CHARACTER_ASSETS_ENDPOINT = '/characters/2112000001/assets/';
    private const string CORPORATION_ASSETS_ENDPOINT = '/corporations/98000001/assets/';
    private const string UNIVERSE_NAMES_ENDPOINT = '/universe/names/';
    private const int ESI_NAMES_CHUNK_SIZE = 1000;

    private const int JITA_STATION_ID = 60003760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int JITA_SYSTEM_ID = 30000142;

    private const int TYPE_TRITANIUM = 34;
    private const int TYPE_PYERITE = 35;
    private const int TYPE_ABSENT_FROM_SDE = 9848;
    private const int FIRST_BULK_TYPE_ID = 100000;

    /** @var list<list<int>> */
    private array $typeNameRequests = [];

    protected function setUp(): void
    {
        $this->typeNameRequests = [];
    }

    public function testCharacterAssetsWhoseTypesAreAllInTheSdeNeverCallEsiUniverseNames(): void
    {
        $assets = $this->fetchCharacterAssets([
            self::TYPE_TRITANIUM => 'Tritanium',
            self::TYPE_PYERITE => 'Pyerite',
        ], [
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000),
            $this->rawAsset(2, self::TYPE_PYERITE, 3200),
        ]);

        self::assertSame([], $this->typeNameRequests);
        self::assertSame(['Tritanium', 'Pyerite'], array_map(static fn (AssetDto $asset): ?string => $asset->typeName, $assets));
    }

    public function testCorporationAssetsWhoseTypesAreAllInTheSdeNeverCallEsiUniverseNames(): void
    {
        $assets = $this->fetchCorporationAssets([
            self::TYPE_TRITANIUM => 'Tritanium',
            self::TYPE_PYERITE => 'Pyerite',
        ], [
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000),
            $this->rawAsset(2, self::TYPE_PYERITE, 3200),
        ]);

        self::assertSame([], $this->typeNameRequests);
        self::assertSame(['Tritanium', 'Pyerite'], array_map(static fn (AssetDto $asset): ?string => $asset->typeName, $assets));
    }

    public function testCharacterAssetsAskEsiUniverseNamesOnlyForTypesAbsentFromTheSdeAndKeepTheEsiName(): void
    {
        $assets = $this->fetchCharacterAssets([
            self::TYPE_TRITANIUM => 'Tritanium',
            self::TYPE_PYERITE => 'Pyerite',
        ], [
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000),
            $this->rawAsset(2, self::TYPE_PYERITE, 3200),
            $this->rawAsset(3, self::TYPE_ABSENT_FROM_SDE, 12),
        ]);

        self::assertSame([[self::TYPE_ABSENT_FROM_SDE]], $this->typeNameRequests);
        self::assertSame(['Tritanium', 'Pyerite', 'Esi Only Module'], array_map(static fn (AssetDto $asset): ?string => $asset->typeName, $assets));
    }

    public function testCorporationAssetsAskEsiUniverseNamesOnlyForTypesAbsentFromTheSdeAndKeepTheEsiName(): void
    {
        $assets = $this->fetchCorporationAssets([
            self::TYPE_TRITANIUM => 'Tritanium',
        ], [
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000),
            $this->rawAsset(3, self::TYPE_ABSENT_FROM_SDE, 12),
        ]);

        self::assertSame([[self::TYPE_ABSENT_FROM_SDE]], $this->typeNameRequests);
        self::assertSame(['Tritanium', 'Esi Only Module'], array_map(static fn (AssetDto $asset): ?string => $asset->typeName, $assets));
    }

    public function testSdeNameWinsOverTheEsiNameForATypeKnownToBoth(): void
    {
        $assets = $this->fetchCharacterAssets([
            self::TYPE_TRITANIUM => 'Tritanium (SDE)',
        ], [
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000),
        ]);

        self::assertSame('Tritanium (SDE)', $assets[0]->typeName);
    }

    public function testTypesAbsentFromTheSdeAreSentToEsiInChunksOfOneThousand(): void
    {
        $absentTypeCount = self::ESI_NAMES_CHUNK_SIZE + 1;
        $rawAssets = [];
        for ($offset = 0; $offset < $absentTypeCount; ++$offset) {
            $rawAssets[] = $this->rawAsset($offset + 1, self::FIRST_BULK_TYPE_ID + $offset, 1);
        }

        $this->fetchCharacterAssets([], $rawAssets);

        self::assertSame(
            [self::ESI_NAMES_CHUNK_SIZE, 1],
            array_map('count', $this->typeNameRequests),
        );
    }

    /**
     * @param array<int, string> $sdeTypeNames
     * @param list<array<string, mixed>> $rawAssets
     * @return AssetDto[]
     */
    private function fetchCharacterAssets(array $sdeTypeNames, array $rawAssets): array
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);

        return $this->createAssetsService($sdeTypeNames, self::CHARACTER_ASSETS_ENDPOINT, $rawAssets)->getCharacterAssets($character);
    }

    /**
     * @param array<int, string> $sdeTypeNames
     * @param list<array<string, mixed>> $rawAssets
     * @return AssetDto[]
     */
    private function fetchCorporationAssets(array $sdeTypeNames, array $rawAssets): array
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $this->createAssetsService($sdeTypeNames, self::CORPORATION_ASSETS_ENDPOINT, $rawAssets)->getCorporationAssets($character);
    }

    /**
     * @param array<int, string> $sdeTypeNames
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createAssetsService(array $sdeTypeNames, string $assetsEndpoint, array $rawAssets): AssetsService
    {
        return new AssetsService(
            $this->createEsiClient($assetsEndpoint, $rawAssets),
            $this->createSolarSystemRepository(),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createSdeTypeRepository($sdeTypeNames)),
            $this->createStub(StaStationRepository::class),
            $this->createStub(InvTypeRepository::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAsset(int $itemId, int $typeId, int $quantity): array
    {
        return [
            'item_id' => 2000000000 + $itemId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'location_id' => self::JITA_STATION_ID,
            'location_type' => 'station',
            'location_flag' => 'Hangar',
            'is_singleton' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createEsiClient(string $assetsEndpoint, array $rawAssets): EsiClient
    {
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === $assetsEndpoint ? $rawAssets : [],
        );

        $esiClient->method('get')->willReturn(['name' => self::JITA_STATION_NAME, 'system_id' => self::JITA_SYSTEM_ID]);

        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            if ($endpoint !== self::UNIVERSE_NAMES_ENDPOINT) {
                return [];
            }

            $this->typeNameRequests[] = array_values($body);

            return array_values(array_map(
                static fn (int $typeId): array => ['id' => $typeId, 'name' => 'Esi Only Module', 'category' => 'inventory_type'],
                $body,
            ));
        });

        return $esiClient;
    }

    /**
     * @param array<int, string> $sdeTypeNames
     */
    private function createSdeTypeRepository(array $sdeTypeNames): InvTypeRepository
    {
        $knownTypes = [];
        foreach ($sdeTypeNames as $typeId => $typeName) {
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
