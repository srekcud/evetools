<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryRigCategory;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class IndustryProjectFactoryRegenerationTest extends IntegrationTestCase
{
    use AutowiresByType;

    private const int SHIP_CATEGORY_ID = 999_992_006;
    private const int FRIGATE_GROUP_ID = 999_992_025;
    private const int COMPONENT_GROUP_ID = 999_992_334;
    private const int MINERAL_GROUP_ID = 999_992_018;
    private const int MODULE_GROUP_ID = 999_992_053;

    private const int FRIGATE_TYPE_ID = 999_992_587;
    private const int FRIGATE_BLUEPRINT_TYPE_ID = 999_992_586;
    private const int COMPONENT_TYPE_ID = 999_992_101;
    private const int COMPONENT_BLUEPRINT_TYPE_ID = 999_992_102;
    private const int MINERAL_TYPE_ID = 999_992_034;
    private const int MODULE_TYPE_ID = 999_992_201;
    private const int MODULE_BLUEPRINT_TYPE_ID = 999_992_202;

    private const int DESTROYER_GROUP_ID = 999_992_420;
    private const int HULL_COMPONENT_GROUP_ID = 999_992_335;
    private const int SUB_COMPONENT_GROUP_ID = 999_992_336;
    private const int DESTROYER_TYPE_ID = 999_992_301;
    private const int DESTROYER_BLUEPRINT_TYPE_ID = 999_992_302;
    private const int HULL_COMPONENT_TYPE_ID = 999_992_311;
    private const int HULL_COMPONENT_BLUEPRINT_TYPE_ID = 999_992_312;
    private const int SUB_COMPONENT_TYPE_ID = 999_992_321;
    private const int SUB_COMPONENT_BLUEPRINT_TYPE_ID = 999_992_322;

    private const int COMPOSITE_PLATE_GROUP_ID = 999_992_337;
    private const int POLYMER_GROUP_ID = 999_992_428;
    private const int COMPOSITE_PLATE_TYPE_ID = 999_992_331;
    private const int COMPOSITE_PLATE_BLUEPRINT_TYPE_ID = 999_992_332;
    private const int POLYMER_TYPE_ID = 999_992_341;
    private const int POLYMER_FORMULA_TYPE_ID = 999_992_342;
    private const int POLYMERS_PER_COMPOSITE_PLATE_RUN = 6;
    private const int POLYMERS_PER_REACTION_RUN = 20;
    private const int MINERALS_PER_POLYMER_REACTION_RUN = 12;
    private const int POLYMER_REACTION_TIME_PER_RUN_SECONDS = 3600;

    private const int HULL_COMPONENTS_PER_DESTROYER_RUN = 3;
    private const int SUB_COMPONENTS_PER_HULL_COMPONENT_RUN = 7;
    private const int MINERALS_PER_SUB_COMPONENT_RUN = 15;
    private const int DESTROYER_BASE_TIME_PER_RUN_SECONDS = 900;
    private const int HULL_COMPONENT_BASE_TIME_PER_RUN_SECONDS = 600;
    private const int SUB_COMPONENT_BASE_TIME_PER_RUN_SECONDS = 30;

    private const string HULL_COMPONENT_RIG_CATEGORY = 'advanced_component';
    private const string HULL_COMPONENT_MATERIAL_RIG = 'Standup M-Set Advanced Component Manufacturing Material Efficiency I';
    private const string HIGHSEC_RAITARU = 'Highsec Raitaru';
    private const string NULLSEC_RAITARU = 'Nullsec Raitaru';
    private const string TEST_AZBEL = 'Test Azbel';
    private const string TEST_SOTIYO = 'Test Sotiyo';
    private const string TEST_TATARA = 'Test Tatara';

    private const int COMPONENTS_PER_FRIGATE_RUN = 4;
    private const int MINERALS_PER_COMPONENT_RUN = 10;
    private const int MINERALS_PER_MODULE_RUN = 25;
    private const int BASE_TIME_PER_RUN_SECONDS = 600;
    private const int MODULES_PER_FRIGATE_RUN = 2;

    private const float MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT = 2.0;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_FRIGATE_IN_TWO_JOBS = 1800 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_COMPONENT_IN_TWO_JOBS = 4800 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_AT_TE_10_ONLY = 7000 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_IN_TWO_JOBS = 4000 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_BUT_NOT_A_SIX_RUN_DESTROYER = 5000 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_IN_AN_AZBEL_BUT_NOT_IN_A_SOTIYO = 5000 / 86400;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_WITHOUT_THE_TIME_BONUS_OF_AN_AZBEL = 6000 / 86400;

    private const int ROOT_ESI_JOB_ID = 999_992_001;
    private const int MANUAL_ROOT_ESI_JOB_ID = 999_992_002;
    private const int SECOND_ROOT_JOB_ESI_JOB_ID = 999_992_003;
    private const int UNSPLIT_ROOT_ESI_JOB_ID = 999_992_004;
    private const int FIRST_ROOT_JOB_ESI_JOB_ID = 999_992_005;
    private const int SECOND_COMPONENT_JOB_ESI_JOB_ID = 999_992_006;

    private User $pilot;
    private IndustryProjectFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Industry Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->seedFrigateRecipe();
        $this->seedDestroyerRecipe();
        $this->seedCompositePlateRecipe();
        $this->em->flush();

        $container = self::getContainer();
        $this->factory = self::autowired(IndustryProjectFactory::class, [
            $container->get(IndustryTreeService::class),
            $container->get(IndustryBlacklistService::class),
            $container->get(IndustryBonusService::class),
            $container->get(IndustryCalculationService::class),
            $container->get(CachedCharacterSkillRepository::class),
            $container->get(InvTypeRepository::class),
            $container->get(IndustryActivityMaterialRepository::class),
            $container->get(IndustryActivityRepository::class),
            $this->em,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $container->get(IndustryBlueprintRepository::class),
        ]);
    }

    public function testCreatedProjectPersistsTheStepsOfTheTree(): void
    {
        $projectId = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, 2.0, 20)->getId();
        $this->flushAndClear();

        $project = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($project);
        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 18, 18, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 5, 5, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
    }

    #[Group('issue-21')]
    public function testChangingTheRunsKeepsTheRootStepWithItsJobMatchAndPurchase(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $rootStep = $this->stepOfProduct($project, self::FRIGATE_TYPE_ID);
        $rootStep->addJobMatch($this->jobMatch(self::ROOT_ESI_JOB_ID, 5));
        $rootStep->addPurchase($this->purchase(self::COMPONENT_TYPE_ID, 18, 1000.0));
        $this->em->flush();
        $rootStepId = $this->idOf($rootStep);

        $project->setRuns(10);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 36, 36, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 10, 10, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
        self::assertSame(
            [[$rootStepId, 10, 0, null, [self::ROOT_ESI_JOB_ID], [[self::COMPONENT_TYPE_ID, 18]], 0, false, 'auto']],
            $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID),
        );
        self::assertSame([1, 1], [$this->jobMatchCount(), $this->purchaseCount()]);
    }

    #[Group('issue-21')]
    public function testRegenerationKeepsTheStockThePurchasedStatusTheMeAndTheTeOfAStep(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $componentStep = $this->stepOfProduct($project, self::COMPONENT_TYPE_ID);
        $componentStep->setInStockQuantity(7)->setPurchased(true)->setMeLevel(8)->setTeLevel(16);
        $this->em->flush();
        $componentStepId = $this->idOf($componentStep);

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        $regenerated = $this->stepOfProduct($project, self::COMPONENT_TYPE_ID);
        self::assertSame([18, 8, 16], [$regenerated->getRuns(), $regenerated->getMeLevel(), $regenerated->getTeLevel()]);
        self::assertSame([$componentStepId, 7, true], [$this->idOf($regenerated), $regenerated->getInStockQuantity(), $regenerated->isPurchased()]);
    }

    #[Group('issue-21')]
    public function testChangingTheMeKeepsTheManualJobMatchModeAndTheJobMatchOfTheRootStep(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $rootStep = $this->stepOfProduct($project, self::FRIGATE_TYPE_ID);
        $rootStep->setJobMatchMode('manual')->addJobMatch($this->jobMatch(self::MANUAL_ROOT_ESI_JOB_ID, 5));
        $this->em->flush();
        $rootStepId = $this->idOf($rootStep);

        $project->setMeLevel(8);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 19, 19, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 5, 5, 8, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
        self::assertSame(
            [[$rootStepId, 5, 0, null, [self::MANUAL_ROOT_ESI_JOB_ID], [], 0, false, 'manual']],
            $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID),
        );
    }

    #[Group('issue-21')]
    public function testRaisingTheMaximumJobDurationMergesTheSecondJobOfTheRootIntoTheFirst(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FRIGATE_IN_TWO_JOBS, 20);
        [$firstJob, $secondJob] = $this->rootJobsInSplitIndexOrder($project);
        $firstJob->setInStockQuantity(1);
        $secondJob->setInStockQuantity(2)->setPurchased(true);
        $secondJob->addJobMatch($this->jobMatch(self::SECOND_ROOT_JOB_ESI_JOB_ID, 2));
        $secondJob->addPurchase($this->purchase(self::COMPONENT_TYPE_ID, 8, 1150.0));
        $this->em->flush();
        $firstJobId = $this->idOf($firstJob);
        $secondJobId = $this->idOf($secondJob);
        self::assertSame([[3, 0, 5], [2, 1, 5]], [[$firstJob->getRuns(), $firstJob->getSplitIndex(), $firstJob->getTotalGroupRuns()], [$secondJob->getRuns(), $secondJob->getSplitIndex(), $secondJob->getTotalGroupRuns()]]);

        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 18, 18, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 5, 5, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
        self::assertSame(
            [[$firstJobId, 5, 0, null, [self::SECOND_ROOT_JOB_ESI_JOB_ID], [[self::COMPONENT_TYPE_ID, 8]], 3, true, 'auto']],
            $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID),
        );
        self::assertNull($this->em->find(IndustryProjectStep::class, $secondJobId));
        self::assertSame([1, 1], [$this->jobMatchCount(), $this->purchaseCount()]);
    }

    #[Group('issue-21')]
    public function testLoweringTheMaximumJobDurationKeepsTheRootStepAsTheFirstJobAndCreatesTheSecond(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $rootStep = $this->stepOfProduct($project, self::FRIGATE_TYPE_ID);
        $rootStep->addJobMatch($this->jobMatch(self::UNSPLIT_ROOT_ESI_JOB_ID, 3));
        $this->em->flush();
        $rootStepId = $this->idOf($rootStep);

        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FRIGATE_IN_TWO_JOBS);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        [$firstJob, $secondJob] = $this->rootJobsInSplitIndexOrder($project);
        self::assertSame([[3, 0, 5], [2, 1, 5]], [[$firstJob->getRuns(), $firstJob->getSplitIndex(), $firstJob->getTotalGroupRuns()], [$secondJob->getRuns(), $secondJob->getSplitIndex(), $secondJob->getTotalGroupRuns()]]);
        self::assertSame($firstJob->getSplitGroupId(), $secondJob->getSplitGroupId());
        self::assertNotSame($rootStepId, $this->idOf($secondJob));
        self::assertSame(
            [
                [$rootStepId, 3, 0, 5, [self::UNSPLIT_ROOT_ESI_JOB_ID], [], 0, false, 'auto'],
                [$this->idOf($secondJob), 2, 1, 5, [], [], 0, false, 'auto'],
            ],
            $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID),
        );
    }

    #[Group('issue-21')]
    public function testChangingTheRunsKeepsAProductAddedByHandWithItsPurchaseAfterTheStepsOfThePlan(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $addedByHand = $this->stepAddedByHand(self::MODULE_BLUEPRINT_TYPE_ID, self::MODULE_TYPE_ID, 'manufacturing', 3, 2);
        $addedByHand->addPurchase($this->purchase(self::MINERAL_TYPE_ID, 75, 6.5));
        $project->addStep($addedByHand);
        $this->em->flush();
        $addedByHandId = $this->idOf($addedByHand);

        $project->setRuns(10);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 36, 36, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 10, 10, 10, 20, null, 0, null],
                [2, self::MODULE_BLUEPRINT_TYPE_ID, self::MODULE_TYPE_ID, 'manufacturing', 0, 3, 3, 0, 0, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
        self::assertSame(
            [[$addedByHandId, 3, 0, null, [], [[self::MINERAL_TYPE_ID, 75]], 0, false, 'auto']],
            $this->jobsOfProduct($project, self::MODULE_TYPE_ID),
        );
        self::assertSame(1, $this->purchaseCount());
    }

    #[Group('issue-21')]
    public function testChangingTheRunsOfASplitRootKeepsEachJobMatchOnTheJobOfTheSameSplitIndex(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FRIGATE_IN_TWO_JOBS, 20);
        $project = $this->reloaded($project);
        [$firstJob] = $this->rootJobsInSplitIndexOrder($project);
        $firstJob->setInStockQuantity(4);
        $project = $this->reloaded($project);
        [$firstJob, $secondJob] = $this->rootJobsInSplitIndexOrder($project);
        $firstJob->addJobMatch($this->jobMatch(self::FIRST_ROOT_JOB_ESI_JOB_ID, 3));
        $secondJob->addJobMatch($this->jobMatch(self::SECOND_ROOT_JOB_ESI_JOB_ID, 2));
        $this->em->flush();
        $firstJobId = $this->idOf($firstJob);
        $secondJobId = $this->idOf($secondJob);

        $project->setRuns(6);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [$firstJobId, 3, 0, 6, [self::FIRST_ROOT_JOB_ESI_JOB_ID], [], 4, false, 'auto'],
                [$secondJobId, 3, 1, 6, [self::SECOND_ROOT_JOB_ESI_JOB_ID], [], 0, false, 'auto'],
            ],
            $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID),
        );
    }

    #[Group('issue-21')]
    public function testMergingTheSecondJobIntoAPurchasedFirstJobKeepsItPurchased(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_FRIGATE_IN_TWO_JOBS, 20);
        [$firstJob] = $this->rootJobsInSplitIndexOrder($project);
        $firstJob->setPurchased(true);
        $this->em->flush();
        $firstJobId = $this->idOf($firstJob);

        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[$firstJobId, 5, 0, null, [], [], 0, true, 'auto']],
            $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID),
        );
    }

    #[Group('issue-21')]
    public function testMergingTheSecondJobOfTheComponentGivesItsJobMatchPurchaseAndStockToTheComponentNotToTheRoot(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_COMPONENT_IN_TWO_JOBS, 20);
        $componentJobs = $this->jobsOfProduct($project, self::COMPONENT_TYPE_ID);
        self::assertSame([[9, 0, 18], [9, 1, 18]], array_map(static fn (array $job): array => [$job[1], $job[2], $job[3]], $componentJobs));
        self::assertCount(1, $this->jobsOfProduct($project, self::FRIGATE_TYPE_ID));
        $secondComponentJob = $this->em->find(IndustryProjectStep::class, $componentJobs[1][0]);
        self::assertNotNull($secondComponentJob);
        $secondComponentJob->setInStockQuantity(5);
        $secondComponentJob->addJobMatch($this->jobMatch(self::SECOND_COMPONENT_JOB_ESI_JOB_ID, 9));
        $secondComponentJob->addPurchase($this->purchase(self::MINERAL_TYPE_ID, 90, 4.25));
        $this->em->flush();

        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[$componentJobs[0][0], 18, 0, null, [self::SECOND_COMPONENT_JOB_ESI_JOB_ID], [[self::MINERAL_TYPE_ID, 90]], 5, false, 'auto']],
            $this->jobsOfProduct($project, self::COMPONENT_TYPE_ID),
        );
        self::assertSame([[], [], 0], array_slice($this->jobsOfProduct($project, self::FRIGATE_TYPE_ID)[0], 4, 3));
    }

    #[Group('issue-21')]
    public function testACopyStepOfAPlannedBlueprintIsKeptOutOfThePlanAsACopy(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $componentStepId = $this->idOf($this->stepOfProduct($project, self::COMPONENT_TYPE_ID));
        $copyStep = $this->stepAddedByHand(self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_BLUEPRINT_TYPE_ID, 'copy', 2, 2);
        $copyStep->addPurchase($this->purchase(self::MINERAL_TYPE_ID, 12, 3.75));
        $project->addStep($copyStep);
        $this->em->flush();
        $copyStepId = $this->idOf($copyStep);

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 18, 18, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 5, 5, 10, 20, null, 0, null],
                [2, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_BLUEPRINT_TYPE_ID, 'copy', 0, 2, 2, 0, 0, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
        self::assertSame(
            [[$copyStepId, 2, 0, null, [], [[self::MINERAL_TYPE_ID, 12]], 0, false, 'auto']],
            $this->jobsOfProduct($project, self::COMPONENT_BLUEPRINT_TYPE_ID),
        );
        self::assertSame($componentStepId, $this->jobsOfProduct($project, self::COMPONENT_TYPE_ID)[0][0]);
    }

    #[Group('issue-21')]
    public function testRegenerationKeepsTheStructureOfTheRootStep(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName('Test Raitaru')
            ->setSecurityType('highsec')
            ->setStructureType('station');
        $this->em->persist($structure);
        $rootStep = $this->stepOfProduct($project, self::FRIGATE_TYPE_ID);
        $rootStep->setStructureConfig($structure);
        $this->em->flush();
        $rootStepId = $this->idOf($rootStep);

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        $regenerated = $this->stepOfProduct($project, self::FRIGATE_TYPE_ID);
        self::assertSame([$rootStepId, 5, 10, 20], [$this->idOf($regenerated), $regenerated->getRuns(), $regenerated->getMeLevel(), $regenerated->getTeLevel()]);
        self::assertSame('Test Raitaru', $regenerated->getStructureConfig()?->getName());
    }

    #[Group('issue-21')]
    public function testStepsOutOfThePlanArePlacedAfterThePlanInTheOrderOfTheirFormerSortOrder(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $project->addStep($this->stepAddedByHand(self::MODULE_BLUEPRINT_TYPE_ID, self::MODULE_TYPE_ID, 'manufacturing', 4, 5));
        $project->addStep($this->stepAddedByHand(self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_BLUEPRINT_TYPE_ID, 'copy', 1, 3));
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 18, 18, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 5, 5, 10, 20, null, 0, null],
                [2, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_BLUEPRINT_TYPE_ID, 'copy', 0, 1, 1, 0, 0, null, 0, null],
                [3, self::MODULE_BLUEPRINT_TYPE_ID, self::MODULE_TYPE_ID, 'manufacturing', 0, 4, 4, 0, 0, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
    }

    #[Group('issue-21')]
    public function testChangingTheRunsKeepsTheMeOfTheHullComponentAndPlansItsSubComponentsWithIt(): void
    {
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $this->stepOfProduct($project, self::HULL_COMPONENT_TYPE_ID)->setMeLevel(8);
        $this->em->flush();

        $project->setRuns(6);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::SUB_COMPONENT_BLUEPRINT_TYPE_ID, self::SUB_COMPONENT_TYPE_ID, 'manufacturing', 2, 110, 110, 10, 20, null, 0, null],
                [1, self::HULL_COMPONENT_BLUEPRINT_TYPE_ID, self::HULL_COMPONENT_TYPE_ID, 'manufacturing', 1, 17, 17, 8, 20, null, 0, null],
                [2, self::DESTROYER_BLUEPRINT_TYPE_ID, self::DESTROYER_TYPE_ID, 'manufacturing', 0, 6, 6, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
    }

    #[Group('issue-21')]
    public function testRegenerationKeepsTheStructureOfTheHullComponentAndPlansItsSubComponentsWithItsMaterialBonus(): void
    {
        $highsecRaitaru = $this->raitaru(self::HIGHSEC_RAITARU, 'highsec');
        $this->raitaru(self::NULLSEC_RAITARU, 'nullsec');
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $this->stepOfProduct($project, self::HULL_COMPONENT_TYPE_ID)->setStructureConfig($highsecRaitaru);
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::SUB_COMPONENT_BLUEPRINT_TYPE_ID, self::SUB_COMPONENT_TYPE_ID, 'manufacturing', 2, 80, 80, 10, 20, null, 0, null],
                [1, self::HULL_COMPONENT_BLUEPRINT_TYPE_ID, self::HULL_COMPONENT_TYPE_ID, 'manufacturing', 1, 13, 13, 10, 20, null, 0, null],
                [2, self::DESTROYER_BLUEPRINT_TYPE_ID, self::DESTROYER_TYPE_ID, 'manufacturing', 0, 5, 5, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
        self::assertSame([[0, 13, 13, 10, 20, self::HIGHSEC_RAITARU]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
    }

    #[Group('issue-21')]
    public function testRegenerationSplitsTheHullComponentWithItsTe(): void
    {
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_AT_TE_10_ONLY, 20);
        self::assertSame([[0, 14, 14, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
        $this->stepOfProduct($project, self::HULL_COMPONENT_TYPE_ID)->setTeLevel(10);
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[0, 7, 7, 10, 10, null], [1, 7, 7, 10, 10, null]],
            $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID),
        );
        self::assertSame([[0, 90, 90, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::SUB_COMPONENT_TYPE_ID));
    }

    #[Group('issue-21')]
    public function testChangingTheRunsGivesTheRootStepTheMeAndTeOfTheProject(): void
    {
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $this->stepOfProduct($project, self::DESTROYER_TYPE_ID)->setMeLevel(8)->setTeLevel(14);
        $this->em->flush();

        $project->setRuns(6);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [
                [0, self::SUB_COMPONENT_BLUEPRINT_TYPE_ID, self::SUB_COMPONENT_TYPE_ID, 'manufacturing', 2, 108, 108, 10, 20, null, 0, null],
                [1, self::HULL_COMPONENT_BLUEPRINT_TYPE_ID, self::HULL_COMPONENT_TYPE_ID, 'manufacturing', 1, 17, 17, 10, 20, null, 0, null],
                [2, self::DESTROYER_BLUEPRINT_TYPE_ID, self::DESTROYER_TYPE_ID, 'manufacturing', 0, 6, 6, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
    }

    #[Group('issue-21')]
    public function testASecondJobCreatedByLoweringTheMaximumJobDurationTakesTheMeTeAndStructureOfTheFirstJob(): void
    {
        $highsecRaitaru = $this->raitaru(self::HIGHSEC_RAITARU, 'highsec');
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $this->stepOfProduct($project, self::HULL_COMPONENT_TYPE_ID)->setMeLevel(8)->setTeLevel(16)->setStructureConfig($highsecRaitaru);
        $this->em->flush();

        $project->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_IN_TWO_JOBS);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[0, 7, 7, 8, 16, self::HIGHSEC_RAITARU], [1, 7, 7, 8, 16, self::HIGHSEC_RAITARU]],
            $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID),
        );
    }

    #[Group('issue-21')]
    public function testChangingTheRunsKeepsTheMeTeAndStructureOfEachJobOfASplitHullComponent(): void
    {
        $highsecRaitaru = $this->raitaru(self::HIGHSEC_RAITARU, 'highsec');
        $nullsecRaitaru = $this->raitaru(self::NULLSEC_RAITARU, 'nullsec');
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_BUT_NOT_A_SIX_RUN_DESTROYER, 20);
        $hullComponentJobs = $this->jobsOfProductInSplitIndexOrder($project, self::HULL_COMPONENT_TYPE_ID);
        self::assertSame([[0, 7, 7, 10, 20, null], [1, 6, 6, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
        $hullComponentJobs[0]->setMeLevel(8)->setTeLevel(16)->setStructureConfig($highsecRaitaru);
        $hullComponentJobs[1]->setMeLevel(9)->setTeLevel(18)->setStructureConfig($nullsecRaitaru);
        $this->em->flush();

        $project->setRuns(6);
        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[0, 8, 8, 8, 16, self::HIGHSEC_RAITARU], [1, 8, 8, 9, 18, self::NULLSEC_RAITARU]],
            $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID),
        );
        self::assertSame([[0, 99, 99, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::SUB_COMPONENT_TYPE_ID));
    }

    #[Group('issue-21')]
    public function testRegenerationSplitsTheHullComponentWithTheTimeBonusOfItsStructureRatherThanTheBestOne(): void
    {
        $azbel = $this->structure(self::TEST_AZBEL, 'azbel');
        $this->structure(self::TEST_SOTIYO, 'sotiyo')->setRigs([self::HULL_COMPONENT_MATERIAL_RIG]);
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_IN_AN_AZBEL_BUT_NOT_IN_A_SOTIYO, 20);
        self::assertSame([[0, 14, 14, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
        $this->stepOfProduct($project, self::HULL_COMPONENT_TYPE_ID)->setStructureConfig($azbel);
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame(
            [[0, 7, 7, 10, 20, self::TEST_AZBEL], [1, 7, 7, 10, 20, self::TEST_AZBEL]],
            $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID),
        );
    }

    #[Group('issue-21')]
    public function testRegenerationKeepsTheHullComponentInOneJobThanksToTheTimeBonusOfItsStructure(): void
    {
        $azbel = $this->structure(self::TEST_AZBEL, 'azbel');
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_SPLITTING_THE_HULL_COMPONENT_WITHOUT_THE_TIME_BONUS_OF_AN_AZBEL, 20);
        $this->stepOfProduct($project, self::HULL_COMPONENT_TYPE_ID)->setStructureConfig($azbel);
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame([[0, 14, 14, 10, 20, self::TEST_AZBEL]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
    }

    #[Group('issue-21')]
    public function testRegenerationGivesAReactionStepNoMeNorTeAndKeepsItsStructure(): void
    {
        $tatara = $this->structure(self::TEST_TATARA, 'tatara');
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::COMPOSITE_PLATE_TYPE_ID, 4, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $this->stepOfProduct($project, self::POLYMER_TYPE_ID)->setMeLevel(5)->setTeLevel(5)->setStructureConfig($tatara);
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame([[0, 2, 22, 0, 0, self::TEST_TATARA]], $this->settingsOfJobsOfProduct($project, self::POLYMER_TYPE_ID));
        self::assertSame('reaction', $this->stepOfProduct($project, self::POLYMER_TYPE_ID)->getActivityType());
    }

    #[Group('issue-21')]
    public function testAStructureOnARootWithoutRigCategoryPlansItsHullComponentsWithTheBaseMaterialBonusOnly(): void
    {
        $nullsecRaitaru = $this->raitaru(self::NULLSEC_RAITARU, 'nullsec');
        $this->em->flush();
        $project = $this->factory->createProject($this->pilot, self::DESTROYER_TYPE_ID, 37, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        self::assertSame([[0, 95, 95, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
        $this->stepOfProduct($project, self::DESTROYER_TYPE_ID)->setStructureConfig($nullsecRaitaru);
        $this->em->flush();

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame([[0, 37, 37, 10, 20, self::NULLSEC_RAITARU]], $this->settingsOfJobsOfProduct($project, self::DESTROYER_TYPE_ID));
        self::assertSame([[0, 99, 99, 10, 20, null]], $this->settingsOfJobsOfProduct($project, self::HULL_COMPONENT_TYPE_ID));
    }

    public function testExcludedComponentsAreRememberedOnceAndNeverPlannedAtCreationNorAtRegeneration(): void
    {
        $this->em->persist((new IndustryActivityMaterial())->setTypeId(self::FRIGATE_BLUEPRINT_TYPE_ID)->setActivityId(IndustryActivityType::Manufacturing->value)->setMaterialTypeId(self::MODULE_TYPE_ID)->setQuantity(self::MODULES_PER_FRIGATE_RUN));
        $this->em->flush();

        $projectId = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20, null, [self::COMPONENT_TYPE_ID, self::MODULE_TYPE_ID, self::COMPONENT_TYPE_ID])->getId();
        $this->flushAndClear();
        $project = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($project);

        self::assertSame([self::COMPONENT_TYPE_ID, self::MODULE_TYPE_ID], $project->getExcludedTypeIds());
        self::assertSame([self::FRIGATE_TYPE_ID], array_column($this->persistedStepsOf($project), 2));

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        self::assertSame([self::COMPONENT_TYPE_ID, self::MODULE_TYPE_ID], $project->getExcludedTypeIds());
        self::assertSame([self::FRIGATE_TYPE_ID], array_column($this->persistedStepsOf($project), 2));
    }

    /**
     * @return list<list<int|string|null>>
     */
    private function persistedStepsOf(IndustryProject $project): array
    {
        $steps = [];
        foreach ($project->getSteps() as $step) {
            $steps[] = [
                $step->getSortOrder(),
                $step->getBlueprintTypeId(),
                $step->getProductTypeId(),
                $step->getActivityType(),
                $step->getDepth(),
                $step->getRuns(),
                $step->getQuantity(),
                $step->getMeLevel(),
                $step->getTeLevel(),
                $step->getSplitGroupId(),
                $step->getSplitIndex(),
                $step->getTotalGroupRuns(),
            ];
        }
        usort($steps, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $steps;
    }

    /**
     * @return list<array{string, int, int, int|null, list<int>, list<array{int, int}>, int, bool, string}>
     */
    private function jobsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        $jobs = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getProductTypeId() !== $productTypeId) {
                continue;
            }
            $esiJobIds = array_map(static fn (IndustryStepJobMatch $match): int => $match->getEsiJobId(), $step->getJobMatches()->toArray());
            sort($esiJobIds);
            $jobs[] = [
                $this->idOf($step),
                $step->getRuns(),
                $step->getSplitIndex(),
                $step->getTotalGroupRuns(),
                $esiJobIds,
                array_values(array_map(static fn (IndustryStepPurchase $purchase): array => [$purchase->getTypeId(), $purchase->getQuantity()], $step->getPurchases()->toArray())),
                $step->getInStockQuantity(),
                $step->isPurchased(),
                $step->getJobMatchMode(),
            ];
        }
        usort($jobs, static fn (array $a, array $b): int => $a[2] <=> $b[2]);

        return $jobs;
    }

    /**
     * @return list<array{int, int, int, int, int, string|null}>
     */
    private function settingsOfJobsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        $jobs = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getProductTypeId() === $productTypeId) {
                $jobs[] = [$step->getSplitIndex(), $step->getRuns(), $step->getQuantity(), $step->getMeLevel(), $step->getTeLevel(), $step->getStructureConfig()?->getName()];
            }
        }
        usort($jobs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $jobs;
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private function jobsOfProductInSplitIndexOrder(IndustryProject $project, int $productTypeId): array
    {
        $jobs = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId,
        ));
        usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $jobs;
    }

    private function structure(string $name, string $structureType): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSecurityType('highsec')
            ->setStructureType($structureType);
        $this->em->persist($structure);

        return $structure;
    }

    private function raitaru(string $name, string $securityType): IndustryStructureConfig
    {
        $raitaru = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSecurityType($securityType)
            ->setStructureType('raitaru')
            ->setRigs([self::HULL_COMPONENT_MATERIAL_RIG]);
        $this->em->persist($raitaru);

        return $raitaru;
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private function rootJobsInSplitIndexOrder(IndustryProject $project): array
    {
        $rootJobs = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === self::FRIGATE_TYPE_ID,
        ));
        usort($rootJobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());
        self::assertCount(2, $rootJobs);

        return $rootJobs;
    }

    private function reloaded(IndustryProject $project): IndustryProject
    {
        $projectId = $project->getId();
        $this->flushAndClear();
        $reloaded = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function idOf(IndustryProjectStep $step): string
    {
        $id = $step->getId();
        self::assertNotNull($id);

        return $id->toRfc4122();
    }

    private function stepAddedByHand(int $blueprintTypeId, int $productTypeId, string $activityType, int $runs, int $sortOrder): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setQuantity($runs)
            ->setDepth(0)
            ->setActivityType($activityType)
            ->setSortOrder($sortOrder)
            ->setMeLevel(0)
            ->setTeLevel(0);
    }

    private function jobMatch(int $esiJobId, int $runs): IndustryStepJobMatch
    {
        return (new IndustryStepJobMatch())->setEsiJobId($esiJobId)->setRuns($runs)->setCharacterName('Industry Pilot');
    }

    private function purchase(int $typeId, int $quantity, float $unitPrice): IndustryStepPurchase
    {
        return (new IndustryStepPurchase())->setTypeId($typeId)->setQuantity($quantity)->setUnitPrice($unitPrice)->setTotalPrice($quantity * $unitPrice)->setSource('manual');
    }

    private function stepOfProduct(IndustryProject $project, int $productTypeId): IndustryProjectStep
    {
        foreach ($project->getSteps() as $step) {
            if ($step->getProductTypeId() === $productTypeId) {
                return $step;
            }
        }

        self::fail("No step for product {$productTypeId}");
    }

    private function jobMatchCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.IndustryStepJobMatch::class.' m JOIN m.step s JOIN s.project pr WHERE pr.user = :pilot')
            ->setParameter('pilot', $this->pilot)
            ->getSingleScalarResult();
    }

    private function purchaseCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.IndustryStepPurchase::class.' p JOIN p.step s JOIN s.project pr WHERE pr.user = :pilot')
            ->setParameter('pilot', $this->pilot)
            ->getSingleScalarResult();
    }

    private function seedFrigateRecipe(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SHIP_CATEGORY_ID)->setCategoryName('Test Ship');
        $this->em->persist($category);
        $this->type(self::FRIGATE_TYPE_ID, 'Test Frigate', $this->group(self::FRIGATE_GROUP_ID, 'Test Frigate', $category));
        $this->type(self::COMPONENT_TYPE_ID, 'Test Component', $this->group(self::COMPONENT_GROUP_ID, 'Test Component', $category));
        $this->type(self::MINERAL_TYPE_ID, 'Test Mineral', $this->group(self::MINERAL_GROUP_ID, 'Test Mineral', $category));
        $this->type(self::MODULE_TYPE_ID, 'Test Module', $this->group(self::MODULE_GROUP_ID, 'Test Module', $category));

        $this->manufacturingRecipe(self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, self::COMPONENT_TYPE_ID, self::COMPONENTS_PER_FRIGATE_RUN);
        $this->manufacturingRecipe(self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, self::MINERAL_TYPE_ID, self::MINERALS_PER_COMPONENT_RUN);
        $this->manufacturingRecipe(self::MODULE_BLUEPRINT_TYPE_ID, self::MODULE_TYPE_ID, self::MINERAL_TYPE_ID, self::MINERALS_PER_MODULE_RUN);
    }

    private function seedDestroyerRecipe(): void
    {
        $category = $this->em->find(InvCategory::class, self::SHIP_CATEGORY_ID);
        self::assertNotNull($category);
        $this->type(self::DESTROYER_TYPE_ID, 'Test Destroyer', $this->group(self::DESTROYER_GROUP_ID, 'Test Destroyer', $category));
        $this->type(self::HULL_COMPONENT_TYPE_ID, 'Test Hull Component', $this->group(self::HULL_COMPONENT_GROUP_ID, 'Test Hull Component', $category));
        $this->type(self::SUB_COMPONENT_TYPE_ID, 'Test Sub-Component', $this->group(self::SUB_COMPONENT_GROUP_ID, 'Test Sub-Component', $category));
        $this->em->persist((new IndustryRigCategory())->setGroupId(self::HULL_COMPONENT_GROUP_ID)->setCategory(self::HULL_COMPONENT_RIG_CATEGORY));

        $this->manufacturingRecipe(self::DESTROYER_BLUEPRINT_TYPE_ID, self::DESTROYER_TYPE_ID, self::HULL_COMPONENT_TYPE_ID, self::HULL_COMPONENTS_PER_DESTROYER_RUN, self::DESTROYER_BASE_TIME_PER_RUN_SECONDS);
        $this->manufacturingRecipe(self::HULL_COMPONENT_BLUEPRINT_TYPE_ID, self::HULL_COMPONENT_TYPE_ID, self::SUB_COMPONENT_TYPE_ID, self::SUB_COMPONENTS_PER_HULL_COMPONENT_RUN, self::HULL_COMPONENT_BASE_TIME_PER_RUN_SECONDS);
        $this->manufacturingRecipe(self::SUB_COMPONENT_BLUEPRINT_TYPE_ID, self::SUB_COMPONENT_TYPE_ID, self::MINERAL_TYPE_ID, self::MINERALS_PER_SUB_COMPONENT_RUN, self::SUB_COMPONENT_BASE_TIME_PER_RUN_SECONDS);
    }

    private function seedCompositePlateRecipe(): void
    {
        $category = $this->em->find(InvCategory::class, self::SHIP_CATEGORY_ID);
        self::assertNotNull($category);
        $this->type(self::COMPOSITE_PLATE_TYPE_ID, 'Test Composite Plate', $this->group(self::COMPOSITE_PLATE_GROUP_ID, 'Test Composite Plate', $category));
        $this->type(self::POLYMER_TYPE_ID, 'Test Polymer', $this->group(self::POLYMER_GROUP_ID, 'Test Polymer', $category));

        $this->manufacturingRecipe(self::COMPOSITE_PLATE_BLUEPRINT_TYPE_ID, self::COMPOSITE_PLATE_TYPE_ID, self::POLYMER_TYPE_ID, self::POLYMERS_PER_COMPOSITE_PLATE_RUN);

        $reaction = IndustryActivityType::Reaction->value;
        $this->em->persist((new IndustryActivityProduct())->setTypeId(self::POLYMER_FORMULA_TYPE_ID)->setActivityId($reaction)->setProductTypeId(self::POLYMER_TYPE_ID)->setQuantity(self::POLYMERS_PER_REACTION_RUN));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId(self::POLYMER_FORMULA_TYPE_ID)->setActivityId($reaction)->setMaterialTypeId(self::MINERAL_TYPE_ID)->setQuantity(self::MINERALS_PER_POLYMER_REACTION_RUN));
        $this->em->persist((new IndustryActivity())->setTypeId(self::POLYMER_FORMULA_TYPE_ID)->setActivityId($reaction)->setTime(self::POLYMER_REACTION_TIME_PER_RUN_SECONDS));
    }

    private function manufacturingRecipe(int $blueprintTypeId, int $productTypeId, int $materialTypeId, int $baseQuantityPerRun, int $baseTimePerRunSeconds = self::BASE_TIME_PER_RUN_SECONDS): void
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setProductTypeId($productTypeId)->setQuantity(1));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setMaterialTypeId($materialTypeId)->setQuantity($baseQuantityPerRun));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setTime($baseTimePerRunSeconds));
    }

    private function group(int $groupId, string $name, InvCategory $category): InvGroup
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($category);
        $this->em->persist($group);

        return $group;
    }

    private function type(int $typeId, string $name, InvGroup $group): void
    {
        $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($group));
    }
}
