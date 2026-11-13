<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\CachedStructure;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
class AssetsServiceUnresolvedStructureRetryTest extends TestCase
{
    private const int CORPORATION_ID = 98057377;
    private const string CORPORATION_ASSETS_ENDPOINT = '/corporations/98057377/assets/';
    private const string CORPORATION_ASSET_NAMES_ENDPOINT = '/corporations/98057377/assets/names/';

    private const int SYSTEM_ID = 30004000;
    private const string SYSTEM_NAME = '0UBC-R';

    private const int FOREIGN_STRUCTURE_ID = 1060000000001;
    private const string FOREIGN_STRUCTURE_NAME = '0UBC-R - The Crystal Coconut - T2 Repro';
    private const string FOREIGN_STRUCTURE_ENDPOINT = '/universe/structures/1060000000001/';
    private const string UNRESOLVED_LABEL = 'Structure #1060000000001';

    private const int TYPE_FORTIZAR = 35833;
    private const int TYPE_TRITANIUM = 34;
    private const int TRITANIUM_ITEM_ID = 3300000001;

    private const string RECENT_ATTEMPT = '-1 hour';
    private const string OLD_ATTEMPT = '-24 hours';

    /** @var list<string> */
    private array $requestedStructureEndpoints = [];

    /** @var list<CachedStructure> */
    private array $persistedStructures = [];

    private bool $structureReadable = false;

    protected function setUp(): void
    {
        $this->requestedStructureEndpoints = [];
        $this->persistedStructures = [];
        $this->structureReadable = false;
    }

    public function testUnresolvedStructureAttemptedRecentlyIsNotRequestedAgain(): void
    {
        $cached = $this->unresolvedCachedStructure(new \DateTimeImmutable(self::RECENT_ATTEMPT));

        $this->fetchAssetInForeignStructure($cached);

        $this->assertSame([], $this->requestedStructureEndpoints);
    }

    public function testUnresolvedStructureAttemptedRecentlyKeepsShowingTheUnresolvedLabel(): void
    {
        $cached = $this->unresolvedCachedStructure(new \DateTimeImmutable(self::RECENT_ATTEMPT));

        $asset = $this->fetchAssetInForeignStructure($cached);

        $this->assertSame(self::UNRESOLVED_LABEL, $asset->locationName);
    }

    public function testUnresolvedStructureAttemptedRecentlyKeepsItsLastAttemptTimestamp(): void
    {
        $lastAttempt = new \DateTimeImmutable(self::RECENT_ATTEMPT);
        $cached = $this->unresolvedCachedStructure($lastAttempt);

        $this->fetchAssetInForeignStructure($cached);

        $this->assertEquals($lastAttempt, $cached->getLastResolutionAttemptAt());
    }

    public function testUnresolvedStructureIsRequestedAgainOnceTheDelayIsOver(): void
    {
        $cached = $this->unresolvedCachedStructure(new \DateTimeImmutable(self::OLD_ATTEMPT));

        $this->fetchAssetInForeignStructure($cached);

        $this->assertSame([self::FOREIGN_STRUCTURE_ENDPOINT], $this->requestedStructureEndpoints);
    }

    public function testUnresolvedStructureNeverAttemptedBeforeIsRequested(): void
    {
        $cached = $this->unresolvedCachedStructure(null);

        $this->fetchAssetInForeignStructure($cached);

        $this->assertSame([self::FOREIGN_STRUCTURE_ENDPOINT], $this->requestedStructureEndpoints);
    }

