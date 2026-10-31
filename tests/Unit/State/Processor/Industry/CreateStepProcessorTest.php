<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Processor\Industry\CreateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateStepProcessor::class)]
final class CreateStepProcessorTest extends TestCase
{
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int HELIUM_FUEL_BLOCK_BLUEPRINT = 1_004_247;
    private const int HELIUM_FUEL_BLOCK_UNITS_PER_RUN = 40;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46166;
    private const int FERNITE_CARBIDE_UNITS_PER_RUN = 10_000;
    private const int PRODUCT_MANUFACTURED_AND_REACTED = 990_457;
    private const int BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED = 991_457;
    private const int FORMULA_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED = 992_457;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int PHENOLIC_COMPOSITES_REACTION_FORMULA = 46181;
    private const int TRITANIUM = 34;
    private const int TYPE_MISSING_FROM_THE_SDE = 999_999_999;
    private const string FERNITE_CARBIDE_SPLIT_GROUP = '5b0f6c1e-2a7d-4e93-8c41-7f2d9a6b3e15';
    private const string OTHER_SPLIT_GROUP = '9c3e7a52-6d18-4b0f-a2e9-1d5c8f4b7a60';
    private const string UNKNOWN_SPLIT_GROUP = 'e41a9d07-3c6b-4f25-9e8a-6b2d0c7f1a93';

