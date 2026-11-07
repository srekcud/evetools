<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\CachedStructure;
use App\Entity\Sde\MapSolarSystem;
use App\Message\SyncRegionalMarketVolumes;
use App\MessageHandler\SyncRegionalMarketVolumesHandler;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\JitaMarketService;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(SyncRegionalMarketVolumesHandler::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-419')]
final class SyncRegionalMarketVolumesHandlerTest extends TestCase
{
    private const int DEFAULT_STRUCTURE_ID = 1_035_466_617_946;
    private const int PREFERRED_STRUCTURE_ID = 1_046_664_001_931;
    private const int SECOND_PREFERRED_STRUCTURE_ID = 1_046_664_001_932;
    private const int UNKNOWN_STRUCTURE_ID = 1_046_664_001_933;
    private const int DEFAULT_REGION_ID = 10000060;
    private const int PREFERRED_REGION_ID = 10000030;
    private const string SYNC_TYPE = 'market-volumes';

    private JitaMarketService&MockObject $jitaMarketService;
    private IndustryActivityProductRepository&MockObject $productRepository;
    private UserRepository&MockObject $userRepository;
    private CachedStructureRepository&MockObject $cachedStructureRepository;
    private MapSolarSystemRepository&MockObject $solarSystemRepository;
    private SyncTracker $syncTracker;
    private SyncRegionalMarketVolumesHandler $handler;

    /** @var array<int, int|null> structureId => regionId */
    private array $regionByStructure = [];

    protected function setUp(): void
    {
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->cachedStructureRepository = $this->createMock(CachedStructureRepository::class);
        $this->solarSystemRepository = $this->createMock(MapSolarSystemRepository::class);
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
        $this->regionByStructure = [
            self::DEFAULT_STRUCTURE_ID => self::DEFAULT_REGION_ID,
            self::PREFERRED_STRUCTURE_ID => self::PREFERRED_REGION_ID,
            self::SECOND_PREFERRED_STRUCTURE_ID => self::PREFERRED_REGION_ID,
            self::UNKNOWN_STRUCTURE_ID => null,
        ];

        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 1, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => 2, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 11],
            ['blueprintTypeId' => 3, 'productTypeId' => 588, 'outputPerRun' => 1, 'activityId' => 1],
        ]);
        $this->cachedStructureRepository->method('findByStructureId')->willReturnCallback(
            function (int $structureId): ?CachedStructure {
                if ($this->regionByStructure[$structureId] === null) {
                    return null;
                }
                $structure = $this->createStub(CachedStructure::class);
                $structure->method('getSolarSystemId')->willReturn($structureId);

                return $structure;
            },
        );
        $this->solarSystemRepository->method('find')->willReturnCallback(
            function (int $solarSystemId): MapSolarSystem {
                $solarSystem = $this->createStub(MapSolarSystem::class);
                $solarSystem->method('getRegionId')->willReturn($this->regionByStructure[$solarSystemId]);

                return $solarSystem;
            },
        );
    }

    public function testSyncsEachDistinctRegionOnceWithTheDistinctProductTypes(): void
    {
        $this->givenPreferredStructures([self::PREFERRED_STRUCTURE_ID, self::SECOND_PREFERRED_STRUCTURE_ID]);
        $calls = [];
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            function (int $regionId, array $typeIds) use (&$calls): array {
                $calls[] = [$regionId, $typeIds];

                return ['synced' => 2, 'failed' => 0, 'aborted' => false];
            },
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([
            [self::DEFAULT_REGION_ID, [587, 588]],
            [self::PREFERRED_REGION_ID, [587, 588]],
        ], $calls);
        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('2 regions, 4 types synced', $state['message']);
    }

    public function testAStructureWhoseRegionIsUnknownIsSkippedWithoutFailingTheTracker(): void
    {
        $this->givenPreferredStructures([self::UNKNOWN_STRUCTURE_ID]);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('1 regions, 2 types synced; skipped (region unknown): ' . self::UNKNOWN_STRUCTURE_ID, $state['message']);
    }

    public function testFailedTypesFailTheTrackerWithTheCounts(): void
    {
        $this->givenPreferredStructures([]);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 5, 'failed' => 3, 'aborted' => false]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('1 regions, 5 types synced; 3 types failed', $state['message']);
    }

    public function testAnAbandonedRegionFailsTheTrackerAndNamesTheRegion(): void
    {
        $this->givenPreferredStructures([self::PREFERRED_STRUCTURE_ID]);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            static fn (int $regionId): array => $regionId === self::PREFERRED_REGION_ID
                ? ['synced' => 0, 'failed' => 60, 'aborted' => true]
                : ['synced' => 2, 'failed' => 0, 'aborted' => false],
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 2 types synced; 60 types failed; abandoned: ' . self::PREFERRED_REGION_ID, $state['message']);
    }

    public function testNoResolvableRegionCompletesTheTrackerWithoutAnyEsiSync(): void
    {
        $this->regionByStructure[self::DEFAULT_STRUCTURE_ID] = null;
        $this->givenPreferredStructures([]);
        $this->jitaMarketService->expects($this->never())->method('syncRegionalDailyVolumes');

        $this->handler()(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('0 regions, 0 types synced; skipped (region unknown): ' . self::DEFAULT_STRUCTURE_ID, $state['message']);
    }

    /**
     * @param list<int> $structureIds
     */
    private function givenPreferredStructures(array $structureIds): void
    {
        $this->userRepository->method('findDistinctPreferredMarketStructureIds')->willReturn($structureIds);
    }

    private function handler(): SyncRegionalMarketVolumesHandler
    {
        return new SyncRegionalMarketVolumesHandler(
            productRepository: $this->productRepository,
            userRepository: $this->userRepository,
            cachedStructureRepository: $this->cachedStructureRepository,
            solarSystemRepository: $this->solarSystemRepository,
            jitaMarketService: $this->jitaMarketService,
            syncTracker: $this->syncTracker,
            logger: new NullLogger(),
            defaultMarketStructureId: self::DEFAULT_STRUCTURE_ID,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function trackerState(): array
    {
        foreach ($this->syncTracker->getAll() as $state) {
            if ($state['type'] === self::SYNC_TYPE) {
                return $state;
            }
        }

        $this->fail('No tracker state for ' . self::SYNC_TYPE);
    }
}
