<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Constant\EveConstants;
use App\Entity\CachedStructure;
use App\Entity\Sde\MapSolarSystem;
use App\Exception\EsiApiException;
use App\Message\SyncRegionalMarketVolumes;
use App\MessageHandler\SyncRegionalMarketVolumesHandler;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Tests\Unit\Service\Admin\InMemorySyncRunLog;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryProductTypeResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(SyncRegionalMarketVolumesHandler::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-419')]
#[Group('issue-781')]
#[Group('issue-1404')]
final class SyncRegionalMarketVolumesHandlerTest extends TestCase
{
    private const int DEFAULT_STRUCTURE_ID = 1_035_466_617_946;
    private const int PREFERRED_STRUCTURE_ID = 1_046_664_001_931;
    private const int SECOND_PREFERRED_STRUCTURE_ID = 1_046_664_001_932;
    private const int UNKNOWN_STRUCTURE_ID = 1_046_664_001_933;
    private const int DEFAULT_REGION_ID = 10000060;
    private const int PREFERRED_REGION_ID = 10000030;
    private const int THE_FORGE_REGION_ID = EveConstants::THE_FORGE_REGION_ID;
    private const string SYNC_TYPE = 'market-volumes';
    private const int COOLANT = 91_021;
    private const int ROBOTICS = 91_031;
    private const int BROADCAST_NODE = 91_041;
    private const int WATER = 91_011;
    private const int SUPERCONDUCTORS = 91_051;
    private const int TRANSMITTER = 91_061;
    private const int SYNTHETIC_OIL = 91_071;
    private const int MECHANICAL_PARTS = 91_081;
    private const array SEVEN_PLANETARY_PRODUCTS = [
        self::COOLANT => 'P2',
        self::ROBOTICS => 'P3',
        self::BROADCAST_NODE => 'P4',
        self::SUPERCONDUCTORS => 'P2',
        self::TRANSMITTER => 'P3',
        self::SYNTHETIC_OIL => 'P2',
        self::MECHANICAL_PARTS => 'P3',
    ];

    private JitaMarketService&MockObject $jitaMarketService;
    private IndustryActivityProductRepository&MockObject $productRepository;
    private UserRepository&MockObject $userRepository;
    private CachedStructureRepository&MockObject $cachedStructureRepository;
    private MapSolarSystemRepository&MockObject $solarSystemRepository;
    private PlanetSchematicTypeRepository&MockObject $schematicTypeRepository;
    private PlanetaryProductionCalculator&MockObject $productionCalculator;
    private MarketHistoryService&MockObject $marketHistoryService;
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
        $this->schematicTypeRepository = $this->createMock(PlanetSchematicTypeRepository::class);
        $this->productionCalculator = $this->createMock(PlanetaryProductionCalculator::class);
        $this->marketHistoryService = $this->createMock(MarketHistoryService::class);
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            new InMemorySyncRunLog(),
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
            [self::THE_FORGE_REGION_ID, [587, 588]],
            [self::DEFAULT_REGION_ID, [587, 588]],
            [self::PREFERRED_REGION_ID, [587, 588]],
        ], $calls);
        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('3 regions, 6 types synced', $state['message']);
    }

    public function testTheForgeIsSyncedBeforeTheDefaultStructureRegionWhenNoStructureIsPreferred(): void
    {
        $this->givenPreferredStructures([]);
        $calls = [];
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            function (int $regionId, array $typeIds) use (&$calls): array {
                $calls[] = [$regionId, $typeIds];

                return ['synced' => 2, 'failed' => 0, 'aborted' => false];
            },
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([
            [self::THE_FORGE_REGION_ID, [587, 588]],
            [self::DEFAULT_REGION_ID, [587, 588]],
        ], $calls);
    }

    public function testAPreferredStructureInTheForgeDoesNotSyncTheForgeTwice(): void
    {
        $this->regionByStructure[self::PREFERRED_STRUCTURE_ID] = self::THE_FORGE_REGION_ID;
        $this->givenPreferredStructures([self::PREFERRED_STRUCTURE_ID]);
        $syncedRegionIds = [];
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            function (int $regionId) use (&$syncedRegionIds): array {
                $syncedRegionIds[] = $regionId;

                return ['synced' => 2, 'failed' => 0, 'aborted' => false];
            },
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([self::THE_FORGE_REGION_ID, self::DEFAULT_REGION_ID], $syncedRegionIds);
        $this->assertSame('2 regions, 4 types synced', $this->trackerState()['message']);
    }

    public function testAStructureWhoseRegionIsUnknownIsSkippedWithoutFailingTheTracker(): void
    {
        $this->givenPreferredStructures([self::UNKNOWN_STRUCTURE_ID]);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('2 regions, 4 types synced; skipped (region unknown): ' . self::UNKNOWN_STRUCTURE_ID, $state['message']);
    }

    public function testFailedTypesFailTheTrackerWithTheCounts(): void
    {
        $this->givenPreferredStructures([]);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 5, 'failed' => 3, 'aborted' => false]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 10 types synced; 6 types failed', $state['message']);
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
        $this->assertSame('3 regions, 4 types synced; 60 types failed; abandoned: ' . self::PREFERRED_REGION_ID, $state['message']);
    }

    public function testAbandoningTheForgeFailsTheTrackerNamesItAndStillSyncsTheOtherRegions(): void
    {
        $this->givenPreferredStructures([]);
        $syncedRegionIds = [];
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            function (int $regionId) use (&$syncedRegionIds): array {
                $syncedRegionIds[] = $regionId;

                return $regionId === self::THE_FORGE_REGION_ID
                    ? ['synced' => 0, 'failed' => 60, 'aborted' => true]
                    : ['synced' => 2, 'failed' => 0, 'aborted' => false];
            },
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([self::THE_FORGE_REGION_ID, self::DEFAULT_REGION_ID], $syncedRegionIds);
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 2 types synced; 60 types failed; abandoned: ' . self::THE_FORGE_REGION_ID, $state['message']);
    }

    public function testWithoutAnyResolvableStructureTheForgeIsStillSyncedAndTheTrackerCompletes(): void
    {
        $this->regionByStructure[self::DEFAULT_STRUCTURE_ID] = null;
        $this->givenPreferredStructures([]);
        $calls = [];
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            function (int $regionId, array $typeIds) use (&$calls): array {
                $calls[] = [$regionId, $typeIds];

                return ['synced' => 2, 'failed' => 0, 'aborted' => false];
            },
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([[self::THE_FORGE_REGION_ID, [587, 588]]], $calls);
        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('1 regions, 2 types synced; skipped (region unknown): ' . self::DEFAULT_STRUCTURE_ID, $state['message']);
    }

    public function testTheHistoryOfEachP2P3AndP4ProductIsSyncedOnTheForgeOnlyWhileTheVolumeListOfEachRegionStaysUnchanged(): void
    {
        $this->givenPreferredStructures([self::PREFERRED_STRUCTURE_ID]);
        $this->givenPlanetaryProducts([self::WATER => 'P1', self::COOLANT => 'P2', self::ROBOTICS => 'P3', self::BROADCAST_NODE => 'P4']);
        $volumeCalls = [];
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            function (int $regionId, array $typeIds) use (&$volumeCalls): array {
                $volumeCalls[] = [$regionId, $typeIds];

                return ['synced' => 2, 'failed' => 0, 'aborted' => false];
            },
        );
        $historyCalls = $this->recordHistorySyncs();

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([
            [self::THE_FORGE_REGION_ID, [587, 588]],
            [self::DEFAULT_REGION_ID, [587, 588]],
            [self::PREFERRED_REGION_ID, [587, 588]],
        ], $volumeCalls);
        $this->assertSame([
            [self::COOLANT, self::THE_FORGE_REGION_ID],
            [self::ROBOTICS, self::THE_FORGE_REGION_ID],
            [self::BROADCAST_NODE, self::THE_FORGE_REGION_ID],
        ], $historyCalls());
        $state = $this->trackerState();
        $this->assertSame('ok', $state['status']);
        $this->assertSame('3 regions, 9 types synced', $state['message']);
    }

    public function testAnEsiFailureOnOneProductHistoryCountsAsAFailedTypeAndTheOtherProductsAreStillSynced(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts([self::COOLANT => 'P2', self::ROBOTICS => 'P3', self::BROADCAST_NODE => 'P4']);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = [];
        $this->marketHistoryService->method('syncHistory')->willReturnCallback(
            function (int $typeId, int $regionId) use (&$attemptedTypeIds): int {
                $attemptedTypeIds[] = $typeId;
                if ($typeId === self::ROBOTICS) {
                    throw EsiApiException::fromResponse(503, 'Service unavailable', sprintf('/markets/%d/history/?type_id=%d', $regionId, $typeId));
                }

                return 1;
            },
        );

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([self::COOLANT, self::ROBOTICS, self::BROADCAST_NODE], $attemptedTypeIds);
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 6 types synced; 1 types failed', $state['message']);
    }

    public function testAFailureThatIsNotAnEsiErrorFailsTheTrackerWithItsMessageAndIsRethrown(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts([self::COOLANT => 'P2', self::ROBOTICS => 'P3']);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = [];
        $this->marketHistoryService->method('syncHistory')->willReturnCallback(
            function (int $typeId) use (&$attemptedTypeIds): int {
                $attemptedTypeIds[] = $typeId;
                throw new \RuntimeException('Database connection lost');
            },
        );

        $thrown = null;
        try {
            $this->handler()(new SyncRegionalMarketVolumes());
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('Database connection lost', $thrown->getMessage());

        $this->assertSame([self::COOLANT], $attemptedTypeIds);
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('Database connection lost', $state['message']);
    }

    public function testWithoutAnyP2P3OrP4SchematicNoHistoryIsSynced(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts([self::WATER => 'P1']);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $historyCalls = $this->recordHistorySyncs();

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([], $historyCalls());
        $this->assertSame('2 regions, 4 types synced', $this->trackerState()['message']);
    }

    public function testAnEmptyProductHistoryCountsAsAFailedTypeWithAWarningAndNotAsSynced(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts([self::COOLANT => 'P2', self::ROBOTICS => 'P3', self::BROADCAST_NODE => 'P4']);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = $this->givenHistoryOutcomes([self::COOLANT => 3, self::ROBOTICS => 0, self::BROADCAST_NODE => 7]);
        $warnedTypeIds = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string|\Stringable $message, array $context) use (&$warnedTypeIds): void {
                $warnedTypeIds[] = $context['typeId'];
            },
        );

        $this->handler($logger)(new SyncRegionalMarketVolumes());

        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 6 types synced; 1 types failed', $state['message']);
        $this->assertSame([self::COOLANT, self::ROBOTICS, self::BROADCAST_NODE], $attemptedTypeIds());
        $this->assertSame([self::ROBOTICS], $warnedTypeIds);
    }

    public function testAbandoningTheForgeSyncsNoProductHistoryAndCountsEachProductAsFailed(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts([self::COOLANT => 'P2', self::ROBOTICS => 'P3', self::BROADCAST_NODE => 'P4']);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturnCallback(
            static fn (int $regionId): array => $regionId === self::THE_FORGE_REGION_ID
                ? ['synced' => 0, 'failed' => 60, 'aborted' => true]
                : ['synced' => 2, 'failed' => 0, 'aborted' => false],
        );
        $attemptedTypeIds = $this->givenHistoryOutcomes([self::COOLANT => 3, self::ROBOTICS => 7, self::BROADCAST_NODE => 11]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([], $attemptedTypeIds());
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 2 types synced; 63 types failed; abandoned: ' . self::THE_FORGE_REGION_ID, $state['message']);
    }

    #[DataProvider('esiErrorBudgetStatusCodes')]
    public function testAnEsiErrorBudgetOrRateLimitStatusStopsTheProductHistoryLoopAndCountsTheRemainingProductsAsFailed(int $statusCode): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts(self::SEVEN_PLANETARY_PRODUCTS);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = $this->givenHistoryOutcomes([
            self::COOLANT => 4,
            self::ROBOTICS => $this->esiFailure($statusCode),
            self::BROADCAST_NODE => 7,
            self::SUPERCONDUCTORS => 9,
            self::TRANSMITTER => 12,
            self::SYNTHETIC_OIL => 15,
            self::MECHANICAL_PARTS => 21,
        ]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([self::COOLANT, self::ROBOTICS], $attemptedTypeIds());
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 5 types synced; 6 types failed', $state['message']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function esiErrorBudgetStatusCodes(): array
    {
        return [
            'error budget exhausted' => [420],
            'rate limited' => [429],
        ];
    }

    public function testAnEsiServiceUnavailableStatusOnTheFirstProductDoesNotStopTheLoopAndCountsASingleFailure(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts(self::SEVEN_PLANETARY_PRODUCTS);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = $this->givenHistoryOutcomes([
            self::COOLANT => $this->esiFailure(503),
            self::ROBOTICS => 4,
            self::BROADCAST_NODE => 7,
            self::SUPERCONDUCTORS => 9,
            self::TRANSMITTER => 12,
            self::SYNTHETIC_OIL => 15,
            self::MECHANICAL_PARTS => 21,
        ]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame(
            [self::COOLANT, self::ROBOTICS, self::BROADCAST_NODE, self::SUPERCONDUCTORS, self::TRANSMITTER, self::SYNTHETIC_OIL, self::MECHANICAL_PARTS],
            $attemptedTypeIds(),
        );
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 10 types synced; 1 types failed', $state['message']);
    }

    public function testThreeConsecutiveFailedProductHistoriesStopTheLoopAndCountTheRemainingProductsAsFailed(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts(self::SEVEN_PLANETARY_PRODUCTS);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = $this->givenHistoryOutcomes([
            self::COOLANT => $this->esiFailure(503),
            self::ROBOTICS => $this->esiFailure(503),
            self::BROADCAST_NODE => $this->esiFailure(503),
            self::SUPERCONDUCTORS => 9,
            self::TRANSMITTER => 12,
            self::SYNTHETIC_OIL => 15,
            self::MECHANICAL_PARTS => 21,
        ]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame([self::COOLANT, self::ROBOTICS, self::BROADCAST_NODE], $attemptedTypeIds());
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 4 types synced; 7 types failed', $state['message']);
    }

    public function testASuccessfulProductHistoryBetweenFailuresResetsTheConsecutiveFailureCount(): void
    {
        $this->givenPreferredStructures([]);
        $this->givenPlanetaryProducts(self::SEVEN_PLANETARY_PRODUCTS);
        $this->jitaMarketService->method('syncRegionalDailyVolumes')->willReturn(['synced' => 2, 'failed' => 0, 'aborted' => false]);
        $attemptedTypeIds = $this->givenHistoryOutcomes([
            self::COOLANT => $this->esiFailure(503),
            self::ROBOTICS => $this->esiFailure(503),
            self::BROADCAST_NODE => 7,
            self::SUPERCONDUCTORS => $this->esiFailure(503),
            self::TRANSMITTER => $this->esiFailure(503),
            self::SYNTHETIC_OIL => 15,
            self::MECHANICAL_PARTS => 21,
        ]);

        $this->handler()(new SyncRegionalMarketVolumes());

        $this->assertSame(
            [self::COOLANT, self::ROBOTICS, self::BROADCAST_NODE, self::SUPERCONDUCTORS, self::TRANSMITTER, self::SYNTHETIC_OIL, self::MECHANICAL_PARTS],
            $attemptedTypeIds(),
        );
        $state = $this->trackerState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('2 regions, 7 types synced; 4 types failed', $state['message']);
    }

    /**
     * @param array<int, int|EsiApiException> $outcomeByTypeId
     */
    private function givenHistoryOutcomes(array $outcomeByTypeId): \Closure
    {
        $attemptedTypeIds = [];
        $this->marketHistoryService->method('syncHistory')->willReturnCallback(
            function (int $typeId) use (&$attemptedTypeIds, $outcomeByTypeId): int {
                $attemptedTypeIds[] = $typeId;
                $outcome = $outcomeByTypeId[$typeId];
                if ($outcome instanceof EsiApiException) {
                    throw $outcome;
                }

                return $outcome;
            },
        );

        return static function () use (&$attemptedTypeIds): array {
            return $attemptedTypeIds;
        };
    }

    private function esiFailure(int $statusCode): EsiApiException
    {
        return EsiApiException::fromResponse($statusCode, 'ESI failure', sprintf('/markets/%d/history/', self::THE_FORGE_REGION_ID));
    }

    private function givenPlanetaryProducts(array $tierByTypeId): void
    {
        $schematicMap = [];
        foreach (array_keys($tierByTypeId) as $position => $typeId) {
            $schematicMap[301 + $position] = ['inputs' => [91_001 => 40], 'output' => ['typeId' => $typeId, 'quantity' => 5]];
        }
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);
        $this->productionCalculator->method('findProductTier')->willReturnCallback(
            static fn (int $typeId): ?string => $tierByTypeId[$typeId] ?? null,
        );
    }

    private function recordHistorySyncs(): \Closure
    {
        $calls = [];
        $this->marketHistoryService->method('syncHistory')->willReturnCallback(
            function (int $typeId, int $regionId) use (&$calls): int {
                $calls[] = [$typeId, $regionId];

                return 1;
            },
        );

        return static function () use (&$calls): array {
            return $calls;
        };
    }

    /**
     * @param list<int> $structureIds
     */
    private function givenPreferredStructures(array $structureIds): void
    {
        $this->userRepository->method('findDistinctMarketStructureIds')->willReturn($structureIds);
    }

    private function handler(?LoggerInterface $logger = null): SyncRegionalMarketVolumesHandler
    {
        return new SyncRegionalMarketVolumesHandler(
            productRepository: $this->productRepository,
            userRepository: $this->userRepository,
            cachedStructureRepository: $this->cachedStructureRepository,
            solarSystemRepository: $this->solarSystemRepository,
            jitaMarketService: $this->jitaMarketService,
            planetaryProductTypeResolver: new PlanetaryProductTypeResolver($this->schematicTypeRepository, $this->productionCalculator),
            marketHistoryService: $this->marketHistoryService,
            syncTracker: $this->syncTracker,
            logger: $logger ?? new NullLogger(),
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