    private User $pilot;
    private IndustryProject $project;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = (new IndustryProject())->setUser($this->pilot);
    }

    #[Group('issue-280')]
    public function testStepAddedForAManufacturedTypeIsMadeByItsBlueprintAndCountsItsUnitsPerRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $this->post($this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 5), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $entityManager);

        self::assertSame(
            [self::HELIUM_FUEL_BLOCK_BLUEPRINT, self::HELIUM_FUEL_BLOCK, 'manufacturing', 5, 200, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedForAReactedTypeIsMadeByItsReactionFormulaAndCountsItsUnitsPerRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $ferniteCarbideFormula = $this->activityProduct(self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_UNITS_PER_RUN);

        $this->post($this->typeEntered(self::FERNITE_CARBIDE, runs: 3), $this->productRepositoryWith($ferniteCarbideFormula), $entityManager);

        self::assertSame(
            [self::FERNITE_CARBIDE_REACTION_FORMULA, self::FERNITE_CARBIDE, 'reaction', 3, 30_000, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedForATypeManufacturedAndReactedIsMadeByItsBlueprint(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $productRepository = $this->productRepositoryWith(
            $this->activityProduct(self::FORMULA_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, IndustryActivityType::Reaction, self::PRODUCT_MANUFACTURED_AND_REACTED, 200),
            $this->activityProduct(self::BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, IndustryActivityType::Manufacturing, self::PRODUCT_MANUFACTURED_AND_REACTED, 40),
        );

        $this->post($this->typeEntered(self::PRODUCT_MANUFACTURED_AND_REACTED, runs: 2), $productRepository, $entityManager);

        self::assertSame(
            [self::BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, self::PRODUCT_MANUFACTURED_AND_REACTED, 'manufacturing', 2, 80, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedForATypeWithoutProducerIsRefusedWithoutAddingAnyStep(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->post($this->typeEntered(self::TRITANIUM, runs: 4), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $entityManager);
            self::fail('A step for a type without blueprint nor reaction formula must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('No blueprint or reaction found for product type ID 34', $refusal->getMessage());
            self::assertInstanceOf(ProducerMissingFromSdeException::class, $refusal->getPrevious());
        }

        self::assertCount(0, $this->project->getSteps());
    }

    #[Group('issue-280')]
    public function testStepAddedForATypeMissingFromTheSdeIsRefusedBeforeLookingForItsProducer(): void
    {
        $productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $productRepository->expects($this->never())->method('findBlueprintForProduct');
        $productRepository->expects($this->never())->method('findOneBy');

        try {
            $this->post($this->typeEntered(self::TYPE_MISSING_FROM_THE_SDE, runs: 1), $productRepository, $this->createStub(EntityManagerInterface::class));
            self::fail('A step for a type missing from the SDE must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Unknown type', $refusal->getMessage());
        }
    }

    #[Group('issue-280')]
    public function testStepAddedWithMeAndTeLevelsKeepsThemAndComesAfterTheLastStepOfTheProject(): void
    {
        $this->project->addStep((new IndustryProjectStep())->setSortOrder(4));
        $typeEntered = $this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 2);
        $typeEntered->meLevel = 10;
        $typeEntered->teLevel = 20;

        $this->post($typeEntered, $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class));

        $addedStep = $this->addedStep();
        self::assertSame([10, 20, 5, 80], [$addedStep->getMeLevel(), $addedStep->getTeLevel(), $addedStep->getSortOrder(), $addedStep->getQuantity()]);
    }

    #[Group('issue-280')]
    public function testStepAddedWithoutMeAndTeLevelsHasLevelsZero(): void
    {
        $this->post($this->typeEntered(self::FERNITE_CARBIDE, runs: 6), $this->productRepositoryWith($this->ferniteCarbideFormula()), $this->createStub(EntityManagerInterface::class));

        $addedStep = $this->addedStep();
        self::assertSame([0, 0], [$addedStep->getMeLevel(), $addedStep->getTeLevel()]);
    }

    #[Group('issue-190')]
    public function testStepAddedForAReactedTypeHasMeLevelZeroWhateverTheMeLevelEnteredAndKeepsItsTeLevel(): void
    {
        $typeEntered = $this->typeEntered(self::FERNITE_CARBIDE, runs: 3);
        $typeEntered->meLevel = 10;
        $typeEntered->teLevel = 4;

        $this->post($typeEntered, $this->productRepositoryWith($this->ferniteCarbideFormula()), $this->createStub(EntityManagerInterface::class));

        $addedStep = $this->addedStep();
        self::assertSame([0, 4, 30_000], [$addedStep->getMeLevel(), $addedStep->getTeLevel(), $addedStep->getQuantity()]);
    }

    #[Group('issue-190')]
    public function testStepAddedForAManufacturedTypeWithoutMeAndTeLevelsHasLevelsZero(): void
    {
        $this->post($this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 7), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class));

        $addedStep = $this->addedStep();
        self::assertSame([0, 0, 280], [$addedStep->getMeLevel(), $addedStep->getTeLevel(), $addedStep->getQuantity()]);
    }

    #[Group('issue-287')]
    public function testStepAddedForTheMaximumRunsOfAFormulaOfTenThousandUnitsPerRunCountsOneBillionUnits(): void
    {
        $this->post($this->typeEntered(self::FERNITE_CARBIDE, runs: 100_000), $this->productRepositoryWith($this->ferniteCarbideFormula()), $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [self::FERNITE_CARBIDE_REACTION_FORMULA, self::FERNITE_CARBIDE, 'reaction', 100_000, 1_000_000_000, 0],
            $this->blueprintProductActivityRunsQuantityAndDepthOf($this->addedStep()),
        );
    }

    #[Group('issue-280')]
    public function testStepAddedToAProjectWithoutStepsComesFirst(): void
    {
        $this->post($this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 9), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class));

        self::assertSame(1, $this->addedStep()->getSortOrder());
    }

    #[Group('issue-280')]
    public function testStepAddedComesAfterTheHighestSortOrderOfTheProjectWhateverTheOrderOfItsSteps(): void
    {
        $this->project->addStep((new IndustryProjectStep())->setSortOrder(5));
        $this->project->addStep((new IndustryProjectStep())->setSortOrder(2));

        $this->post($this->typeEntered(self::FERNITE_CARBIDE, runs: 1), $this->productRepositoryWith($this->ferniteCarbideFormula()), $this->createStub(EntityManagerInterface::class));

        self::assertSame(6, $this->addedStep()->getSortOrder());
    }

    #[Group('issue-191')]
    public function testJobAddedToASingleStepCountsTheUnitsPerRunOfItsBlueprint(): void
    {
        $singleStep = $this->heliumFuelBlockStep('manufacturing', runs: 7, quantity: 280);

        $this->post($this->stepEntered(runs: 3), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class), $singleStep);

        self::assertSame(
            [[7, 280, false, 0, 10], [3, 120, false, 1, 10]],
            array_map($this->runsQuantityRunsFixedSplitIndexAndTotalGroupRunsOf(...), [$singleStep, $this->addedStep()]),
        );
    }

    #[Group('issue-190')]
    public function testJobAddedToAReactionStepHasMeLevelZeroAndLeavesTheMeLevelOfTheStep(): void
    {
        $reactionStep = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::FERNITE_CARBIDE_REACTION_FORMULA)
            ->setProductTypeId(self::FERNITE_CARBIDE)
            ->setActivityType('reaction')
            ->setRuns(5)
            ->setQuantity(50_000)
            ->setDepth(0)
            ->setMeLevel(3)
            ->setTeLevel(6);
        $this->project->addStep($reactionStep);

        $this->post($this->stepEntered(runs: 2), $this->productRepositoryWith($this->ferniteCarbideFormula()), $this->createStub(EntityManagerInterface::class), $reactionStep);

        self::assertSame(
            [[3, 6], [0, 6, 20_000]],
            [[$reactionStep->getMeLevel(), $reactionStep->getTeLevel()], [$this->addedStep()->getMeLevel(), $this->addedStep()->getTeLevel(), $this->addedStep()->getQuantity()]],
        );
    }

    #[Group('issue-191')]
    public function testJobAddedToAStepWithAMatchedJobAPurchaseMarkAndStockStartsWithoutAnyOfThem(): void
    {
        $engagedStep = $this->heliumFuelBlockStep('manufacturing', runs: 7, quantity: 280)
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(7_191_001)->setRuns(7))
            ->setPurchased(true)
            ->setInStockQuantity(35)
            ->setJobMatchMode('manual');

        $this->post($this->stepEntered(runs: 3), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class), $engagedStep);

        self::assertSame(
            [[1, true, 35, 'manual'], [0, false, 0, 'auto']],
            array_map(
                static fn (IndustryProjectStep $step): array => [\count($step->getJobMatches()), $step->isPurchased(), $step->getInStockQuantity(), $step->getJobMatchMode()],
                [$engagedStep, $this->addedStep()],
            ),
        );
    }

    public function testStepAddedToASingleStepFormsWithItASplitGroupOfTheirTotalRuns(): void
    {
        $singleStep = $this->heliumFuelBlockStep('manufacturing', runs: 7, quantity: 280);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $result = $this->post($this->stepEntered(runs: 3), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $entityManager, $singleStep);

        $addedStep = $this->addedStep();
        self::assertNotNull($singleStep->getSplitGroupId());
        self::assertSame($singleStep->getSplitGroupId(), $addedStep->getSplitGroupId());
        self::assertSame([7, 280, 0, 10], $this->runsQuantitySplitIndexAndTotalGroupRunsOf($singleStep));
        self::assertSame([3, 1, 10], [$addedStep->getRuns(), $addedStep->getSplitIndex(), $addedStep->getTotalGroupRuns()]);
        self::assertCount(2, $this->project->getSteps());
        self::assertIsArray($result);
        self::assertSame(['newStep' => [3, 1], 'updatedStep' => [7, 0]], array_map($this->runsAndSplitIndexOfResource(...), $result));
    }

    public function testStepAddedToASingleCopyStepCountsOneBlueprintCopyPerRunAndCopiesItsBlueprintProductActivityDepthSortOrderLevelsAndStructure(): void
    {
        $singleStep = $this->heliumFuelBlockStep('copy', runs: 7, quantity: 7);

        $this->post($this->stepEntered(runs: 3), $this->createStub(IndustryActivityProductRepository::class), $this->createStub(EntityManagerInterface::class), $singleStep);

        self::assertSame([3, 3], [$this->addedStep()->getRuns(), $this->addedStep()->getQuantity()]);
        self::assertSame(
            [self::HELIUM_FUEL_BLOCK_BLUEPRINT, self::HELIUM_FUEL_BLOCK, 'copy', 2, 3, 8, 16, 'Raitaru'],
            $this->copiedFieldsOf($this->addedStep()),
        );
    }

    public function testStepAddedToAStepOfAnotherProjectIsRefusedAsStepNotFound(): void
    {
        $stepOfAnotherProject = (new IndustryProjectStep())->setRuns(7);
        (new IndustryProject())->setUser($this->pilot)->addStep($stepOfAnotherProject);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->post($this->stepEntered(runs: 3), $this->createStub(IndustryActivityProductRepository::class), $entityManager, $stepOfAnotherProject);
            self::fail('A step of another project must not receive a new job.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Step not found', $refusal->getMessage());
        }

        self::assertCount(0, $this->project->getSteps());
    }

    #[Group('issue-191')]
    public function testJobAddedToASplitGroupCountsTheUnitsPerRunOfItsFormulaAndAddsItsRunsToTheTotalGroupRuns(): void
    {
        [$firstJob, $secondJob] = $this->ferniteCarbideSplitGroup();

        $this->post($this->splitGroupEntered(self::FERNITE_CARBIDE_SPLIT_GROUP, runs: 4), $this->productRepositoryWith($this->ferniteCarbideFormula()), $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [[6, 60_000, 0, 16], [6, 60_000, 1, 16], [4, 40_000, 2, 16]],
            array_map($this->runsQuantitySplitIndexAndTotalGroupRunsOf(...), [$firstJob, $secondJob, $this->addedStep()]),
        );
    }

    #[Group('issue-190')]
    public function testJobAddedToASplitGroupComesAfterItsLastJobAndCopiesItsFirstJobExceptTheMeLevelOfAReaction(): void
    {
        [$firstJob, $secondJob] = $this->ferniteCarbideSplitGroup();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $result = $this->post($this->splitGroupEntered(self::FERNITE_CARBIDE_SPLIT_GROUP, runs: 4), $this->productRepositoryWith($this->ferniteCarbideFormula()), $entityManager);

        $addedStep = $this->addedStep();
        self::assertSame([self::FERNITE_CARBIDE_SPLIT_GROUP, 2], [$addedStep->getSplitGroupId(), $addedStep->getSplitIndex()]);
        self::assertSame(
            [self::FERNITE_CARBIDE_REACTION_FORMULA, self::FERNITE_CARBIDE, 'reaction', 1, 4, 0, 12, 'Tatara'],
            $this->copiedFieldsOf($addedStep),
        );
        self::assertSame([4, 4], [$firstJob->getMeLevel(), $secondJob->getMeLevel()]);
        self::assertCount(4, $this->project->getSteps());
        self::assertInstanceOf(ProjectStepResource::class, $result);
        self::assertSame([4, 2], $this->runsAndSplitIndexOfResource($result));
    }

    public function testStepAddedToAnUnknownSplitGroupIsRefusedAsSplitGroupNotFound(): void
    {
        $this->ferniteCarbideSplitGroup();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->post($this->splitGroupEntered(self::UNKNOWN_SPLIT_GROUP, runs: 4), $this->createStub(IndustryActivityProductRepository::class), $entityManager);
            self::fail('A step must not be added to an unknown split group.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Split group not found', $refusal->getMessage());
        }

        self::assertCount(3, $this->project->getSteps());
    }

    #[Group('issue-287')]
    public function testStepAddedToASplitGroupIdThatIsNotAUuidIsRefusedAsSplitGroupNotFound(): void
    {
        $this->ferniteCarbideSplitGroup();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->post($this->splitGroupEntered('g-1', runs: 4), $this->createStub(IndustryActivityProductRepository::class), $entityManager);
            self::fail('A step must not be added to a split group id that is not a UUID.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Split group not found', $refusal->getMessage());
        }

        self::assertCount(3, $this->project->getSteps());
    }

    public function testStepAddedWithoutTypeNorSplitGroupNorStepIsRefused(): void
    {
        $nothingEntered = new CreateStepInput();
        $nothingEntered->runs = 2;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('typeId, splitGroupId or stepId is required');

        $this->post($nothingEntered, $this->createStub(IndustryActivityProductRepository::class), $this->createStub(EntityManagerInterface::class));
    }

    public function testStepAddedWithAStepAlsoEnteringATypeAndASplitGroupJoinsTheStep(): void
    {
        $singleStep = $this->heliumFuelBlockStep('manufacturing', runs: 7, quantity: 280);
        $everythingEntered = $this->stepEntered(runs: 3);
        $everythingEntered->typeId = self::FERNITE_CARBIDE;
        $everythingEntered->splitGroupId = self::UNKNOWN_SPLIT_GROUP;

        $this->post($everythingEntered, $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class), $singleStep);

        self::assertSame([self::HELIUM_FUEL_BLOCK, 1, 10], [$this->addedStep()->getProductTypeId(), $this->addedStep()->getSplitIndex(), $this->addedStep()->getTotalGroupRuns()]);
    }

    public function testStepAddedToAProjectOfAnotherPilotIsRefusedAsProjectNotFound(): void
    {
        $this->project->setUser(new User());

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->post($this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 5), $this->productRepositoryWith($this->heliumFuelBlockBlueprint()), $this->createStub(EntityManagerInterface::class));
    }

    #[Group('issue-200')]
    public function testProjectIdThatIsNotAUuidIsNotFoundWithoutReadingTheProjects(): void
    {
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects($this->never())->method('find');
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->processor($projectRepository, $stepRepository, $this->createStub(IndustryActivityProductRepository::class), $this->entityManagerNeverWriting())
            ->process($this->typeEntered(self::HELIUM_FUEL_BLOCK, runs: 5), new Post(), ['id' => 'xyz']);
    }

    #[Group('issue-200')]
    public function testStepIdOfTheBodyThatIsNotAUuidIsNotFoundWithoutReadingTheSteps(): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects($this->never())->method('find');
        $stepIdNotAUuid = $this->stepEntered(runs: 3);
        $stepIdNotAUuid->stepId = 's-1';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Step not found');

        $this->processor($projectRepository, $stepRepository, $this->createStub(IndustryActivityProductRepository::class), $this->entityManagerNeverWriting())
            ->process($stepIdNotAUuid, new Post(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    private function typeEntered(int $typeId, int $runs): CreateStepInput
    {
        $input = new CreateStepInput();
        $input->typeId = $typeId;
        $input->runs = $runs;

        return $input;
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

    private function heliumFuelBlockStep(string $activityType, int $runs, int $quantity): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setBlueprintTypeId(self::HELIUM_FUEL_BLOCK_BLUEPRINT)
            ->setProductTypeId(self::HELIUM_FUEL_BLOCK)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth(2)
            ->setSortOrder(3)
            ->setMeLevel(8)
            ->setTeLevel(16)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Raitaru'));
        $this->project->addStep($step);

        return $step;
    }

    private function ferniteCarbideSplitGroup(): array
    {
        $this->project->addStep((new IndustryProjectStep())
            ->setBlueprintTypeId(self::PHENOLIC_COMPOSITES_REACTION_FORMULA)
            ->setProductTypeId(self::PHENOLIC_COMPOSITES)
            ->setActivityType('reaction')
            ->setRuns(9)
            ->setQuantity(19_800)
            ->setDepth(0)
            ->setSplitGroupId(self::OTHER_SPLIT_GROUP)
            ->setSplitIndex(3)
            ->setTotalGroupRuns(30));
        $jobs = [];
        foreach ([0, 1] as $splitIndex) {
            $jobs[] = (new IndustryProjectStep())
                ->setBlueprintTypeId(self::FERNITE_CARBIDE_REACTION_FORMULA)
                ->setProductTypeId(self::FERNITE_CARBIDE)
                ->setActivityType('reaction')
                ->setRuns(6)
                ->setQuantity(60_000)
                ->setDepth(1)
                ->setSortOrder(4)
                ->setMeLevel(4)
                ->setTeLevel(12)
                ->setStructureConfig((new IndustryStructureConfig())->setName('Tatara'))
                ->setSplitGroupId(self::FERNITE_CARBIDE_SPLIT_GROUP)
                ->setSplitIndex($splitIndex)
                ->setTotalGroupRuns(12);
        }
        $this->project->addStep($jobs[1]);
        $this->project->addStep($jobs[0]);

        return $jobs;
    }

    private function runsQuantitySplitIndexAndTotalGroupRunsOf(IndustryProjectStep $step): array
    {
        return [$step->getRuns(), $step->getQuantity(), $step->getSplitIndex(), $step->getTotalGroupRuns()];
    }

    private function runsQuantityRunsFixedSplitIndexAndTotalGroupRunsOf(IndustryProjectStep $step): array
    {
        return [$step->getRuns(), $step->getQuantity(), $step->isRunsFixed(), $step->getSplitIndex(), $step->getTotalGroupRuns()];
    }

    private function copiedFieldsOf(IndustryProjectStep $step): array
    {
        return [
            $step->getBlueprintTypeId(),
            $step->getProductTypeId(),
            $step->getActivityType(),
            $step->getDepth(),
            $step->getSortOrder(),
            $step->getMeLevel(),
            $step->getTeLevel(),
            $step->getStructureConfig()?->getName(),
        ];
    }

    private function runsAndSplitIndexOfResource(ProjectStepResource $resource): array
    {
        return [$resource->runs, $resource->splitIndex];
    }

    private function ferniteCarbideFormula(): IndustryActivityProduct
    {
        return $this->activityProduct(self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_UNITS_PER_RUN);
    }

    private function addedStep(): IndustryProjectStep
    {
        $addedStep = $this->project->getSteps()->last();
        self::assertInstanceOf(IndustryProjectStep::class, $addedStep);

        return $addedStep;
    }

    private function blueprintProductActivityRunsQuantityAndDepthOf(IndustryProjectStep $step): array
    {
        return [$step->getBlueprintTypeId(), $step->getProductTypeId(), $step->getActivityType(), $step->getRuns(), $step->getQuantity(), $step->getDepth()];
    }

    private function heliumFuelBlockBlueprint(): IndustryActivityProduct
    {
        return $this->activityProduct(self::HELIUM_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::HELIUM_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK_UNITS_PER_RUN);
    }

    private function post(CreateStepInput $input, IndustryActivityProductRepository $productRepository, EntityManagerInterface $entityManager, ?IndustryProjectStep $stepFound = null): ProjectStepResource|array
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($stepFound);

        return $this->processor($projectRepository, $stepRepository, $productRepository, $entityManager)
            ->process($input, new Post(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    private function processor(IndustryProjectRepository $projectRepository, IndustryProjectStepRepository $stepRepository, IndustryActivityProductRepository $productRepository, EntityManagerInterface $entityManager): CreateStepProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (int $typeId): ?InvType => $typeId === self::TYPE_MISSING_FROM_THE_SDE ? null : (new InvType())->setTypeId($typeId),
        );
        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('stepToResource')->willReturnCallback(static function (IndustryProjectStep $step): ProjectStepResource {
            $resource = new ProjectStepResource();
            $resource->runs = $step->getRuns();
            $resource->splitIndex = $step->getSplitIndex();

            return $resource;
        });

        return new CreateStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $invTypeRepository,
            $productRepository,
            new IndustryStepCalculator($this->createStub(IndustryCalculationService::class), $this->createStub(IndustryActivityMaterialRepository::class), $productRepository, $entityManager),
            $mapper,
            $entityManager,
        );
    }

    private function entityManagerNeverWriting(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        return $entityManager;
    }

    private function productRepositoryWith(IndustryActivityProduct ...$products): IndustryActivityProductRepository
    {
        $matches = static fn (IndustryActivityProduct $product, array $criteria): bool => array_intersect_assoc([
            'typeId' => $product->getTypeId(),
            'activityId' => $product->getActivityId(),
            'productTypeId' => $product->getProductTypeId(),
        ], $criteria) == $criteria;
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($products, $matches): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($matches($product, $criteria)) {
                        return $product;
                    }
                }

                return null;
            },
        );
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($products, $matches): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($matches($product, ['productTypeId' => $productTypeId, 'activityId' => $activityId])) {
                        return $product;
                    }
                }

                return null;
            },
        );

        return $repository;
    }

    private function activityProduct(int $blueprintTypeId, IndustryActivityType $activity, int $productTypeId, int $unitsPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activity->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity($unitsPerRun);
    }
}
