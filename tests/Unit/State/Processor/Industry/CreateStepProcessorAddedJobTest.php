<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Processor\Industry\CreateStepProcessor;
use App\State\Processor\Industry\UpdateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateStepProcessor::class)]
#[Group('issue-191')]
final class CreateStepProcessorAddedJobTest extends TestCase
{
    use BuildsStepProcessorsOnGoldenSde;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;
    private const int FORMULA_MISSING_FROM_THE_SDE = 999_999_998;
    private const string FERNITE_ALLOY_SPLIT_GROUP = 'fernite-alloy';

    private User $pilot;
    private IndustryProject $project;
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = (new IndustryProject())->setUser($this->pilot);
    }

    public function testJobAddedToASingleStepFixesBothJobsAtTheirRunsAndRecalculatesTheMaterialsJobByJob(): void
    {
        [, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfFourRuns();

        $response = $this->post($this->stepEntered(runs: 3), $ferniteAlloy, $this->createStub(EntityManagerInterface::class));

        $addedJob = $this->addedJob();
        self::assertSame($ferniteAlloy->getSplitGroupId(), $addedJob->getSplitGroupId());
        self::assertSame(
            [[2, 400, true, 0, 5], [3, 600, true, 1, 5], [2, 45]],
            [$this->jobValuesOf($ferniteAlloy), $this->jobValuesOf($addedJob), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
        self::assertIsArray($response);
        self::assertSame(['newStep' => [3, 1], 'updatedStep' => [2, 0]], array_map($this->runsAndSplitIndexOfResource(...), $response));
    }

    public function testJobAddedToTheRootStepIsNotFixedAndItsRunsReachTheMaterialSteps(): void
    {
        [$ferniteCarbide, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfFourRuns();

        $this->post($this->stepEntered(runs: 2), $ferniteCarbide, $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [[4, 40_000, false, 0, 6], [2, 20_000, false, 1, 6], [3, 600], [2, 45]],
            [$this->jobValuesOf($ferniteCarbide), $this->jobValuesOf($this->addedJob()), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
    }

    public function testJobAddedToASplitGroupFixesEveryJobOfTheGroupAndAddsItsRunsToTheTotalGroupRuns(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns(hydrogenFuelBlockBlueprintTypeId: self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET);

        $response = $this->post($this->splitGroupEntered(self::FERNITE_ALLOY_SPLIT_GROUP, runs: 4), null, $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [[3, 600, true, 0, 9], [2, 400, true, 1, 9], [4, 800, true, 2, 9], [3, 95]],
            [$this->jobValuesOf($firstAlloyJob), $this->jobValuesOf($secondAlloyJob), $this->jobValuesOf($this->addedJob()), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
        self::assertInstanceOf(ProjectStepResource::class, $response);
        self::assertSame([4, 2], $this->runsAndSplitIndexOfResource($response));
    }

    public function testJobAddedThroughAJobOfASplitGroupJoinsThatGroupInsteadOfSplittingItInTwo(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns(hydrogenFuelBlockBlueprintTypeId: self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET);

        $response = $this->post($this->stepEntered(runs: 4), $firstAlloyJob, $this->createStub(EntityManagerInterface::class));

        $addedJob = $this->addedJob();
        self::assertSame(
            [self::FERNITE_ALLOY_SPLIT_GROUP, self::FERNITE_ALLOY_SPLIT_GROUP, self::FERNITE_ALLOY_SPLIT_GROUP],
            [$firstAlloyJob->getSplitGroupId(), $secondAlloyJob->getSplitGroupId(), $addedJob->getSplitGroupId()],
        );
        self::assertSame(
            [[3, 600, true, 0, 9], [2, 400, true, 1, 9], [4, 800, true, 2, 9], [3, 95]],
            [$this->jobValuesOf($firstAlloyJob), $this->jobValuesOf($secondAlloyJob), $this->jobValuesOf($addedJob), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
        self::assertIsArray($response);
        self::assertSame(['newStep' => [4, 2], 'updatedStep' => [3, 0]], array_map($this->runsAndSplitIndexOfResource(...), $response));
    }

    public function testJobsOfASplitGroupReleasedAfterAnAddedJobShareTheNeedAgainWithoutRemovingAnyJob(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns(hydrogenFuelBlockBlueprintTypeId: self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET);
        $this->post($this->splitGroupEntered(self::FERNITE_ALLOY_SPLIT_GROUP, runs: 4), null, $this->createStub(EntityManagerInterface::class));
        $addedJob = $this->addedJob();
        $runsReleased = new UpdateStepInput();
        $runsReleased->runsFixed = false;

        $this->updateStepProcessor($addedJob, $this->createStub(EntityManagerInterface::class))
            ->process($runsReleased, new Patch(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);

        self::assertSame(
            [[2, 400, false, 0, 5], [2, 400, false, 1, 5], [1, 200, false, 2, 5], [2, 75]],
            [$this->jobValuesOf($firstAlloyJob), $this->jobValuesOf($secondAlloyJob), $this->jobValuesOf($addedJob), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
    }

    public function testJobAddedToAProjectWithAMaterialStepWithoutProductInTheSdeIsRefusedWithoutFlushing(): void
    {
        [, $firstAlloyJob] = $this->ferniteCarbideProjectOfTenRuns(hydrogenFuelBlockBlueprintTypeId: self::BLUEPRINT_MISSING_FROM_THE_SDE);

        try {
            $this->post($this->stepEntered(runs: 4), $firstAlloyJob, $this->entityManagerCountingTheFlushes());
            self::fail('A cascade reaching a material step without product in the SDE must refuse the added job.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 999999999 missing from the SDE', $refusal->getMessage());
        }

        self::assertSame(0, $this->flushes);
    }

    public function testJobAddedToAStepWhoseBlueprintHasNoProductForItsActivityIsRefusedWithoutAddingAnyJob(): void
    {
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::FORMULA_MISSING_FROM_THE_SDE, 'reaction', runs: 4, quantity: 8_800, depth: 0, meLevel: 0, sortOrder: 0);

        try {
            $this->post($this->stepEntered(runs: 2), $phenolicComposites, $this->entityManagerCountingTheFlushes());
            self::fail('A job added to a step whose blueprint has no product in the SDE must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 999999998 missing from the SDE', $refusal->getMessage());
        }

        self::assertSame([1, null, 0], [\count($this->project->getSteps()), $phenolicComposites->getSplitGroupId(), $this->flushes]);
    }

    private function ferniteCarbideProjectOfFourRuns(): array
    {
        return [
            $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 4, quantity: 40_000, depth: 0, meLevel: 0, sortOrder: 0),
            $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, sortOrder: 1),
            $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 1, quantity: 30, depth: 2, meLevel: 10, sortOrder: 2),
        ];
    }

    private function ferniteCarbideProjectOfTenRuns(int $hydrogenFuelBlockBlueprintTypeId): array
    {
        return [
            $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 10, quantity: 100_000, depth: 0, meLevel: 0, sortOrder: 0),
            $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, sortOrder: 1)
                ->setSplitGroupId(self::FERNITE_ALLOY_SPLIT_GROUP)->setSplitIndex(0)->setTotalGroupRuns(5),
            $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, sortOrder: 1)
                ->setSplitGroupId(self::FERNITE_ALLOY_SPLIT_GROUP)->setSplitIndex(1)->setTotalGroupRuns(5),
            $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, $hydrogenFuelBlockBlueprintTypeId, 'manufacturing', runs: 2, quantity: 75, depth: 2, meLevel: 10, sortOrder: 2),
        ];
    }

    private function stepOfTheProject(int $productTypeId, int $blueprintTypeId, string $activityType, int $runs, int $quantity, int $depth, int $meLevel, int $sortOrder): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setMeLevel($meLevel)
            ->setSortOrder($sortOrder);
        $this->project->addStep($step);

        return $step;
    }

    private function stepEntered(int $runs): CreateStepInput
    {
        $input = new CreateStepInput();
        $input->stepId = Uuid::v4()->toRfc4122();
        $input->runs = $runs;

        return $input;
    }

    private function splitGroupEntered(string $splitGroupId, int $runs): CreateStepInput
    {
        $input = new CreateStepInput();
        $input->splitGroupId = $splitGroupId;
        $input->runs = $runs;

        return $input;
    }

    private function addedJob(): IndustryProjectStep
    {
        $addedJob = $this->project->getSteps()->last();
        self::assertInstanceOf(IndustryProjectStep::class, $addedJob);

        return $addedJob;
    }

    private function jobValuesOf(IndustryProjectStep $job): array
    {
        return [$job->getRuns(), $job->getQuantity(), $job->isRunsFixed(), $job->getSplitIndex(), $job->getTotalGroupRuns()];
    }

    private function runsAndQuantityOf(IndustryProjectStep $step): array
    {
        return [$step->getRuns(), $step->getQuantity()];
    }

    private function runsAndSplitIndexOfResource(ProjectStepResource $resource): array
    {
        return [$resource->runs, $resource->splitIndex];
    }

    private function entityManagerCountingTheFlushes(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $entityManager;
    }

    private function post(CreateStepInput $input, ?IndustryProjectStep $stepFound, EntityManagerInterface $entityManager): ProjectStepResource|array
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($stepFound);
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();

        $processor = new CreateStepProcessor(
            $this->securityOfThePilot(),
            $projectRepository,
            $stepRepository,
            $this->createStub(InvTypeRepository::class),
            $productRepository,
            $this->stepCalculatorOnGoldenSde($productRepository, $this->createStub(IndustryStructureConfigRepository::class), $entityManager),
            $this->mapper(),
            $entityManager,
        );

        return $processor->process($input, new Post(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    private function updateStepProcessor(IndustryProjectStep $stepFound, EntityManagerInterface $entityManager): UpdateStepProcessor
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($stepFound);
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);

        return new UpdateStepProcessor(
            $this->securityOfThePilot(),
            $projectRepository,
            $stepRepository,
            $productRepository,
            $structureConfigRepository,
            $this->stepCalculatorOnGoldenSde($productRepository, $structureConfigRepository, $entityManager),
            $this->mapper(),
            $entityManager,
        );
    }

    private function securityOfThePilot(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);

        return $security;
    }

    private function mapper(): IndustryResourceMapper
    {
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturnCallback(static function (IndustryProjectStep $step): ProjectStepResource {
            $resource = new ProjectStepResource();
            $resource->runs = $step->getRuns();
            $resource->splitIndex = $step->getSplitIndex();

            return $resource;
        });

        return $mapper;
    }
}
