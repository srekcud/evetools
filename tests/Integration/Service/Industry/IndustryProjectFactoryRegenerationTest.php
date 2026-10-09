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
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

/**
 * Characterization of the steps written to the database by IndustryProjectFactory, before buildProductionTree() is
 * replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 7): steps persisted at creation, and
 * what a regeneration keeps. UpdateProjectProcessor regenerates the steps when the runs, ME, TE or maximum job
 * duration of a project change; app:industry:regenerate-steps regenerates them unconditionally.
 *
 * Real: the factory and every collaborator from the container, on a seeded synthetic recipe (frigate made of
 * 4 components per run, component made of 10 minerals per run, 600 s per run each). Stubbed: the Mercure hub.
 */
final class IndustryProjectFactoryRegenerationTest extends IntegrationTestCase
{
    private const int SHIP_CATEGORY_ID = 999_992_006;
    private const int FRIGATE_GROUP_ID = 999_992_025;
    private const int COMPONENT_GROUP_ID = 999_992_334;
    private const int MINERAL_GROUP_ID = 999_992_018;

    private const int FRIGATE_TYPE_ID = 999_992_587;
    private const int FRIGATE_BLUEPRINT_TYPE_ID = 999_992_586;
    private const int COMPONENT_TYPE_ID = 999_992_101;
    private const int COMPONENT_BLUEPRINT_TYPE_ID = 999_992_102;
    private const int MINERAL_TYPE_ID = 999_992_034;

    private const int COMPONENTS_PER_FRIGATE_RUN = 4;
    private const int MINERALS_PER_COMPONENT_RUN = 10;
    private const int BASE_TIME_PER_RUN_SECONDS = 600;

    private const int ESI_JOB_ID = 999_992_001;

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

    // 5 runs of frigate at ME 10 need max(5, ceil(4 × 5 × 0.9)) = 18 components, built at the hard-coded ME 10 / TE 20
    // of intermediates. Deepest step first.
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #21 : changer les runs régénère les étapes en
    // supprimant les anciennes, et avec elles leurs liens de jobs ESI (IndustryStepJobMatch) et leurs achats
    // (IndustryStepPurchase). La spec (§7, rang 7) demande que les liens de jobs soient conservés.
    public function testChangingTheRunsCurrentlyDeletesTheJobLinksAndPurchasesOfTheStepsIssue21(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, 2.0, 20);
        $rootStep = $this->stepOfProduct($project, self::FRIGATE_TYPE_ID);
        $rootStep->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(self::ESI_JOB_ID)->setRuns(5)->setCharacterName('Industry Pilot'));
        $rootStep->addPurchase((new IndustryStepPurchase())->setTypeId(self::COMPONENT_TYPE_ID)->setQuantity(18)->setUnitPrice(1000.0)->setTotalPrice(18000.0)->setSource('manual'));
        $this->em->flush();
        self::assertSame([1, 1], [$this->jobLinkCount(), $this->purchaseCount()]);

        $project->setRuns(10);
        $this->factory->regenerateSteps($project);
        $projectId = $project->getId();
        $this->flushAndClear();

        self::assertSame([0, 0], [$this->jobLinkCount(), $this->purchaseCount()]);
        $project = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($project);
        self::assertSame(
            [
                [0, self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, 'manufacturing', 1, 36, 36, 10, 20, null, 0, null],
                [1, self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, 'manufacturing', 0, 10, 10, 10, 20, null, 0, null],
            ],
            $this->persistedStepsOf($project),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #21 : même sans changement de paramètre (commande
    // app:industry:regenerate-steps), la régénération remplace chaque étape par une nouvelle et perd ce que le pilote
    // avait saisi sur l'étape : stock en main, étape marquée achetée, ME et TE ajustés.
    public function testRegenerationCurrentlyReplacesEveryStepAndLosesWhatThePilotSetOnItIssue21(): void
    {
        $project = $this->factory->createProject($this->pilot, self::FRIGATE_TYPE_ID, 5, 10, 2.0, 20);
        $componentStep = $this->stepOfProduct($project, self::COMPONENT_TYPE_ID);
        $componentStep->setInStockQuantity(7)->setPurchased(true)->setMeLevel(8)->setTeLevel(16);
        $this->em->flush();
        $componentStepId = $componentStep->getId();

        $this->factory->regenerateSteps($project);
        $projectId = $project->getId();
        $this->flushAndClear();

        self::assertNull($this->em->find(IndustryProjectStep::class, $componentStepId));
        $project = $this->em->find(IndustryProject::class, $projectId);
        self::assertNotNull($project);
        $regenerated = $this->stepOfProduct($project, self::COMPONENT_TYPE_ID);
        self::assertSame([0, false, 10, 20, 18], [$regenerated->getInStockQuantity(), $regenerated->isPurchased(), $regenerated->getMeLevel(), $regenerated->getTeLevel(), $regenerated->getRuns()]);
    }

    /**
     * Each step as [sortOrder, blueprintTypeId, productTypeId, activityType, depth, runs, quantity, meLevel, teLevel,
     * splitGroupId, splitIndex, totalGroupRuns], in sortOrder.
     *
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

    private function stepOfProduct(IndustryProject $project, int $productTypeId): IndustryProjectStep
    {
        foreach ($project->getSteps() as $step) {
            if ($step->getProductTypeId() === $productTypeId) {
                return $step;
            }
        }

        self::fail("No step for product {$productTypeId}");
    }

    private function jobLinkCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.IndustryStepJobMatch::class.' m WHERE m.esiJobId = :esiJobId')
            ->setParameter('esiJobId', self::ESI_JOB_ID)
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

        $this->manufacturingRecipe(self::FRIGATE_BLUEPRINT_TYPE_ID, self::FRIGATE_TYPE_ID, self::COMPONENT_TYPE_ID, self::COMPONENTS_PER_FRIGATE_RUN);
        $this->manufacturingRecipe(self::COMPONENT_BLUEPRINT_TYPE_ID, self::COMPONENT_TYPE_ID, self::MINERAL_TYPE_ID, self::MINERALS_PER_COMPONENT_RUN);
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