    public function testCharacterWithAccessResolvesTheStructureOnceTheDelayIsOver(): void
    {
        $cached = $this->unresolvedCachedStructure(new \DateTimeImmutable(self::OLD_ATTEMPT));
        $this->structureReadable = true;

        $asset = $this->fetchAssetInForeignStructure($cached);

        $this->assertSame(self::FOREIGN_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame(self::FOREIGN_STRUCTURE_NAME, $cached->getName());
    }

    public function testFailedRetryRecordsTheAttemptTime(): void
    {
        $cached = $this->unresolvedCachedStructure(new \DateTimeImmutable(self::OLD_ATTEMPT));
        $before = new \DateTimeImmutable();

        $asset = $this->fetchAssetInForeignStructure($cached);

        $this->assertSame(self::UNRESOLVED_LABEL, $asset->locationName);
        $this->assertGreaterThanOrEqual($before, $cached->getLastResolutionAttemptAt());
    }

    public function testFirstFailedResolutionPersistsTheUnresolvedStructureWithTheAttemptTime(): void
    {
        $before = new \DateTimeImmutable();

        $asset = $this->fetchAssetInForeignStructure(null);

        $this->assertSame(self::UNRESOLVED_LABEL, $asset->locationName);
        $this->assertCount(1, $this->persistedStructures);
        $this->assertSame(self::UNRESOLVED_LABEL, $this->persistedStructures[0]->getName());
        $this->assertGreaterThanOrEqual($before, $this->persistedStructures[0]->getLastResolutionAttemptAt());
    }

    public function testResolvedStructureIsNotRequestedAgain(): void
    {
        $cached = new CachedStructure();
        $cached->setStructureId(self::FOREIGN_STRUCTURE_ID);
        $cached->setName(self::FOREIGN_STRUCTURE_NAME);
        $cached->setSolarSystemId(self::SYSTEM_ID);

        $asset = $this->fetchAssetInForeignStructure($cached);

        $this->assertSame(self::FOREIGN_STRUCTURE_NAME, $asset->locationName);
        $this->assertSame([], $this->requestedStructureEndpoints);
    }

    private function unresolvedCachedStructure(?\DateTimeImmutable $lastAttempt): CachedStructure
    {
        $cached = new CachedStructure();
        $cached->setStructureId(self::FOREIGN_STRUCTURE_ID);
        $cached->setName(self::UNRESOLVED_LABEL);
        $cached->setLastResolutionAttemptAt($lastAttempt);

        return $cached;
    }

    private function fetchAssetInForeignStructure(?CachedStructure $cached): AssetDto
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        $assetsService = new AssetsService(
            $this->createEsiClient(),
            $this->createSolarSystemRepository(),
            $this->createCachedStructureRepository($cached),
            $this->createEntityManager(),
            new NullLogger(),
            new TypeNameResolver($this->createSdeTypeRepositoryKnowingNoType()),
            $this->createStub(StaStationRepository::class),
            $this->createStub(InvTypeRepository::class),
        );

        $assets = $assetsService->getCorporationAssets($character);

        $this->assertCount(1, $assets);

        return $assets[0];
    }

    private function createEsiClient(): EsiClient&Stub
    {
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::CORPORATION_ASSETS_ENDPOINT
                ? [[
                    'item_id' => self::TRITANIUM_ITEM_ID,
                    'type_id' => self::TYPE_TRITANIUM,
                    'quantity' => 5000,
                    'location_id' => self::FOREIGN_STRUCTURE_ID,
                    'location_type' => 'other',
                    'location_flag' => 'CorpSAG1',
                    'is_singleton' => false,
                ]]
                : [],
        );

        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            if (str_starts_with($endpoint, '/universe/structures/')) {
                $this->requestedStructureEndpoints[] = $endpoint;
            }

            if ($endpoint === self::FOREIGN_STRUCTURE_ENDPOINT && $this->structureReadable) {
                return [
                    'name' => self::FOREIGN_STRUCTURE_NAME,
                    'solar_system_id' => self::SYSTEM_ID,
                    'owner_id' => 98000001,
                    'type_id' => self::TYPE_FORTIZAR,
                ];
            }

            throw new EsiApiException('Forbidden', 403);
        });

        $esiClient->method('post')->willReturnCallback(
            static function (string $endpoint, array $body): array {
                if ($endpoint === self::CORPORATION_ASSET_NAMES_ENDPOINT) {
                    return array_map(
                        static fn (int $itemId): array => ['item_id' => $itemId, 'name' => 'None'],
                        $body,
                    );
                }

                return [];
            },
        );

        return $esiClient;
    }

    private function createEntityManager(): EntityManagerInterface&Stub
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof CachedStructure) {
                $this->persistedStructures[] = $entity;
            }
        });

        return $entityManager;
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

    private function createCachedStructureRepository(?CachedStructure $cached): CachedStructureRepository&Stub
    {
        $repository = $this->createStub(CachedStructureRepository::class);
        $repository->method('findByStructureIds')->willReturn(
            $cached === null ? [] : [self::FOREIGN_STRUCTURE_ID => $cached],
        );
        $repository->method('findByStructureId')->willReturn($cached);

        return $repository;
    }
}
