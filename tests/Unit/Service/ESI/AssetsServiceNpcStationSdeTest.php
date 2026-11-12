<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
class AssetsServiceNpcStationSdeTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 91000001;
    private const int CORPORATION_ID = 98057377;
    private const string CHARACTER_ASSETS_ENDPOINT = '/characters/91000001/assets/';
    private const string CORPORATION_ASSETS_ENDPOINT = '/corporations/98057377/assets/';

    private const int JITA_STATION_ID = 60003760;
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int JITA_SYSTEM_ID = 30000142;
    private const string JITA_SYSTEM_NAME = 'Jita';

    private const int AMARR_STATION_ID = 60008494;
    private const string AMARR_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy';
    private const int AMARR_SYSTEM_ID = 30002187;
    private const string AMARR_SYSTEM_NAME = 'Amarr';

    private const int NEW_STATION_ID = 60015999;
    private const string NEW_STATION_ESI_NAME = 'Perimeter VII - Moon 1 - New Outpost';
    private const int NEW_STATION_SYSTEM_ID = 30000144;
    private const string NEW_STATION_SYSTEM_NAME = 'Perimeter';

    private const int STRUCTURE_ID = 1050000000001;
    private const string STRUCTURE_NAME = 'Perimeter - Corp Fortizar';

    private const int TYPE_TRITANIUM = 34;
    private const int TYPE_PYERITE = 35;

    /** @var list<string> */
    private array $requestedGetEndpoints = [];

    /** @var list<string> */
    private array $requestedPostEndpoints = [];

    protected function setUp(): void
    {
        $this->requestedGetEndpoints = [];
        $this->requestedPostEndpoints = [];
    }

    public function testCharacterAssetsInSdeStationsReadNameAndSystemFromSdeWithoutEsiCall(): void
    {
        $assets = $this->fetchCharacterAssets($this->stationAssets());

        $this->assertSame(self::JITA_STATION_NAME, $assets[1]->locationName);
        $this->assertSame(self::JITA_SYSTEM_ID, $assets[1]->solarSystemId);
        $this->assertSame(self::JITA_SYSTEM_NAME, $assets[1]->solarSystemName);
        $this->assertSame(self::AMARR_STATION_NAME, $assets[2]->locationName);
        $this->assertSame(self::AMARR_SYSTEM_ID, $assets[2]->solarSystemId);
        $this->assertSame(self::AMARR_SYSTEM_NAME, $assets[2]->solarSystemName);
        $this->assertSame([], $this->stationLookups());
    }

    public function testCorporationAssetsInSdeStationsReadNameAndSystemFromSdeWithoutEsiCall(): void
    {
        $assets = $this->fetchCorporationAssets($this->stationAssets());

        $this->assertSame(self::JITA_STATION_NAME, $assets[1]->locationName);
        $this->assertSame(self::JITA_SYSTEM_ID, $assets[1]->solarSystemId);
        $this->assertSame(self::AMARR_STATION_NAME, $assets[2]->locationName);
        $this->assertSame(self::AMARR_SYSTEM_NAME, $assets[2]->solarSystemName);
        $this->assertSame([], $this->stationLookups());
    }

    public function testAllStationsAreLoadedFromSdeInASingleBatch(): void
    {
        $repository = $this->createMock(StaStationRepository::class);
        $repository->expects($this->once())
            ->method('findByStationIds')
            ->with($this->callback(static function (array $ids): bool {
                sort($ids);

                return $ids === [self::JITA_STATION_ID, self::AMARR_STATION_ID];
            }))
            ->willReturn($this->knownStations());

        $this->fetchCharacterAssets($this->stationAssets(), $repository);
    }

    public function testStationMissingFromSdeIsResolvedThroughEsiForThatStationOnly(): void
    {
        $assets = $this->fetchCharacterAssets([
            ...$this->stationAssets(),
            $this->rawAsset(3, self::TYPE_TRITANIUM, 10, self::NEW_STATION_ID),
        ]);

        $this->assertSame(self::NEW_STATION_ESI_NAME, $assets[3]->locationName);
        $this->assertSame(self::NEW_STATION_SYSTEM_ID, $assets[3]->solarSystemId);
        $this->assertSame(self::NEW_STATION_SYSTEM_NAME, $assets[3]->solarSystemName);
        $this->assertSame(['/universe/stations/' . self::NEW_STATION_ID . '/'], $this->stationLookups());
    }

    public function testStationMissingFromSdeIsReportedInTheLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool => in_array(self::NEW_STATION_ID, $context, true)),
            );

        $this->fetchCharacterAssets(
            [$this->rawAsset(3, self::TYPE_TRITANIUM, 10, self::NEW_STATION_ID)],
            logger: $logger,
        );
    }

    public function testStationKnownFromSdeIsNotReportedInTheLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->fetchCharacterAssets($this->stationAssets(), logger: $logger);
    }

    public function testStructureLookupStillGoesThroughEsiAndNeverThroughTheSdeStations(): void
    {
        $repository = $this->createMock(StaStationRepository::class);
        $repository->expects($this->once())
            ->method('findByStationIds')
            ->with([self::JITA_STATION_ID])
            ->willReturn([self::JITA_STATION_ID => $this->createStation(self::JITA_STATION_ID, self::JITA_STATION_NAME, self::JITA_SYSTEM_ID)]);

        $assets = $this->fetchCharacterAssets([
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000, self::JITA_STATION_ID),
            $this->rawAsset(4, self::TYPE_PYERITE, 20, self::STRUCTURE_ID),
        ], $repository);

        $this->assertSame(self::STRUCTURE_NAME, $assets[4]->locationName);
        $this->assertSame(self::NEW_STATION_SYSTEM_ID, $assets[4]->solarSystemId);
        $this->assertSame(['/universe/structures/' . self::STRUCTURE_ID . '/'], $this->structureLookups());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stationAssets(): array
    {
        return [
            $this->rawAsset(1, self::TYPE_TRITANIUM, 5000, self::JITA_STATION_ID),
            $this->rawAsset(2, self::TYPE_PYERITE, 800, self::AMARR_STATION_ID),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAsset(int $itemId, int $typeId, int $quantity, int $locationId): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'location_id' => $locationId,
            'location_type' => $locationId < 1000000000000 ? 'station' : 'other',
            'location_flag' => 'Hangar',
            'is_singleton' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     * @return array<int, AssetDto> indexés par item_id
     */
    private function fetchCharacterAssets(array $rawAssets, ?StaStationRepository $stationRepository = null, ?LoggerInterface $logger = null): array
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);

        $assets = $this->createAssetsService($rawAssets, $stationRepository, $logger)->getCharacterAssets($character);

        return $this->indexByItemId($assets);
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     * @return array<int, AssetDto> indexés par item_id
     */
    private function fetchCorporationAssets(array $rawAssets, ?StaStationRepository $stationRepository = null): array
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        $assets = $this->createAssetsService($rawAssets, $stationRepository, null)->getCorporationAssets($character);

        return $this->indexByItemId($assets);
    }

    /**
     * @param AssetDto[] $assets
     * @return array<int, AssetDto>
     */
    private function indexByItemId(array $assets): array
    {
        $indexed = [];
        foreach ($assets as $asset) {
            $indexed[$asset->itemId] = $asset;
        }

        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createAssetsService(array $rawAssets, ?StaStationRepository $stationRepository, ?LoggerInterface $logger): AssetsService
    {
        return new AssetsService(
            $this->createEsiClient($rawAssets),
            $this->createSolarSystemRepository(),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $logger ?? new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
            $stationRepository ?? $this->createKnownStationRepository(),
        );
    }

    private function createKnownStationRepository(): StaStationRepository&Stub
    {
        $repository = $this->createStub(StaStationRepository::class);
        $repository->method('findByStationIds')->willReturnCallback(
            fn (array $ids): array => array_intersect_key($this->knownStations(), array_flip($ids)),
        );

        return $repository;
    }

    /**
     * @return array<int, StaStation>
     */
    private function knownStations(): array
    {
        return [
            self::JITA_STATION_ID => $this->createStation(self::JITA_STATION_ID, self::JITA_STATION_NAME, self::JITA_SYSTEM_ID),
            self::AMARR_STATION_ID => $this->createStation(self::AMARR_STATION_ID, self::AMARR_STATION_NAME, self::AMARR_SYSTEM_ID),
        ];
    }

    private function createStation(int $stationId, string $stationName, int $solarSystemId): StaStation&Stub
    {
        $solarSystem = $this->createStub(MapSolarSystem::class);
        $solarSystem->method('getSolarSystemId')->willReturn($solarSystemId);

        $station = $this->createStub(StaStation::class);
        $station->method('getStationId')->willReturn($stationId);
        $station->method('getStationName')->willReturn($stationName);
        $station->method('getSolarSystem')->willReturn($solarSystem);

        return $station;
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createEsiClient(array $rawAssets): EsiClient&Stub
    {
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => in_array($endpoint, [self::CHARACTER_ASSETS_ENDPOINT, self::CORPORATION_ASSETS_ENDPOINT], true) ? $rawAssets : [],
        );

        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->requestedGetEndpoints[] = $endpoint;

            return match ($endpoint) {
                '/universe/stations/' . self::NEW_STATION_ID . '/' => [
                    'name' => self::NEW_STATION_ESI_NAME,
                    'system_id' => self::NEW_STATION_SYSTEM_ID,
                ],
                '/universe/structures/' . self::STRUCTURE_ID . '/' => [
                    'name' => self::STRUCTURE_NAME,
                    'solar_system_id' => self::NEW_STATION_SYSTEM_ID,
                    'owner_id' => 98000001,
                    'type_id' => 35833,
                ],
                default => throw new EsiApiException('Not found', 404),
            };
        });

        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            $this->requestedPostEndpoints[] = $endpoint;

            if (str_ends_with($endpoint, '/assets/names/')) {
                return array_map(static fn (int $itemId): array => ['item_id' => $itemId, 'name' => 'None'], $body);
            }

            return [];
        });

        return $esiClient;
    }

    private function createSolarSystemRepository(): MapSolarSystemRepository&Stub
    {
        $systemNames = [
            self::JITA_SYSTEM_ID => self::JITA_SYSTEM_NAME,
            self::AMARR_SYSTEM_ID => self::AMARR_SYSTEM_NAME,
            self::NEW_STATION_SYSTEM_ID => self::NEW_STATION_SYSTEM_NAME,
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

    /**
     * @return list<string>
     */
    private function stationLookups(): array
    {
        return array_values(array_filter(
            $this->requestedGetEndpoints,
            static fn (string $endpoint): bool => str_starts_with($endpoint, '/universe/stations/'),
        ));
    }

    /**
     * @return list<string>
     */
    private function structureLookups(): array
    {
        return array_values(array_filter(
            $this->requestedGetEndpoints,
            static fn (string $endpoint): bool => str_starts_with($endpoint, '/universe/structures/'),
        ));
    }
}
