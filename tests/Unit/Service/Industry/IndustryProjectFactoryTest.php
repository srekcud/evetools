<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\InventionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(IndustryProjectFactory::class)]
class IndustryProjectFactoryTest extends TestCase
{
    use AutowiresByType;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int QUANTUM_MICROPROCESSOR_BLUEPRINT = 11541;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int NANOTRANSISTORS = 16681;
    private const int QUANTUM_MICROPROCESSOR_RUNS = 32;
    private const int BPC_MAX_RUNS_ABOVE_THE_RUNS_OF_EVERY_STEP = 1_000;
    private const int FAVORITE_MANUFACTURING_SYSTEM = 30_002_510;
    private const int OTHER_SYSTEM = 30_000_142;
    private const string MATERIAL_EFFICIENCY_RIG_OF_BASIC_SMALL_SHIPS = 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency I';

    private IndustryTreeService&MockObject $treeService;
    private IndustryBlacklistService&MockObject $blacklistService;
    private IndustryCalculationService $calculationService;
    private CachedCharacterSkillRepository $skillRepository;
    private InvTypeRepository&MockObject $invTypeRepository;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProjectFactory $factory;
    private array $baseTimeByBlueprintAndActivity = [];
    private array $skillLevelsByCharacterName = [];
    private array $structuresOfThePilot = [];
    private ?int $favoriteManufacturingSystemId = null;
    private array $rigCategoryByGroupId = [];

    protected function setUp(): void
    {
        $this->treeService = $this->createMock(IndustryTreeService::class);
        $this->blacklistService = $this->createMock(IndustryBlacklistService::class);
        $this->skillRepository = $this->skillRepositoryOfTheCharacters();
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $activityRepository = $this->activityRepositoryWithTheBaseTimes();
        $structureConfigRepository = $this->structureConfigRepositoryOfThePilot();
        $bonusService = new IndustryBonusService(
            $this->rigCategoryRepository(),
            $structureConfigRepository,
            $this->invTypeRepository,
            $this->createStub(IndustryActivityProductRepository::class),
        );
        $this->calculationService = new IndustryCalculationService(
            $this->createStub(TypeNameResolver::class),
            $bonusService,
            $structureConfigRepository,
            $this->settingsRepositoryWithTheFavoriteManufacturingSystem(),
            $this->sdeEntityManagerWithTheBaseTimes(),
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $activityRepository,
            $this->skillRepository,
        );

        $this->factory = self::autowired(IndustryProjectFactory::class, [
            $this->treeService,
            $this->blacklistService,
            $bonusService,
            $this->calculationService,
            $this->skillRepository,
            $this->invTypeRepository,
            $this->materialRepository,
            $activityRepository,
            $this->entityManager,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->blueprintRepositoryWithBpcMaxRunsAboveTheRunsOfEveryStep(),
        ]);
    }

