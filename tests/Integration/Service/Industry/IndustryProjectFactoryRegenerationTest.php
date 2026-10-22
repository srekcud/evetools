<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
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
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class IndustryProjectFactoryRegenerationTest extends IntegrationTestCase
{
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

    private const int COMPONENTS_PER_FRIGATE_RUN = 4;
    private const int MINERALS_PER_COMPONENT_RUN = 10;
    private const int MINERALS_PER_MODULE_RUN = 25;
    private const int BASE_TIME_PER_RUN_SECONDS = 600;

    private const float MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT = 2.0;
    private const float MAX_JOB_DURATION_DAYS_SPLITTING_THE_FRIGATE_IN_TWO_JOBS = 1800 / 86400;

    private const int ROOT_ESI_JOB_ID = 999_992_001;
    private const int MANUAL_ROOT_ESI_JOB_ID = 999_992_002;
    private const int SECOND_ROOT_JOB_ESI_JOB_ID = 999_992_003;
    private const int UNSPLIT_ROOT_ESI_JOB_ID = 999_992_004;

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
        $this->em->flush();

        $container = self::getContainer();
        $this->factory = new IndustryProjectFactory(
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
        );
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
    public function testRegenerationKeepsTheStockAndPurchasedStatusOfAStepAndResetsItsMeAndTeToThePlan(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, self::MAX_JOB_DURATION_DAYS_WITHOUT_SPLIT, 20);
        $componentStep = $this->stepOfProduct($project, self::COMPONENT_TYPE_ID);
        $componentStep->setInStockQuantity(7)->setPurchased(true)->setMeLevel(8)->setTeLevel(16);
        $this->em->flush();
        $componentStepId = $this->idOf($componentStep);

        $this->factory->regenerateSteps($project);
        $project = $this->reloaded($project);

        $regenerated = $this->stepOfProduct($project, self::COMPONENT_TYPE_ID);
        self::assertSame([18, 10, 20], [$regenerated->getRuns(), $regenerated->getMeLevel(), $regenerated->getTeLevel()]);
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
        $addedByHand = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::MODULE_BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::MODULE_TYPE_ID)
            ->setRuns(3)
            ->setQuantity(3)
            ->setDepth(0)
            ->setActivityType('manufacturing')
            ->setSortOrder(2)
            ->setMeLevel(0)
            ->setTeLevel(0);
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

    private function manufacturingRecipe(int $blueprintTypeId, int $productTypeId, int $materialTypeId, int $baseQuantityPerRun): void
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $this->em->persist((new IndustryActivityProduct())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setProductTypeId($productTypeId)->setQuantity(1));
        $this->em->persist((new IndustryActivityMaterial())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setMaterialTypeId($materialTypeId)->setQuantity($baseQuantityPerRun));
        $this->em->persist((new IndustryActivity())->setTypeId($blueprintTypeId)->setActivityId($manufacturing)->setTime(self::BASE_TIME_PER_RUN_SECONDS));
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
