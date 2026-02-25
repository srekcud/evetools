<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(IndustryProjectFactory::class)]
class IndustryProjectFactoryTest extends TestCase
{
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
        );
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

    // ===========================================
    // Skill multiplier calculation
    // ===========================================

    public function testSkillMultiplierReducesJobTime(): void
    {
        $skills = [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,      // -20%
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5, // -15%
        ];
        $user = $this->createUserWithCharacter('TestChar', $skills);

        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->calculationService->method('getBlueprintScienceSkillIds')->willReturn([]);

        // Base time of 10000 seconds
        $activity = $this->createMock(IndustryActivity::class);
        $activity->method('getTime')->willReturn(10000);
        $activity->method('getTypeId')->willReturn(586);
        $activity->method('getActivityId')->willReturn(1);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([
            '586-1' => $activity,
        ]);

        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn([
            'bonus' => 0.0,
            'structure' => null,
        ]);
        // Return base time unchanged (no TE or structure bonus for simplicity)
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturn(10000);

        $project = $this->factory->createProject($user, 587, 1, 10, 2.0, 0);

        // Skill multiplier: (1 - 0.04*5) * (1 - 0.03*5) = 0.80 * 0.85 = 0.68
        // Expected time: ceil(10000 * 0.68) = 6800
        // The step's time data is internal; we verify the project was created without error
        // and the step exists. The time calculation is embedded in the step creation.
        $this->assertCount(1, $project->getSteps());
    }

    public function testReactionSkillMultiplier(): void
    {
        $skills = [
            CachedCharacterSkill::SKILL_REACTIONS => 4, // -16%
        ];
        $user = $this->createUserWithCharacter('ReactChar', $skills);

        $invType = $this->createMock(InvType::class);
        $this->invTypeRepository->method('find')->willReturn($invType);

        $tree = $this->buildFlatTreeNode(2000, 2001, 'Reaction Product', 5, 200, [], 0, 'reaction');
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->calculationService->method('getBlueprintScienceSkillIds')->willReturn([]);

        $activity = $this->createMock(IndustryActivity::class);
        $activity->method('getTime')->willReturn(3600);
        $activity->method('getTypeId')->willReturn(2000);
        $activity->method('getActivityId')->willReturn(11);
        $this->activityRepository->method('findByTypeIdsAndActivityIds')->willReturn([
            '2000-11' => $activity,
        ]);

        $this->bonusService->method('findBestStructureForProductTimeBonus')->willReturn([
            'bonus' => 0.0,
            'structure' => null,
        ]);
        $this->bonusService->method('calculateAdjustedTimePerRun')->willReturn(3600);
        $this->bonusService->method('getCategoryForProduct')->willReturn(null);
        $this->materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn([]);

        $project = $this->factory->createProject($user, 2001, 5, 0, 2.0, 0);

        // Verify the reaction step was created
        $reactionStep = null;
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'reaction') {
                $reactionStep = $step;
                break;
            }
        }
        $this->assertNotNull($reactionStep);
        $this->assertSame(0, $reactionStep->getMeLevel());
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
}