    private function blueprintRepositoryWithBpcMaxRunsAboveTheRunsOfEveryStep(): IndustryBlueprintRepository
    {
        $blueprintOf = static fn (int $blueprintTypeId): IndustryBlueprint => (new IndustryBlueprint())
            ->setTypeId($blueprintTypeId)
            ->setMaxProductionLimit(self::BPC_MAX_RUNS_ABOVE_THE_RUNS_OF_EVERY_STEP);

        $blueprintRepository = $this->createStub(IndustryBlueprintRepository::class);
        $blueprintRepository->method('find')->willReturnCallback(static fn (mixed $blueprintTypeId): IndustryBlueprint => $blueprintOf((int) $blueprintTypeId));
        $blueprintRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_map($blueprintOf, array_values((array) ($criteria['typeId'] ?? []))),
        );
        $blueprintRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryBlueprint => isset($criteria['typeId']) && !\is_array($criteria['typeId']) ? $blueprintOf((int) $criteria['typeId']) : null,
        );

        return $blueprintRepository;
    }

    private function activityRepositoryWithTheBaseTimes(): IndustryActivityRepository
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($activityIds as $activityId) {
                        if (isset($this->baseTimeByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId])) {
                            $activities[$blueprintTypeId.'-'.$activityId] = (new IndustryActivity())
                                ->setTypeId($blueprintTypeId)
                                ->setActivityId($activityId)
                                ->setTime($this->baseTimeByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId]);
                        }
                    }
                }

                return $activities;
            },
        );

        return $activityRepository;
    }

    private function sdeEntityManagerWithTheBaseTimes(): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            fn (string $sql, array $params): string|false => isset($this->baseTimeByBlueprintAndActivity[$params[0].'-'.$params[1]])
                ? (string) $this->baseTimeByBlueprintAndActivity[$params[0].'-'.$params[1]]
                : false,
        );
        $connection->method('fetchAllAssociative')->willReturn([]);
        $sdeEntityManager = $this->createStub(EntityManagerInterface::class);
        $sdeEntityManager->method('getConnection')->willReturn($connection);

        return $sdeEntityManager;
    }

    private function skillRepositoryOfTheCharacters(): CachedCharacterSkillRepository
    {
        $skillsOf = fn (Character $character): array => array_map(
            static fn (int $skillId, int $level): CachedCharacterSkill => (new CachedCharacterSkill())->setSkillId($skillId)->setLevel($level),
            array_keys($this->skillLevelsByCharacterName[$character->getName()]),
            array_values($this->skillLevelsByCharacterName[$character->getName()]),
        );
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findAllSkillsForCharacter')->willReturnCallback($skillsOf);
        $skillRepository->method('findIndustrySkillsForCharacter')->willReturnCallback($skillsOf);

        return $skillRepository;
    }

    private function structureConfigRepositoryOfThePilot(): IndustryStructureConfigRepository
    {
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturnCallback(fn (): array => $this->structuresOfThePilot);

        return $structureConfigRepository;
    }

    private function settingsRepositoryWithTheFavoriteManufacturingSystem(): IndustryUserSettingsRepository
    {
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturnCallback(
            fn (): ?IndustryUserSettings => $this->favoriteManufacturingSystemId === null
                ? null
                : (new IndustryUserSettings())->setFavoriteManufacturingSystemId($this->favoriteManufacturingSystemId),
        );

        return $settingsRepository;
    }

    private function rigCategoryRepository(): IndustryRigCategoryRepository
    {
        $rigCategoryRepository = $this->createStub(IndustryRigCategoryRepository::class);
        $rigCategoryRepository->method('buildGroupCategoryMap')->willReturnCallback(fn (): array => $this->rigCategoryByGroupId);

        return $rigCategoryRepository;
    }

    private function givenAProductInAGroup(int $productTypeId, int $groupId, ?string $rigCategory): void
    {
        $this->invTypeRepository->method('find')->willReturn(
            (new InvType())->setTypeId($productTypeId)->setTypeName('Product '.$productTypeId)->setGroup((new InvGroup())->setGroupId($groupId)),
        );
        $this->rigCategoryByGroupId = $rigCategory === null ? [] : [$groupId => $rigCategory];
    }

    private static function structure(string $structureType, int $solarSystemId, array $rigs = []): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setName(ucfirst($structureType).' in '.$solarSystemId)
            ->setStructureType($structureType)
            ->setSecurityType('highsec')
            ->setSolarSystemId($solarSystemId)
            ->setRigs($rigs);
    }

    private function createUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getCharacters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        return $user;
    }

    private function userWithCharacters(array $skillLevelsByCharacterName): User
    {
        $this->skillLevelsByCharacterName = $skillLevelsByCharacterName;
        $user = new User();
        foreach (array_keys($skillLevelsByCharacterName) as $characterName) {
            $user->addCharacter((new Character())->setName($characterName));
        }

        return $user;
    }

    private function runsAndSplitTotalOfEachStep(IndustryProject $project, string $activityType): array
    {
        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getTotalGroupRuns()],
            $this->stepsOfActivity($project, $activityType),
        );
    }

    private function displayedJobsOf(IndustryProject $project, string $activityType): array
    {
        $mapper = self::autowired(IndustryResourceMapper::class, [
            $this->calculationService,
            $this->skillRepository,
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
        ]);

        return array_map(
            static function (IndustryProjectStep $step) use ($mapper): array {
                $displayedStep = $mapper->stepToResource($step);

                return [$step->getRuns(), $step->getTotalGroupRuns(), $displayedStep->timePerRun, $displayedStep->recommendedCharacterName];
            },
            $this->stepsOfActivity($project, $activityType),
        );
    }

    private function stepsOfActivity(IndustryProject $project, string $activityType): array
    {
        return array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getActivityType() === $activityType,
        ));
    }

    /**
     * Build a simple flat production tree node (T1 item, no sub-components).
     *
     * @param list<array{typeId: int, typeName: string, quantity: int}> $rawMaterials
     */
    private function buildFlatTreeNode(
        int $blueprintTypeId,
        int $productTypeId,
        string $productName,
        int $runs,
        int $outputPerRun = 1,
        array $rawMaterials = [],
        int $depth = 0,
        string $activityType = 'manufacturing',
        bool $hasCopy = false,
    ): array {
        $materials = [];
        foreach ($rawMaterials as $mat) {
            $materials[] = [
                'typeId' => $mat['typeId'],
                'typeName' => $mat['typeName'],
                'quantity' => $mat['quantity'],
                'isBuildable' => false,
                'activityType' => null,
            ];
        }

        return [
            'blueprintTypeId' => $blueprintTypeId,
            'productTypeId' => $productTypeId,
            'productTypeName' => $productName,
            'quantity' => $runs * $outputPerRun,
            'runs' => $runs,
            'outputPerRun' => $outputPerRun,
            'depth' => $depth,
            'activityType' => $activityType,
            'hasCopy' => $hasCopy,
            'materials' => $materials,
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];
    }

    private function setupDefaultMocks(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
    }

    // ===========================================
    // createProject() - basic T1 creation
    // ===========================================

    public function testCreateProjectBasicT1(): void
    {
        $user = $this->createUser();
        $productTypeId = 587; // Rifter
        $blueprintTypeId = 586;

        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->with($productTypeId)->willReturn($invType);

        $tree = $this->buildFlatTreeNode($blueprintTypeId, $productTypeId, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $project = $this->factory->createProject($user, $productTypeId, 10, 10, 2.0, 20, 'Test Rifter');

        $this->assertSame($productTypeId, $project->getProductTypeId());
        $this->assertSame(10, $project->getRuns());
        $this->assertSame(10, $project->getMeLevel());
        $this->assertSame(20, $project->getTeLevel());
        $this->assertSame(2.0, $project->getMaxJobDurationDays());
        $this->assertSame('Test Rifter', $project->getName());
        $this->assertSame($user, $project->getUser());

        // Should have 1 manufacturing step
        $steps = $project->getSteps();
        $this->assertCount(1, $steps);

        $step = $steps->first();
        $this->assertSame($blueprintTypeId, $step->getBlueprintTypeId());
        $this->assertSame($productTypeId, $step->getProductTypeId());
        $this->assertSame('manufacturing', $step->getActivityType());
        $this->assertSame(10, $step->getRuns());
        $this->assertSame(0, $step->getDepth());
    }

    public function testCreateProjectThrowsForUnknownTypeId(): void
    {
        $user = $this->createUser();

        $this->invTypeRepository->method('find')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown type ID 99999');

        $this->factory->createProject($user, 99999, 1, 0);
    }

    public function testCreateProjectWithEmptyNameStoresNull(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();

        $project = $this->factory->createProject($user, 587, 1, 0, 2.0, 0, '');

        $this->assertNull($project->getName());
    }

    // ===========================================
    // collectStepsFromTree() - ME/TE assignment
    // ===========================================

    public function testStepMeTeForDepthZeroUsesProjectValues(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();

        $project = $this->factory->createProject($user, 587, 5, 8, 2.0, 16);

        $step = $project->getSteps()->first();
        $this->assertSame(8, $step->getMeLevel());
        $this->assertSame(16, $step->getTeLevel());
    }

    public function testStepMeTeForDeepStepsUsesDefaults(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        // Tree with a buildable sub-component at depth 1
        $subComponent = $this->buildFlatTreeNode(1000, 1001, 'Component', 10, 1, [], 1);
        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5);
        $tree['materials'] = [
            [
                'typeId' => 1001,
                'typeName' => 'Component',
                'quantity' => 50,
                'isBuildable' => true,
                'blueprint' => $subComponent,
            ],
        ];
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();

        $project = $this->factory->createProject($user, 587, 5, 2, 2.0, 4);

        // Find the depth-1 step
        $deepStep = null;
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 1) {
                $deepStep = $step;
                break;
            }
        }

        $this->assertNotNull($deepStep);
        // Intermediate components default to ME 10 / TE 20
        $this->assertSame(10, $deepStep->getMeLevel());
        $this->assertSame(20, $deepStep->getTeLevel());
    }

    public function testReactionStepHasZeroMeTe(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        // Build a tree with a reaction at depth 1
        $reactionNode = $this->buildFlatTreeNode(2000, 2001, 'Fullerite', 10, 200, [], 1, 'reaction');
        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5);
        $tree['materials'] = [
            [
                'typeId' => 2001,
                'typeName' => 'Fullerite',
                'quantity' => 1000,
                'isBuildable' => true,
                'blueprint' => $reactionNode,
            ],
        ];
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();

        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);

        $project = $this->factory->createProject($user, 587, 5, 10, 2.0, 20);

        $reactionStep = null;
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'reaction') {
                $reactionStep = $step;
                break;
            }
        }

        $this->assertNotNull($reactionStep);
        $this->assertSame(0, $reactionStep->getMeLevel());
        $this->assertSame(0, $reactionStep->getTeLevel());
    }

    // ===========================================
    // collectStepsFromTree() - consolidation
    // ===========================================

    public function testDuplicateStepsAreConsolidated(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        // Two different branches both require the same component
        $component1 = $this->buildFlatTreeNode(1000, 1001, 'Component', 5, 1, [], 1);
        $component2 = $this->buildFlatTreeNode(1000, 1001, 'Component', 3, 1, [], 1);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5);
        $tree['materials'] = [
            [
                'typeId' => 1001,
                'typeName' => 'Component',
                'quantity' => 5,
                'isBuildable' => true,
                'blueprint' => $component1,
            ],
            [
                'typeId' => 1001,
                'typeName' => 'Component',
                'quantity' => 3,
                'isBuildable' => true,
                'blueprint' => $component2,
            ],
        ];
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);

        $project = $this->factory->createProject($user, 587, 5, 10, 2.0, 20);

        // Should have 2 steps: 1 root + 1 consolidated component
        $componentSteps = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 1) {
                $componentSteps[] = $step;
            }
        }

        $this->assertCount(1, $componentSteps);
        // 5 + 3 = 8 quantity, 8 runs (outputPerRun=1)
        $this->assertSame(8, $componentSteps[0]->getQuantity());
        $this->assertSame(8, $componentSteps[0]->getRuns());
    }

    public function testMergedStepOfAProductNeededByTwoConsumersRoundsItsRunsUpOnTheMergedQuantity(): void
    {
        $user = $this->createUser();
        $this->invTypeRepository->method('find')->willReturn($this->createMock(InvType::class));
        $firstNeed = $this->buildFlatTreeNode(4313, 4312, 'Oxygen Fuel Block', 2, 40, [], 1);
        $firstNeed['quantity'] = 50;
        $secondNeed = $this->buildFlatTreeNode(4313, 4312, 'Oxygen Fuel Block', 1, 40, [], 1);
        $tree = $this->buildFlatTreeNode(28607, 28606, 'Orca', 1);
        $tree['materials'] = [
            ['typeId' => 4312, 'typeName' => 'Oxygen Fuel Block', 'quantity' => 50, 'isBuildable' => true, 'blueprint' => $firstNeed],
            ['typeId' => 4312, 'typeName' => 'Oxygen Fuel Block', 'quantity' => 40, 'isBuildable' => true, 'blueprint' => $secondNeed],
        ];
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);

        $project = $this->factory->createProject($user, 28606, 1, 10, 2.0, 20);

        $fuelBlockSteps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === 4312,
        ));
        $this->assertSame([[90, 3]], array_map(
            static fn (IndustryProjectStep $step): array => [$step->getQuantity(), $step->getRuns()],
            $fuelBlockSteps,
        ));
    }

    // ===========================================
    // Copy steps
    // ===========================================

    public function testCopyStepCreatedWhenHasCopyIsTrue(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(586, 587, 'Sabre', 10, 1, [], 0, 'manufacturing', true);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();

        $project = $this->factory->createProject($user, 587, 10, 2, 2.0, 4);

        $copyStep = null;
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                $copyStep = $step;
                break;
            }
        }

        $this->assertNotNull($copyStep, 'Copy step should be created when hasCopy is true');
        $this->assertSame(10, $copyStep->getRuns());
        $this->assertSame(0, $copyStep->getMeLevel());
        $this->assertSame(0, $copyStep->getTeLevel());
    }

    // ===========================================
    // splitLongJobs()
    // ===========================================

    public function testLongJobsAreSplitByMaxDuration(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 100);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->baseTimeByBlueprintAndActivity = ['586-1' => 50000];

        $project = $this->factory->createProject($user, 587, 100, 10, 1.0, 20);

        $splitSteps = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getSplitGroupId() !== null) {
                $splitSteps[] = $step;
            }
        }

        $this->assertGreaterThan(1, count($splitSteps), 'Job should be split into multiple steps');

        $totalRuns = 0;
        foreach ($splitSteps as $step) {
            $totalRuns += $step->getRuns();
        }
        $this->assertSame(100, $totalRuns);
    }

    public function testShortJobsAreNotSplit(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();
        $this->baseTimeByBlueprintAndActivity = ['586-1' => 3600];

        $project = $this->factory->createProject($user, 587, 5, 10, 2.0, 20);

        foreach ($project->getSteps() as $step) {
            $this->assertNull($step->getSplitGroupId(), 'Short jobs should not be split');
        }
    }

    public function testCopyStepLongerThanTheMaximumDurationIsNeverSplit(): void
    {
        $user = $this->createUser();
        $this->invTypeRepository->method('find')->willReturn($this->createMock(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturn(
            $this->buildFlatTreeNode(22457, 22456, 'Sabre', 12, 1, [], 0, 'manufacturing', true),
        );
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->baseTimeByBlueprintAndActivity = ['22457-5' => 48_000, '22457-1' => 1_200];

        $project = $this->factory->createProject($user, 22456, 12, 10, 1.0, 20);

        $stepsByActivity = [];
        foreach ($project->getSteps() as $step) {
            $stepsByActivity[$step->getActivityType()][] = [$step->getRuns(), $step->getQuantity(), $step->getSplitGroupId(), $step->getSplitIndex(), $step->getTotalGroupRuns()];
        }
        $this->assertSame(
            ['copy' => [[12, 12, null, 0, null]], 'manufacturing' => [[12, 12, null, 0, null]]],
            $stepsByActivity,
        );
    }

    #[Group('issue-165')]
    public function testIndustryFiveAndAdvancedIndustryFiveCutTheTimePerRunThatDecidesTheSplit(): void
    {
        $user = $this->userWithCharacters(['TestChar' => [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            fn (int $productTypeId, int $runs): array => $this->buildFlatTreeNode(586, 587, 'Rifter', $runs),
        );
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->baseTimeByBlueprintAndActivity = ['586-1' => 10000];

        $twentyFiveRuns = $this->factory->createProject($user, 587, 25, 10, 2.0, 0);
        $twentySixRuns = $this->factory->createProject($user, 587, 26, 10, 2.0, 0);

        $this->assertSame([[25, null]], $this->runsAndSplitTotalOfEachStep($twentyFiveRuns, 'manufacturing'));
        $this->assertSame([[13, 26], [13, 26]], $this->runsAndSplitTotalOfEachStep($twentySixRuns, 'manufacturing'));
    }

    #[Group('issue-165')]
    public function testReactionsFourCutsTheTimePerRunThatDecidesTheSplit(): void
    {
        $user = $this->userWithCharacters(['ReactChar' => [CachedCharacterSkill::SKILL_REACTIONS => 4]]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            fn (int $productTypeId, int $runs): array => $this->buildFlatTreeNode(2000, 2001, 'Reaction Product', $runs, 200, [], 0, 'reaction'),
        );
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->baseTimeByBlueprintAndActivity = ['2000-11' => 3600];
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);

        $fiftySevenRuns = $this->factory->createProject($user, 2001, 57, 0, 2.0, 0);
        $fiftyEightRuns = $this->factory->createProject($user, 2001, 58, 0, 2.0, 0);

        $this->assertSame([[57, null]], $this->runsAndSplitTotalOfEachStep($fiftySevenRuns, 'reaction'));
        $this->assertSame([[29, 58], [29, 58]], $this->runsAndSplitTotalOfEachStep($fiftyEightRuns, 'reaction'));
        $this->assertSame([0, 0], array_map(static fn (IndustryProjectStep $step): int => $step->getMeLevel(), $fiftyEightRuns->getSteps()->toArray()));
    }

    #[Group('issue-165')]
    public function testSplitUsesTheBestCharacterOfTheUser(): void
    {
        $user = $this->userWithCharacters([
            'Alt' => [],
            'Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 5],
            'Trainee' => [CachedCharacterSkill::SKILL_INDUSTRY => 1],
        ]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            fn (int $productTypeId, int $runs): array => $this->buildFlatTreeNode(586, 587, 'Rifter', $runs),
        );
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->baseTimeByBlueprintAndActivity = ['586-1' => 10000];

        $twentyOneRuns = $this->factory->createProject($user, 587, 21, 10, 2.0, 0);
        $twentyTwoRuns = $this->factory->createProject($user, 587, 22, 10, 2.0, 0);

        $this->assertSame([[21, null]], $this->runsAndSplitTotalOfEachStep($twentyOneRuns, 'manufacturing'));
        $this->assertSame([[11, 22], [11, 22]], $this->runsAndSplitTotalOfEachStep($twentyTwoRuns, 'manufacturing'));
    }

    #[Group('issue-165')]
    public function testStepWithoutBaseTimeInTheSdeIsNeverSplitWhateverItsRuns(): void
    {
        $user = $this->createUser();
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturn($this->buildFlatTreeNode(586, 587, 'Rifter', 1000));
        $this->setupDefaultMocks();

        $project = $this->factory->createProject($user, 587, 1000, 10, 2.0, 0);

        $this->assertSame([[1000, null]], $this->runsAndSplitTotalOfEachStep($project, 'manufacturing'));
    }

    #[Group('issue-134')]
    public function testRunsWhoseJobFillsExactlyTheMaximumDurationWithTheDisplayedTimePerRunStayInOneJob(): void
    {
        $user = $this->userWithCharacters(['Industrialist' => [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturn($this->buildFlatTreeNode(586, 587, 'Rifter', 14400));
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->baseTimeByBlueprintAndActivity = ['586-1' => 16003];

        $project = $this->factory->createProject($user, 587, 14400, 10, 1451.0, 20);

        $this->assertSame([[14400, null, 8706, 'Industrialist']], $this->displayedJobsOf($project, 'manufacturing'));
    }

    #[Group('issue-134')]
    public function testSplitTakesTheTimeBonusOfTheRaitaruOfTheFavoriteSystemRatherThanOfTheFasterAzbelElsewhere(): void
    {
        $this->givenAProductInAGroup(36002, 3600, 'basic_large_ship');
        $this->structuresOfThePilot = [
            self::structure('raitaru', self::FAVORITE_MANUFACTURING_SYSTEM),
            self::structure('azbel', self::OTHER_SYSTEM),
        ];
        $this->favoriteManufacturingSystemId = self::FAVORITE_MANUFACTURING_SYSTEM;
        $this->treeService->method('buildProductionTree')->willReturn($this->buildFlatTreeNode(36001, 36002, 'Favorite System Product', 21));
        $this->setupDefaultMocks();
        $this->baseTimeByBlueprintAndActivity = ['36001-1' => 10000];

        $project = $this->factory->createProject($this->userWithCharacters([]), 36002, 21, 10, 2.0, 0);

        $this->assertSame([[11, 21, 8500, null], [10, 21, 8500, null]], $this->displayedJobsOf($project, 'manufacturing'));
    }

    #[Group('issue-134')]
    public function testSplitOfAProductWithoutRigCategoryTakesTheRoleTimeBonusOfTheStructure(): void
    {
        $this->givenAProductInAGroup(37002, 3700, null);
        $this->structuresOfThePilot = [self::structure('raitaru', self::OTHER_SYSTEM)];
        $this->treeService->method('buildProductionTree')->willReturn($this->buildFlatTreeNode(37001, 37002, 'Deployable', 18));
        $this->setupDefaultMocks();
        $this->baseTimeByBlueprintAndActivity = ['37001-1' => 10000];

        $project = $this->factory->createProject($this->userWithCharacters([]), 37002, 18, 10, 2.0, 0);

        $this->assertSame([[18, null, 8500, null]], $this->displayedJobsOf($project, 'manufacturing'));
    }

    #[Group('issue-134')]
    #[Group('issue-126')]
    #[DataProvider('generatedJobsOfTwoProjectsAndTheirDisplayedTimePerRun')]
    public function testEveryGeneratedJobDisplaysTheTimePerRunThatDecidedItsSplit(
        int $blueprintTypeId,
        int $productTypeId,
        int $groupId,
        ?string $rigCategory,
        array $structures,
        ?int $favoriteManufacturingSystemId,
        array $skillLevelsByCharacterName,
        array $baseTimeByActivityId,
        int $teLevel,
        bool $hasCopy,
        array $runsOfEachProject,
        array $expectedDisplayedJobsOfEachProject,
    ): void {
        $this->givenAProductInAGroup($productTypeId, $groupId, $rigCategory);
        $this->structuresOfThePilot = $structures;
        $this->favoriteManufacturingSystemId = $favoriteManufacturingSystemId;
        foreach ($baseTimeByActivityId as $activityId => $baseTime) {
            $this->baseTimeByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId] = $baseTime;
        }
        $user = $this->userWithCharacters($skillLevelsByCharacterName);
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            fn (int $requestedProductTypeId, int $runs): array => $this->buildFlatTreeNode($blueprintTypeId, $productTypeId, 'Product '.$productTypeId, $runs, 1, [], 0, 'manufacturing', $hasCopy),
        );
        $this->setupDefaultMocks();

        $displayedJobsOfEachProject = [];
        foreach ($runsOfEachProject as $runs) {
            $project = $this->factory->createProject($user, $productTypeId, $runs, 10, 2.0, $teLevel);
            $displayedJobsOfEachProject[] = [
                'manufacturing' => $this->displayedJobsOf($project, 'manufacturing'),
                'copy' => $this->displayedJobsOf($project, 'copy'),
            ];
        }

        $this->assertSame($expectedDisplayedJobsOfEachProject, $displayedJobsOfEachProject);
    }

    public static function generatedJobsOfTwoProjectsAndTheirDisplayedTimePerRun(): iterable
    {
        yield 'Raitaru of the favorite system before a faster Azbel elsewhere, character with Industry IV' => [
            31001, 31002, 3100, 'basic_medium_ship',
            [self::structure('raitaru', self::FAVORITE_MANUFACTURING_SYSTEM), self::structure('azbel', self::OTHER_SYSTEM)],
            self::FAVORITE_MANUFACTURING_SYSTEM,
            ['Industrialist' => [CachedCharacterSkill::SKILL_INDUSTRY => 4]],
            [1 => 10000],
            0,
            false,
            [24, 25],
            [
                ['manufacturing' => [[24, null, 7140, 'Industrialist']], 'copy' => []],
                ['manufacturing' => [[13, 25, 7140, 'Industrialist'], [12, 25, 7140, 'Industrialist']], 'copy' => []],
            ],
        ];
        yield 'best structure by material bonus without favorite system, before a faster Azbel' => [
            32001, 32002, 3200, 'basic_small_ship',
            [self::structure('azbel', self::OTHER_SYSTEM), self::structure('raitaru', self::OTHER_SYSTEM, [self::MATERIAL_EFFICIENCY_RIG_OF_BASIC_SMALL_SHIPS])],
            null,
            [],
            [1 => 11000],
            0,
            false,
            [18, 19],
            [
                ['manufacturing' => [[18, null, 9350, null]], 'copy' => []],
                ['manufacturing' => [[10, 19, 9350, null], [9, 19, 9350, null]], 'copy' => []],
            ],
        ];
        yield 'role time bonus of the only structure for a product without rig category, at TE 10' => [
            33001, 33002, 3300, null,
            [self::structure('raitaru', self::OTHER_SYSTEM)],
            null,
            [],
            [1 => 12000],
            10,
            false,
            [18, 19],
            [
                ['manufacturing' => [[18, null, 9180, null]], 'copy' => []],
                ['manufacturing' => [[10, 19, 9180, null], [9, 19, 9180, null]], 'copy' => []],
            ],
        ];
        yield 'copy of the blueprint, never split, without character and with its copying base time' => [
            34001, 34002, 3400, 'basic_small_ship',
            [],
            null,
            ['Industrialist' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5]],
            [1 => 19000, 5 => 14100],
            0,
            true,
            [13, 14],
            [
                ['manufacturing' => [[13, null, 12921, 'Industrialist']], 'copy' => [[13, null, 14100, null]]],
                ['manufacturing' => [[7, 14, 12921, 'Industrialist'], [7, 14, 12921, 'Industrialist']], 'copy' => [[14, null, 14100, null]]],
            ],
        ];
    }

    #[Group('issue-134')]
    public function testDisplayedTimePerRunIsThatOfTheFirstCharacterAmongThoseWithTheLowestSkillTimeMultiplier(): void
    {
        $user = $this->userWithCharacters([
            'Trainee' => [CachedCharacterSkill::SKILL_INDUSTRY => 1],
            'Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 5],
            'Twin' => [CachedCharacterSkill::SKILL_INDUSTRY => 5],
        ]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturn($this->buildFlatTreeNode(35001, 35002, 'Twin Product', 3));
        $this->setupDefaultMocks();
        $this->baseTimeByBlueprintAndActivity = ['35001-1' => 13000];

        $project = $this->factory->createProject($user, 35002, 3, 10, 2.0, 0);

        $this->assertSame([[3, null, 10400, 'Main']], $this->displayedJobsOf($project, 'manufacturing'));
    }

    // ===========================================
    // Step sorting: deepest first, then by activity type
    // ===========================================

    public function testStepsAreSortedDeepestFirst(): void
    {
        $user = $this->createUser();
        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        // Tree with depth 0 and depth 1
        $subComponent = $this->buildFlatTreeNode(1000, 1001, 'Sub Component', 10, 1, [], 1);
        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5);
        $tree['materials'] = [
            [
                'typeId' => 1001,
                'typeName' => 'Sub Component',
                'quantity' => 10,
                'isBuildable' => true,
                'blueprint' => $subComponent,
            ],
        ];
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->setupDefaultMocks();
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);

        $project = $this->factory->createProject($user, 587, 5, 10, 2.0, 20);

        $steps = $project->getSteps()->toArray();
        $this->assertCount(2, $steps);

        // First step should be deeper (depth 1), second should be root (depth 0)
        $this->assertSame(1, $steps[0]->getDepth());
        $this->assertSame(0, $steps[1]->getDepth());
    }

    public function testCreatedProjectRemembersItsExcludedComponentsOnceWithoutTheBlacklist(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([self::NANOTRANSISTORS]);
        $this->capturesTheExclusionsOfTheTree();

        $project = $this->factory->createProject($this->createUser(), self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR_RUNS, 10, 2.0, 20, null, [self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES, self::TITANIUM_CARBIDE]);

        $this->assertTrue(array_is_list($project->getExcludedTypeIds()));
        $this->assertEqualsCanonicalizing([self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES], $project->getExcludedTypeIds());
    }

    public function testCreatedProjectRemembersItsExcludedComponentsInTheirFirstOrderAsAList(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([self::NANOTRANSISTORS]);
        $this->capturesTheExclusionsOfTheTree();

        $project = $this->factory->createProject($this->createUser(), self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR_RUNS, 10, 2.0, 20, null, [self::PHENOLIC_COMPOSITES, self::PHENOLIC_COMPOSITES, self::TITANIUM_CARBIDE]);

        $this->assertSame([self::PHENOLIC_COMPOSITES, self::TITANIUM_CARBIDE], $project->getExcludedTypeIds());
    }

    public function testCreatedProjectTreeExcludesTheBlacklistAndTheExcludedComponents(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([self::NANOTRANSISTORS]);
        $exclusionsOfTheTree = $this->capturesTheExclusionsOfTheTree();

        $this->factory->createProject($this->createUser(), self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR_RUNS, 10, 2.0, 20, null, [self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES, self::TITANIUM_CARBIDE]);

        $this->assertSame([self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES, self::NANOTRANSISTORS], $this->sorted($exclusionsOfTheTree->getArrayCopy()));
    }

    public function testExcludedComponentAlsoBlacklistedIsExcludedOnceFromTheTree(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([self::NANOTRANSISTORS]);
        $exclusionsOfTheTree = $this->capturesTheExclusionsOfTheTree();

        $project = $this->factory->createProject($this->createUser(), self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR_RUNS, 10, 2.0, 20, null, [self::NANOTRANSISTORS, self::PHENOLIC_COMPOSITES]);

        $this->assertSame([self::PHENOLIC_COMPOSITES, self::NANOTRANSISTORS], $this->sorted($exclusionsOfTheTree->getArrayCopy()));
        $this->assertEqualsCanonicalizing([self::NANOTRANSISTORS, self::PHENOLIC_COMPOSITES], $project->getExcludedTypeIds());
    }

    public function testCreatedProjectWithoutExcludedComponentRemembersNone(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([self::NANOTRANSISTORS]);
        $exclusionsOfTheTree = $this->capturesTheExclusionsOfTheTree();

        $project = $this->factory->createProject($this->createUser(), self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR_RUNS, 10, 2.0, 20);

        $this->assertSame([], $project->getExcludedTypeIds());
        $this->assertSame([self::NANOTRANSISTORS], $exclusionsOfTheTree->getArrayCopy());
    }

    public function testRegeneratedStepsExcludeTheBlacklistAndTheExcludedComponentsOfTheProject(): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([self::NANOTRANSISTORS]);
        $exclusionsOfTheTree = $this->capturesTheExclusionsOfTheTree();
        $project = (new IndustryProject())
            ->setUser($this->createUser())
            ->setProductTypeId(self::QUANTUM_MICROPROCESSOR)
            ->setRuns(self::QUANTUM_MICROPROCESSOR_RUNS)
            ->setMeLevel(10)
            ->setTeLevel(20)
            ->setMaxJobDurationDays(2.0)
            ->setExcludedTypeIds([self::TITANIUM_CARBIDE]);

        $this->factory->regenerateSteps($project);

        $this->assertSame([self::TITANIUM_CARBIDE, self::NANOTRANSISTORS], $this->sorted($exclusionsOfTheTree->getArrayCopy()));
    }

    private function capturesTheExclusionsOfTheTree(): \ArrayObject
    {
        $exclusionsOfTheTree = new \ArrayObject();
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            function (int $productTypeId, int $runs, int $meLevel, array $excludedTypeIds) use ($exclusionsOfTheTree): array {
                $exclusionsOfTheTree->exchangeArray($excludedTypeIds);

                return $this->buildFlatTreeNode(self::QUANTUM_MICROPROCESSOR_BLUEPRINT, $productTypeId, 'Quantum Microprocessor', $runs);
            },
        );
        $this->setupDefaultMocks();

        return $exclusionsOfTheTree;
    }

    private function sorted(array $typeIds): array
    {
        $sortedTypeIds = array_values($typeIds);
        sort($sortedTypeIds);

        return $sortedTypeIds;
    }
}
