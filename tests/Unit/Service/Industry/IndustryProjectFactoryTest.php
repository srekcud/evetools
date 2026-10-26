<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
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
use App\Service\Mercure\MercurePublisherService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(IndustryProjectFactory::class)]
class IndustryProjectFactoryTest extends TestCase
{
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int QUANTUM_MICROPROCESSOR_BLUEPRINT = 11541;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int NANOTRANSISTORS = 16681;
    private const int QUANTUM_MICROPROCESSOR_RUNS = 32;
    private const int BPC_MAX_RUNS_ABOVE_THE_RUNS_OF_EVERY_STEP = 1_000;

    private IndustryTreeService&MockObject $treeService;
    private IndustryBlacklistService&MockObject $blacklistService;
    private IndustryBonusService&MockObject $bonusService;
    private IndustryCalculationService&MockObject $calculationService;
    private CachedCharacterSkillRepository&MockObject $skillRepository;
    private InvTypeRepository&MockObject $invTypeRepository;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private IndustryActivityRepository&MockObject $activityRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MercurePublisherService $mercurePublisher;
    private IndustryProjectFactory $factory;

    protected function setUp(): void
    {
        $this->treeService = $this->createMock(IndustryTreeService::class);
        $this->blacklistService = $this->createMock(IndustryBlacklistService::class);
        $this->bonusService = $this->createMock(IndustryBonusService::class);
        $this->calculationService = $this->createMock(IndustryCalculationService::class);
        $this->skillRepository = $this->createMock(CachedCharacterSkillRepository::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->activityRepository = $this->createMock(IndustryActivityRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mercurePublisher = new MercurePublisherService(
            $this->createMock(HubInterface::class),
            new NullLogger(),
        );

        $this->factory = new IndustryProjectFactory(
            $this->treeService,
            $this->blacklistService,
            $this->bonusService,
            $this->calculationService,
            $this->skillRepository,
            $this->invTypeRepository,
            $this->materialRepository,
            $this->activityRepository,
            $this->entityManager,
            $this->mercurePublisher,
            $this->blueprintRepositoryWithBpcMaxRunsAboveTheRunsOfEveryStep(),
        );
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

    // ===========================================
    // Helper methods
    // ===========================================

    private function createUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getCharacters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        return $user;
    }

    private function createUserWithCharacter(string $charName, array $skills): User&MockObject
    {
        $character = $this->createMock(Character::class);
        $character->method('getName')->willReturn($charName);

        $user = $this->createMock(User::class);
        $user->method('getCharacters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$character]));

        $cachedSkills = [];
        foreach ($skills as $skillId => $level) {
            $skill = $this->createMock(CachedCharacterSkill::class);
            $skill->method('getSkillId')->willReturn($skillId);
            $skill->method('getLevel')->willReturn($level);
            $cachedSkills[] = $skill;
        }

        $this->skillRepository->method('findAllSkillsForCharacter')
            ->with($character)
            ->willReturn($cachedSkills);

        return $user;
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
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([]);
        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn([
            'bonus' => 0.0,
            'structure' => null,
        ]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturnCallback(
            fn (int $base, int $te, float $structureBonus) => $base,
        );
        $this->calculationService->method('getBlueprintScienceSkillIds')->willReturn([]);
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

        // Reactions need bonus lookup for recalculate
        $this->bonusService->method('getCategoryForProduct')->willReturn(null);
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
        $this->calculationService->method('getBlueprintScienceSkillIds')->willReturn([]);

        // Each run takes 50000 seconds (~13.8 hours)
        // Max duration: 1 day = 86400 seconds
        // maxRunsPerJob = floor(86400 / 50000) = 1
        // So 100 runs should be split into 100 jobs of 1 run each
        $activity = $this->createMock(IndustryActivity::class);
        $activity->method('getTime')->willReturn(50000);
        $activity->method('getTypeId')->willReturn(586);
        $activity->method('getActivityId')->willReturn(1);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([
            '586-1' => $activity,
        ]);

        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn([
            'bonus' => 0.0,
            'structure' => null,
        ]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturnCallback(
            fn (int $base, int $te, float $structureBonus) => $base,
        );

        $project = $this->factory->createProject($user, 587, 100, 10, 1.0, 20);

        // All steps should have splitGroupId set
        $splitSteps = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getSplitGroupId() !== null) {
                $splitSteps[] = $step;
            }
        }

        $this->assertGreaterThan(1, count($splitSteps), 'Job should be split into multiple steps');

        // Total runs across splits should equal 100
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

        // Each run takes 3600 seconds (1 hour), 5 runs = 5 hours < 2 days
        $activity = $this->createMock(IndustryActivity::class);
        $activity->method('getTime')->willReturn(3600);
        $activity->method('getTypeId')->willReturn(586);
        $activity->method('getActivityId')->willReturn(1);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([
            '586-1' => $activity,
        ]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturnCallback(
            fn (int $base, int $te, float $structureBonus) => $base,
        );

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
        $this->calculationService->method('getBlueprintScienceSkillIds')->willReturn([]);
        $copyActivity = $this->createMock(IndustryActivity::class);
        $copyActivity->method('getTime')->willReturn(48_000);
        $manufacturingActivity = $this->createMock(IndustryActivity::class);
        $manufacturingActivity->method('getTime')->willReturn(1_200);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([
            '22457-5' => $copyActivity,
            '22457-1' => $manufacturingActivity,
        ]);
        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn(['bonus' => 0.0, 'structure' => null]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturnCallback(
            fn (int $base, int $te, float $structureBonus) => $base,
        );

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

    // ===========================================
    // Skill multiplier calculation
    // ===========================================

    #[Group('issue-165')]
    public function testIndustryFiveAndAdvancedIndustryFiveCutTheTimePerRunThatDecidesTheSplit(): void
    {
        $user = $this->createUserWithCharacter('TestChar', [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            fn (int $productTypeId, int $runs): array => $this->buildFlatTreeNode(586, 587, 'Rifter', $runs),
        );
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $activity = (new IndustryActivity())->setTypeId(586)->setActivityId(1)->setTime(10000);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn(['586-1' => $activity]);
        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn(['bonus' => 0.0, 'structure' => null]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturn(10000);
        $factory = $this->factoryWithTheSkillTimeMultiplierOfIndustryCalculation();

        $twentyFiveRuns = $factory->createProject($user, 587, 25, 10, 2.0, 0);
        $twentySixRuns = $factory->createProject($user, 587, 26, 10, 2.0, 0);

        $this->assertSame([[25, null]], $this->runsAndSplitTotalOfEachStep($twentyFiveRuns, 'manufacturing'));
        $this->assertSame([[13, 26], [13, 26]], $this->runsAndSplitTotalOfEachStep($twentySixRuns, 'manufacturing'));
    }

    #[Group('issue-165')]
    public function testReactionsFourCutsTheTimePerRunThatDecidesTheSplit(): void
    {
        $user = $this->createUserWithCharacter('ReactChar', [CachedCharacterSkill::SKILL_REACTIONS => 4]);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturnCallback(
            fn (int $productTypeId, int $runs): array => $this->buildFlatTreeNode(2000, 2001, 'Reaction Product', $runs, 200, [], 0, 'reaction'),
        );
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $activity = (new IndustryActivity())->setTypeId(2000)->setActivityId(11)->setTime(3600);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn(['2000-11' => $activity]);
        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn(['bonus' => 0.0, 'structure' => null]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturn(3600);
        $this->bonusService->method('getCategoryForProduct')->willReturn(null);
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);
        $factory = $this->factoryWithTheSkillTimeMultiplierOfIndustryCalculation();

        $fiftySevenRuns = $factory->createProject($user, 2001, 57, 0, 2.0, 0);
        $fiftyEightRuns = $factory->createProject($user, 2001, 58, 0, 2.0, 0);

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
        $activity = (new IndustryActivity())->setTypeId(586)->setActivityId(1)->setTime(10000);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn(['586-1' => $activity]);
        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn(['bonus' => 0.0, 'structure' => null]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturn(10000);
        $factory = $this->factoryWithTheSkillTimeMultiplierOfIndustryCalculation();

        $twentyOneRuns = $factory->createProject($user, 587, 21, 10, 2.0, 0);
        $twentyTwoRuns = $factory->createProject($user, 587, 22, 10, 2.0, 0);

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
    public function testSplitCurrentlyUsesATimePerRunOneSecondLongerThanTheTimePerRunOfIndustryCalculationIssue134(): void
    {
        $skills = [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5];
        $user = $this->createUserWithCharacter('TestChar', $skills);
        $this->invTypeRepository->method('find')->willReturn($this->createStub(InvType::class));
        $this->treeService->method('buildProductionTree')->willReturn($this->buildFlatTreeNode(586, 587, 'Rifter', 14400));
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $activity = (new IndustryActivity())->setTypeId(586)->setActivityId(1)->setTime(16003);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn(['586-1' => $activity]);
        $bonusService = new IndustryBonusService(
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
        $calculationService = $this->calculationServiceWithBaseTimePerRun($bonusService, 16003);
        $factory = $this->factoryWithTheSkillTimeMultiplierOfIndustryCalculation($bonusService, $calculationService);

        $project = $factory->createProject($user, 587, 14400, 10, 1451.0, 20);

        $this->assertSame([[7200, 14400], [7200, 14400]], $this->runsAndSplitTotalOfEachStep($project, 'manufacturing'));
        $this->assertSame(8706, $calculationService->calculateTimePerRun($project->getSteps()->first(), $skills));
    }

    private function factoryWithTheSkillTimeMultiplierOfIndustryCalculation(?IndustryBonusService $bonusService = null, ?IndustryCalculationService $calculationService = null): IndustryProjectFactory
    {
        $bonusService ??= $this->bonusService;

        return new IndustryProjectFactory(
            $this->treeService,
            $this->blacklistService,
            $bonusService,
            $calculationService ?? $this->calculationServiceWithBaseTimePerRun($bonusService, null),
            $this->skillRepository,
            $this->invTypeRepository,
            $this->materialRepository,
            $this->activityRepository,
            $this->entityManager,
            $this->mercurePublisher,
            $this->blueprintRepositoryWithBpcMaxRunsAboveTheRunsOfEveryStep(),
        );
    }

    private function calculationServiceWithBaseTimePerRun(IndustryBonusService $bonusService, ?int $baseTimePerRun): IndustryCalculationService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($baseTimePerRun === null ? false : (string) $baseTimePerRun);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $sdeEntityManager = $this->createStub(EntityManagerInterface::class);
        $sdeEntityManager->method('getConnection')->willReturn($connection);

        return new IndustryCalculationService(
            $this->createStub(TypeNameResolver::class),
            $bonusService,
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            $sdeEntityManager,
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
        );
    }

    private function userWithCharacters(array $skillLevelsByCharacterName): User
    {
        $user = new User();
        foreach (array_keys($skillLevelsByCharacterName) as $characterName) {
            $user->addCharacter((new Character())->setName($characterName));
        }
        $this->skillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            static fn (Character $character): array => array_map(
                static fn (int $skillId, int $level): CachedCharacterSkill => (new CachedCharacterSkill())->setSkillId($skillId)->setLevel($level),
                array_keys($skillLevelsByCharacterName[$character->getName()]),
                array_values($skillLevelsByCharacterName[$character->getName()]),
            ),
        );

        return $user;
    }

    private function runsAndSplitTotalOfEachStep(IndustryProject $project, string $activityType): array
    {
        return array_values(array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getTotalGroupRuns()],
            array_filter($project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getActivityType() === $activityType),
        ));
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
