<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Processor\Industry\UpdateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\BuildsProjectOnGoldenSde;
use App\Tests\Unit\Service\Industry\TataraAndRaitaruBonusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateStepProcessor::class)]
final class UpdateStepProcessorTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int FERNITE_ALLOY_OUTPUT_PER_RUN = 200;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;
    private const int FORMULA_MISSING_FROM_THE_SDE = 999_999_998;

    private User $pilot;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = (new IndustryProject())->setUser($this->pilot);
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnAStepWhoseBlueprintHasNoProductForItsActivityAreRefusedWithoutChangingTheStep(): void
    {
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::FORMULA_MISSING_FROM_THE_SDE, 'reaction', runs: 4, quantity: 8_800, depth: 0, meLevel: 0);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->patch($phenolicComposites, $this->runsEntered(5), $entityManager);
            self::fail('Runs entered on a step whose blueprint has no product in the SDE must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 999999998 missing from the SDE', $refusal->getMessage());
        }

        self::assertSame([4, 8_800], [$phenolicComposites->getRuns(), $phenolicComposites->getQuantity()]);
    }

    #[Group('issue-137')]
    public function testMeLevelEnteredOnTheRootStepOfAProjectWithAChildWithoutProductInTheSdeIsRefusedWithoutFlushingAnyStep(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10);
        $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::BLUEPRINT_MISSING_FROM_THE_SDE, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $meLevelEntered = new UpdateStepInput();
        $meLevelEntered->meLevel = 8;

        try {
            $this->patch($quantumMicroprocessor, $meLevelEntered, $entityManager);
            self::fail('A cascade reaching a child without product in the SDE must refuse the update.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Product of blueprint 999999999 missing from the SDE', $refusal->getMessage());
        }
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnAReactionStepGiveItTheUnitsPerRunOfItsReactionFormula(): void
    {
        $ferniteCarbide = $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 20_000, depth: 0, meLevel: 0);

        $this->patch($ferniteCarbide, $this->runsEntered(3), $this->createStub(EntityManagerInterface::class));

        self::assertSame([3, 30_000], [$ferniteCarbide->getRuns(), $ferniteCarbide->getQuantity()]);
    }

    #[Group('issue-279')]
    public function testRunsEnteredOnACopyStepCountOneBlueprintCopyPerRun(): void
    {
        $hydrogenFuelBlockCopy = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'copy', runs: 6, quantity: 6, depth: 1, meLevel: 0);

        $this->patch($hydrogenFuelBlockCopy, $this->runsEntered(10), $this->createStub(EntityManagerInterface::class));

        self::assertSame([10, 10], [$hydrogenFuelBlockCopy->getRuns(), $hydrogenFuelBlockCopy->getQuantity()]);
    }

    #[Group('issue-279')]
    public function testRunsEnteredOnACopyStepWhoseBlueprintHasNoProductInTheSdeCountOneBlueprintCopyPerRun(): void
    {
        $hydrogenFuelBlockCopy = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::BLUEPRINT_MISSING_FROM_THE_SDE, 'copy', runs: 6, quantity: 6, depth: 1, meLevel: 0);

        $this->patch($hydrogenFuelBlockCopy, $this->runsEntered(10), $this->createStub(EntityManagerInterface::class));

        self::assertSame([10, 10], [$hydrogenFuelBlockCopy->getRuns(), $hydrogenFuelBlockCopy->getQuantity()]);
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnARootStepAreFlushedOnceAfterTheCascadeRecalculatedItsMaterialStep(): void
    {
        $ferniteCarbide = $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 20_000, depth: 0, meLevel: 0);
        $hydrogenFuelBlock = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 1, quantity: 10, depth: 1, meLevel: 0);
        $stepsAtFlush = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush')->willReturnCallback(
            static function () use ($ferniteCarbide, $hydrogenFuelBlock, &$stepsAtFlush): void {
                $stepsAtFlush = [
                    [$ferniteCarbide->getRuns(), $ferniteCarbide->getQuantity()],
                    [$hydrogenFuelBlock->getRuns(), $hydrogenFuelBlock->getQuantity()],
                ];
            },
        );

        $updatedSteps = $this->patch($ferniteCarbide, $this->runsEntered(3), $entityManager, $this->createStub(EntityManagerInterface::class));

        self::assertSame([[3, 30_000], [1, 15]], $stepsAtFlush);
        self::assertSame([[self::FERNITE_CARBIDE, 30_000], [self::HYDROGEN_FUEL_BLOCK, 15]], $this->productAndQuantityOf($updatedSteps));
    }

    #[Group('issue-137')]
    public function testMeLevelEnteredOnTheRootStepRecalculatesTheQuantityOfItsMaterialStep(): void
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10);
        $titaniumCarbide = $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::TITANIUM_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 1, quantity: 490, depth: 1, meLevel: 0);
        $meLevelEntered = new UpdateStepInput();
        $meLevelEntered->meLevel = 8;

        $updatedSteps = $this->patch($quantumMicroprocessor, $meLevelEntered, $this->createStub(EntityManagerInterface::class));

        self::assertSame([8, 1, 501], [$quantumMicroprocessor->getMeLevel(), $titaniumCarbide->getRuns(), $titaniumCarbide->getQuantity()]);
        self::assertSame([[self::QUANTUM_MICROPROCESSOR, 32], [self::TITANIUM_CARBIDE, 501]], $this->productAndQuantityOf($updatedSteps));
    }

    #[Group('issue-137')]
    public function testRunsEnteredOnAStepWithoutMaterialStepReturnTheResourceOfThatStepAlone(): void
    {
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::PHENOLIC_COMPOSITES + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 2, quantity: 4_400, depth: 0, meLevel: 0);

        $updatedStep = $this->patch($phenolicComposites, $this->runsEntered(5), $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([self::PHENOLIC_COMPOSITES, 11_000], [$updatedStep->productTypeId, $updatedStep->quantity]);
    }

    public function testResponseToRunsEnteredOnTheRootStepListsTheKeptJobsOfItsSplitChildGroupWithoutTheSurplusJob(): void
    {
        $ferniteCarbide = $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 20, quantity: 200_000, depth: 0, meLevel: 0);
        $alloyJobs = array_map(
            fn (int $splitIndex): IndustryProjectStep => $this->jobOfTheFerniteAlloyGroup($splitIndex, runs: 10, totalGroupRuns: 30),
            [0, 1, 2],
        );

        $updatedSteps = $this->patch($ferniteCarbide, $this->runsEntered(4), $this->createStub(EntityManagerInterface::class));

        self::assertSame([[self::FERNITE_CARBIDE, 40_000], [self::FERNITE_ALLOY, 200], [self::FERNITE_ALLOY, 200]], $this->productAndQuantityOf($updatedSteps));
        self::assertSame([true, true, false], array_map(fn (IndustryProjectStep $job): bool => $this->project->getSteps()->contains($job), $alloyJobs));
    }

    public function testReleasingTheFixedRunsOfAJobThatTheNeedNoLongerRunsRemovesItFromTheProjectAndFromTheResponse(): void
    {
        $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 1, quantity: 10_000, depth: 0, meLevel: 0);
        $firstAlloyJob = $this->jobOfTheFerniteAlloyGroup(0, runs: 3, totalGroupRuns: 5);
        $fixedAlloyJob = $this->jobOfTheFerniteAlloyGroup(1, runs: 2, totalGroupRuns: 5)->setRunsFixed(true);
        $runsReleased = new UpdateStepInput();
        $runsReleased->runsFixed = false;

        $updatedSteps = $this->patch($fixedAlloyJob, $runsReleased, $this->createStub(EntityManagerInterface::class));

        self::assertSame([[self::FERNITE_ALLOY, 100]], $this->productAndQuantityOf($updatedSteps));
        self::assertSame(
            [false, [1, 100, null, 0, null]],
            [
                $this->project->getSteps()->contains($fixedAlloyJob),
                [$firstAlloyJob->getRuns(), $firstAlloyJob->getQuantity(), $firstAlloyJob->getSplitGroupId(), $firstAlloyJob->getSplitIndex(), $firstAlloyJob->getTotalGroupRuns()],
            ],
        );
    }

    public function testEditedJobRemovedAsASurplusJobWhileNoOtherStepChangesIsNotInTheResponse(): void
    {
        $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 4, quantity: 40_000, depth: 0, meLevel: 0);
        $firstAlloyJob = $this->jobOfTheFerniteAlloyGroup(0, runs: 1, totalGroupRuns: 2);
        $secondAlloyJob = $this->jobOfTheFerniteAlloyGroup(1, runs: 1, totalGroupRuns: 2);
        $alloyJobWithoutRuns = $this->jobOfTheFerniteAlloyGroup(2, runs: 0, totalGroupRuns: 2);
        $meLevelEntered = new UpdateStepInput();
        $meLevelEntered->meLevel = 2;

        $updatedSteps = $this->patch($alloyJobWithoutRuns, $meLevelEntered, $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [[true, true, false], [1, 200, 0, 2], [1, 200, 1, 2]],
            [
                array_map(fn (IndustryProjectStep $job): bool => $this->project->getSteps()->contains($job), [$firstAlloyJob, $secondAlloyJob, $alloyJobWithoutRuns]),
                [$firstAlloyJob->getRuns(), $firstAlloyJob->getQuantity(), $firstAlloyJob->getSplitIndex(), $firstAlloyJob->getTotalGroupRuns()],
                [$secondAlloyJob->getRuns(), $secondAlloyJob->getQuantity(), $secondAlloyJob->getSplitIndex(), $secondAlloyJob->getTotalGroupRuns()],
            ],
        );
        self::assertSame([], $updatedSteps);
    }

    public function testReleasingKeepsTheCommittedEditedJobAndRemovesTheSmallerSplitIndex(): void
    {
        $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 4, quantity: 40_000, depth: 0, meLevel: 0);
        $alloyJobWithoutCommitment = $this->jobOfTheFerniteAlloyGroup(0, runs: 3, totalGroupRuns: 9)->setRunsFixed(true);
        $editedAlloyJob = $this->jobOfTheFerniteAlloyGroup(1, runs: 2, totalGroupRuns: 9)->setRunsFixed(true)
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_131_601)->setRuns(2));
        $absorbingAlloyJob = $this->jobOfTheFerniteAlloyGroup(2, runs: 2, totalGroupRuns: 9)->setRunsFixed(true)
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_131_602)->setRuns(2))
            ->setInStockQuantity(15);
        $committedSurplusAlloyJob = $this->jobOfTheFerniteAlloyGroup(3, runs: 2, totalGroupRuns: 9)->setRunsFixed(true)
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_131_603)->setRuns(2))
            ->setPurchased(true);
        $runsReleased = new UpdateStepInput();
        $runsReleased->runsFixed = false;

        $updatedSteps = $this->patch($editedAlloyJob, $runsReleased, $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [false, true, true, false],
            array_map(fn (IndustryProjectStep $job): bool => $this->project->getSteps()->contains($job), [$alloyJobWithoutCommitment, $editedAlloyJob, $absorbingAlloyJob, $committedSurplusAlloyJob]),
        );
        self::assertSame(
            [[1, 200, false, 'fernite-alloy', 0, 2, [7_131_601], 0, false], [1, 200, false, 'fernite-alloy', 1, 2, [7_131_602, 7_131_603], 15, true]],
            array_map(
                static fn (IndustryProjectStep $job): array => [
                    $job->getRuns(),
                    $job->getQuantity(),
                    $job->isRunsFixed(),
                    $job->getSplitGroupId(),
                    $job->getSplitIndex(),
                    $job->getTotalGroupRuns(),
                    array_values(array_map(static fn (IndustryStepJobMatch $matchedJob): int => $matchedJob->getEsiJobId(), $job->getJobMatches()->toArray())),
                    $job->getInStockQuantity(),
                    $job->isPurchased(),
                ],
                [$editedAlloyJob, $absorbingAlloyJob],
            ),
        );
        self::assertSame([[self::FERNITE_ALLOY, 200], [self::FERNITE_ALLOY, 200]], $this->productAndQuantityOf($updatedSteps));
    }

    #[Group('issue-200')]
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjectsNorTheSteps(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');
        $processor = new UpdateStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryStepCalculator::class),
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $processor->process($this->runsEntered(7), new Patch(), ['id' => 'not-a-uuid', 'stepId' => '0192f0c4-7b1e-7a3c-9d2e-200000000011']);
    }

    private function stepOfTheProject(int $productTypeId, int $blueprintTypeId, string $activityType, int $runs, int $quantity, int $depth, int $meLevel): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setMeLevel($meLevel);
        $this->project->addStep($step);

        return $step;
    }

    private function jobOfTheFerniteAlloyGroup(int $splitIndex, int $runs, int $totalGroupRuns): IndustryProjectStep
    {
        return $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: $runs, quantity: $runs * self::FERNITE_ALLOY_OUTPUT_PER_RUN, depth: 1, meLevel: 0)
            ->setSplitGroupId('fernite-alloy')
            ->setSplitIndex($splitIndex)
            ->setTotalGroupRuns($totalGroupRuns);
    }

    private function runsEntered(int $runs): UpdateStepInput
    {
        $input = new UpdateStepInput();
        $input->runs = $runs;

        return $input;
    }

    private function productAndQuantityOf(ProjectStepResource|array $updatedSteps): array
    {
        self::assertIsList($updatedSteps);

        return array_map(static fn (ProjectStepResource $resource): array => [$resource->productTypeId, $resource->quantity], $updatedSteps);
    }

    private function patch(IndustryProjectStep $step, UpdateStepInput $input, EntityManagerInterface $entityManager, ?EntityManagerInterface $calculatorEntityManager = null): ProjectStepResource|array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($step);
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturnCallback(static function (IndustryProjectStep $mappedStep): ProjectStepResource {
            $resource = new ProjectStepResource();
            $resource->productTypeId = $mappedStep->getProductTypeId();
            $resource->quantity = $mappedStep->getQuantity();

            return $resource;
        });
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();
        $calculationService = $this->calculationServiceOnGoldenSde($this->bonusServiceWithoutStructure(), $structureConfigRepository);

        $processor = new UpdateStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $productRepository,
            $structureConfigRepository,
            new IndustryStepCalculator($calculationService, $this->materialRepositoryOnGoldenSde(), $productRepository, $calculatorEntityManager ?? $entityManager),
            $mapper,
            $entityManager,
        );
        return $processor->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }

    private function productRepositoryOfTheGoldenBlueprints(): IndustryActivityProductRepository
    {
        $productOf = static function (int $blueprintTypeId, int $activityId): ?IndustryActivityProduct {
            $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
            $recipe = self::goldenRecipes()[$productTypeId] ?? null;
            if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                return null;
            }

            return self::activityProduct($productTypeId, $recipe);
        };
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivityProduct => $productOf($criteria['typeId'], $criteria['activityId']),
        );
        $repository->method('findProductsForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($productOf): array {
                $products = [];
                foreach (array_unique($blueprintTypeIds) as $blueprintTypeId) {
                    foreach (array_unique($activityIds) as $activityId) {
                        $product = $productOf($blueprintTypeId, $activityId);
                        if ($product !== null) {
                            $products[$blueprintTypeId.'-'.$activityId] = $product;
                        }
                    }
                }

                return $products;
            },
        );

        return $repository;
    }

    private function bonusServiceWithoutStructure(): TataraAndRaitaruBonusService
    {
        return new TataraAndRaitaruBonusService(
            static fn (): bool => false,
            static fn (int $typeId): bool => (self::goldenRecipes()[$typeId]['activity'] ?? null) === 'reaction',
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }
}
