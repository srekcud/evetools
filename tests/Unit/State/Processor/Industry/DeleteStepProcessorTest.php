<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Processor\Industry\DeleteStepProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeleteStepProcessor::class)]
#[Group('issue-202')]
final class DeleteStepProcessorTest extends TestCase
{
    use BuildsStepProcessorsOnGoldenSde;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;
    private const string FERNITE_CARBIDE_SPLIT_GROUP = 'fernite-carbide';
    private const string FERNITE_ALLOY_SPLIT_GROUP = 'fernite-alloy';
    private const string LINKED_TO_AN_ESI_JOB = 'Cannot delete a step linked to an ESI job';
    private const string LAST_STEP_OF_THE_PROJECT_PRODUCT = 'Cannot delete the last step of the project product';
    private const string HOLDING_PURCHASES_STOCK_OR_A_PURCHASED_MARK = 'Cannot delete a step holding purchases, stock or a purchased mark';

    private User $pilot;
    private IndustryProject $project;
    private array $projectAtEachFlush = [];
    private array $removedEntities = [];

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = (new IndustryProject())->setUser($this->pilot)->setProductTypeId(self::FERNITE_CARBIDE);
    }

    public function testDeletedJobOfASplitGroupOfTwoDissolvesTheKeptJobWhichTakesTheWholeNeedAndIsSavedWithTheCascade(): void
    {
        [$ferniteCarbide, $firstAlloyJob, $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns();

        $this->delete($secondAlloyJob);

        self::assertSame(
            [[5, 1_000, false, 0, null], null, [2, 75], 3],
            [$this->jobValuesOf($firstAlloyJob), $firstAlloyJob->getSplitGroupId(), $this->runsAndQuantityOf($hydrogenFuelBlock), \count($this->project->getSteps())],
        );
        self::assertSame(
            [$this->jobValuesOf($ferniteCarbide), [5, 1_000, false, 0, null], $this->jobValuesOf($hydrogenFuelBlock)],
            end($this->projectAtEachFlush),
        );
        self::assertSame([$secondAlloyJob], $this->removedEntities);
    }

    public function testDeletedFixedJobOfASplitGroupOfTwoLeavesTheKeptFixedJobAtItsRunsAndLowersTheNeedOfItsMaterials(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns();
        $firstAlloyJob->setRunsFixed(true);
        $secondAlloyJob->setRunsFixed(true);

        $this->delete($firstAlloyJob);

        self::assertSame(
            [[2, 400, true, 0, null], null, [2, 60], 3],
            [$this->jobValuesOf($secondAlloyJob), $secondAlloyJob->getSplitGroupId(), $this->runsAndQuantityOf($hydrogenFuelBlock), \count($this->project->getSteps())],
        );
    }

    public function testDeletedAddedJobGivesBackTheSplitGroupAsBeforeTheJobWasAdded(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $addedJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRunsWithAnAddedJobOfFourRuns();

        $this->delete($addedJob);

        self::assertSame(
            [[3, 600, true, 0, 5], [2, 400, true, 1, 5], [2, 75]],
            [$this->jobValuesOf($firstAlloyJob), $this->jobValuesOf($secondAlloyJob), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
        self::assertSame([self::FERNITE_ALLOY_SPLIT_GROUP, self::FERNITE_ALLOY_SPLIT_GROUP], [$firstAlloyJob->getSplitGroupId(), $secondAlloyJob->getSplitGroupId()]);
    }

    public function testJobsKeptAfterADeletedJobAreRenumberedFromZeroWithTheSumOfTheirRunsAsTotalGroupRuns(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $addedJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRunsWithAnAddedJobOfFourRuns();

        $this->delete($firstAlloyJob);

        self::assertSame(
            [[2, 400, true, 0, 6], [4, 800, true, 1, 6], [2, 80]],
            [$this->jobValuesOf($secondAlloyJob), $this->jobValuesOf($addedJob), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
    }

    public function testDeletedJobOfASplitRootStepDissolvesTheOtherRootJobAndHalvesTheNeedOfTheMaterials(): void
    {
        [$firstRootJob, $secondRootJob] = $this->rootJobsOfFerniteCarbide([[0, 2], [1, 2]]);
        [$ferniteAlloy, $hydrogenFuelBlock] = $this->materialStepsOfFerniteCarbideProjectOfFourRuns();

        $this->delete($secondRootJob);

        self::assertSame(
            [[2, 20_000, false, 0, null], null, [1, 200], [1, 15], 4],
            [$this->jobValuesOf($firstRootJob), $firstRootJob->getSplitGroupId(), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock), $this->project->getRuns()],
        );
    }

    public function testRootJobsKeptAfterADeletedRootJobAreRenumberedAndKeepTheirRuns(): void
    {
        [$firstRootJob, $secondRootJob, $thirdRootJob] = $this->rootJobsOfFerniteCarbide([[0, 2], [1, 1], [2, 1]]);
        [$ferniteAlloy, $hydrogenFuelBlock] = $this->materialStepsOfFerniteCarbideProjectOfFourRuns();

        $this->delete($firstRootJob);

        self::assertSame(
            [[1, 10_000, false, 0, 2], [1, 10_000, false, 1, 2], [1, 200], [1, 15]],
            [$this->jobValuesOf($secondRootJob), $this->jobValuesOf($thirdRootJob), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
    }

    public function testRootJobsKeptAfterADeletedRootJobHaveTheSumOfTheirRunsAsTotalGroupRuns(): void
    {
        [$firstRootJob, $secondRootJob, $thirdRootJob] = $this->rootJobsOfFerniteCarbide([[0, 3], [1, 2], [2, 1]]);
        [$ferniteAlloy, $hydrogenFuelBlock] = $this->materialStepsOfFerniteCarbideProjectOfFourRuns();

        $this->delete($thirdRootJob);

        self::assertSame(
            [[3, 30_000, false, 0, 5], [2, 20_000, false, 1, 5], [3, 500], [1, 40]],
            [$this->jobValuesOf($firstRootJob), $this->jobValuesOf($secondRootJob), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
    }

    public function testDeletedJobPassesItsPurchaseStockAndPurchasedMarkToTheKeptJobOfLargestSplitIndexWhateverTheOrderOfTheSteps(): void
    {
        $this->project->setRuns(10);
        $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 10, quantity: 100_000, depth: 0, meLevel: 0);
        $lastAlloyJob = $this->alloyJob(splitIndex: 2, runs: 1, totalGroupRuns: 5, runsFixed: false);
        $firstAlloyJob = $this->alloyJob(splitIndex: 0, runs: 2, totalGroupRuns: 5, runsFixed: false);
        $middleAlloyJob = $this->alloyJob(splitIndex: 1, runs: 2, totalGroupRuns: 5, runsFixed: false);
        $hydrogenFuelBlock = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 2, quantity: 75, depth: 2, meLevel: 10);
        $purchaseOfFerniteAlloy = $this->purchaseOf(self::FERNITE_ALLOY, quantity: 120, unitPrice: 1_912.25);
        $middleAlloyJob->setInStockQuantity(120)->setPurchased(true)->addPurchase($purchaseOfFerniteAlloy);

        $this->delete($middleAlloyJob);

        self::assertSame(
            [[2, 400, false, 1, 5], 120, true, [$purchaseOfFerniteAlloy], [3, 600, false, 0, 5], 0, false, [], [2, 75]],
            [
                $this->jobValuesOf($lastAlloyJob), $lastAlloyJob->getInStockQuantity(), $lastAlloyJob->isPurchased(), array_values($lastAlloyJob->getPurchases()->toArray()),
                $this->jobValuesOf($firstAlloyJob), $firstAlloyJob->getInStockQuantity(), $firstAlloyJob->isPurchased(), array_values($firstAlloyJob->getPurchases()->toArray()),
                $this->runsAndQuantityOf($hydrogenFuelBlock),
            ],
        );
        self::assertSame($lastAlloyJob, $purchaseOfFerniteAlloy->getStep());
    }

    public function testDeletedCopyOfTheProjectProductWithoutAnyProductionStepOfItLeavesTheMaterialSteps(): void
    {
        $this->project->setProductTypeId(self::QUANTUM_MICROPROCESSOR);
        $quantumMicroprocessorCopy = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, 'copy', runs: 32, quantity: 1, depth: 0, meLevel: 0);
        $titaniumCarbide = $this->stepOfTheProject(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1, meLevel: 0);
        $oxygenFuelBlock = $this->stepOfTheProject(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2, meLevel: 10);

        $this->delete($quantumMicroprocessorCopy);

        self::assertSame(
            [[$titaniumCarbide, $oxygenFuelBlock], [[[1, 490, false, 0, null], [1, 5, false, 0, null]]], [$quantumMicroprocessorCopy]],
            [$this->project->getSteps()->getValues(), $this->projectAtEachFlush, $this->removedEntities],
        );
    }

    public function testDeletedRootStepOfAGroupOfOneJobLeavesTheOtherRootStepOfTheProjectProductUnchanged(): void
    {
        $rootJobOfAGroupOfOneJob = $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 2, quantity: 20_000, depth: 0, meLevel: 0)
            ->setSplitGroupId(self::FERNITE_CARBIDE_SPLIT_GROUP)->setSplitIndex(0)->setTotalGroupRuns(2);
        $rootStep = $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 2, quantity: 20_000, depth: 0, meLevel: 0);
        [$ferniteAlloy, $hydrogenFuelBlock] = $this->materialStepsOfFerniteCarbideProjectOfFourRuns();

        $this->delete($rootJobOfAGroupOfOneJob);

        self::assertSame(
            [[2, 20_000, false, 0, null], null, [1, 200], [1, 15], 3],
            [$this->jobValuesOf($rootStep), $rootStep->getSplitGroupId(), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock), \count($this->project->getSteps())],
        );
    }

    public function testPurchaseStockAndPurchasedMarkOfADeletedJobPassToTheKeptJob(): void
    {
        [, $firstAlloyJob, $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns();
        $purchaseOfFerniteAlloy = $this->purchaseOf(self::FERNITE_ALLOY, quantity: 150, unitPrice: 1_874.5);
        $secondAlloyJob->setInStockQuantity(150)->setPurchased(true)->addPurchase($purchaseOfFerniteAlloy);

        $this->delete($secondAlloyJob);

        self::assertSame(
            [[5, 1_000, false, 0, null], null, 150, true, [2, 75]],
            [$this->jobValuesOf($firstAlloyJob), $firstAlloyJob->getSplitGroupId(), $firstAlloyJob->getInStockQuantity(), $firstAlloyJob->isPurchased(), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
        self::assertSame([$purchaseOfFerniteAlloy], array_values($firstAlloyJob->getPurchases()->toArray()));
        self::assertSame($firstAlloyJob, $purchaseOfFerniteAlloy->getStep());
    }

    public function testMatchedJobOfTheKeptJobStaysOnItAfterTheDissolution(): void
    {
        [, $firstAlloyJob, $secondAlloyJob] = $this->ferniteCarbideProjectOfTenRuns();
        $matchedJob = $this->matchedJob(esiJobId: 7_202_001, runs: 3, status: 'active');
        $firstAlloyJob->addJobMatch($matchedJob)->setJobMatchMode('manual')->setTeLevel(12);

        $this->delete($secondAlloyJob);

        self::assertSame(
            [[5, 1_000, false, 0, null], null, [$matchedJob], 'manual', 12],
            [$this->jobValuesOf($firstAlloyJob), $firstAlloyJob->getSplitGroupId(), array_values($firstAlloyJob->getJobMatches()->toArray()), $firstAlloyJob->getJobMatchMode(), $firstAlloyJob->getTeLevel()],
        );
    }

    public function testDeletedOnlyStepOfAMaterialLeavesItToBuyAndRecalculatesTheNeedOfTheOtherMaterials(): void
    {
        [$ferniteCarbide, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfFourRuns();

        $this->delete($ferniteAlloy);

        self::assertSame(
            [[4, 40_000], [1, 20], [$ferniteCarbide, $hydrogenFuelBlock]],
            [$this->runsAndQuantityOf($ferniteCarbide), $this->runsAndQuantityOf($hydrogenFuelBlock), $this->project->getSteps()->getValues()],
        );
    }

    public function testDeletedProductAddedFromATypeLeavesTheOtherStepsUnchanged(): void
    {
        $ferniteCarbideProject = $this->ferniteCarbideProjectOfFourRuns();
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, 'reaction', runs: 4, quantity: 8_800, depth: 0, meLevel: 0);

        $this->delete($phenolicComposites);

        self::assertSame(
            [[4, 40_000], [2, 400], [1, 30]],
            array_map($this->runsAndQuantityOf(...), $this->project->getSteps()->getValues()),
        );
        self::assertSame($ferniteCarbideProject, $this->project->getSteps()->getValues());
        self::assertSame([[[4, 40_000, false, 0, null], [2, 400, false, 0, null], [1, 30, false, 0, null]]], $this->projectAtEachFlush);
        self::assertSame([$phenolicComposites], $this->removedEntities);
    }

    public function testDeletedCopyStepLeavesTheProductionStepsUnchanged(): void
    {
        $ferniteCarbideProject = $this->ferniteCarbideProjectOfFourRuns();
        $hydrogenFuelBlockCopy = $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, 'copy', runs: 1, quantity: 1, depth: 2, meLevel: 0);

        $this->delete($hydrogenFuelBlockCopy);

        self::assertSame(
            [[4, 40_000], [2, 400], [1, 30]],
            array_map($this->runsAndQuantityOf(...), $this->project->getSteps()->getValues()),
        );
        self::assertSame($ferniteCarbideProject, $this->project->getSteps()->getValues());
        self::assertSame([[[4, 40_000, false, 0, null], [2, 400, false, 0, null], [1, 30, false, 0, null]]], $this->projectAtEachFlush);
        self::assertSame([$hydrogenFuelBlockCopy], $this->removedEntities);
    }

    public function testMaterialStepLeftWithoutConsumerByADeletedStepKeepsItsRunsAndQuantity(): void
    {
        $this->project->setProductTypeId(self::QUANTUM_MICROPROCESSOR);
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10);
        $titaniumCarbide = $this->stepOfTheProject(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 490, depth: 1, meLevel: 0);
        $oxygenFuelBlock = $this->stepOfTheProject(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 5, depth: 2, meLevel: 10);

        $this->delete($titaniumCarbide);

        self::assertSame(
            [[32, 32], [1, 5], [$quantumMicroprocessor, $oxygenFuelBlock]],
            [$this->runsAndQuantityOf($quantumMicroprocessor), $this->runsAndQuantityOf($oxygenFuelBlock), $this->project->getSteps()->getValues()],
        );
    }

    #[DataProvider('statusesOfAMatchedJob')]
    public function testStepWithAMatchedJobIsRefusedWhateverItsStatusWithoutWritingAnything(string $status): void
    {
        [, $firstAlloyJob] = $this->ferniteCarbideProjectOfTenRuns();
        $matchedJob = $this->matchedJob(esiJobId: 7_202_101, runs: 3, status: $status);
        $firstAlloyJob->addJobMatch($matchedJob);

        $this->assertDeletionRefused($firstAlloyJob, self::LINKED_TO_AN_ESI_JOB);

        self::assertSame(
            [4, [3, 600, false, 0, 5], self::FERNITE_ALLOY_SPLIT_GROUP, [$matchedJob], []],
            [\count($this->project->getSteps()), $this->jobValuesOf($firstAlloyJob), $firstAlloyJob->getSplitGroupId(), array_values($firstAlloyJob->getJobMatches()->toArray()), $this->projectAtEachFlush],
        );
    }

    public static function statusesOfAMatchedJob(): iterable
    {
        yield 'active' => ['active'];
        yield 'delivered' => ['delivered'];
        yield 'ready' => ['ready'];
        yield 'paused' => ['paused'];
    }

    public function testLastStepOfTheProjectProductIsRefusedWithoutWritingAnything(): void
    {
        [$ferniteCarbide] = $this->ferniteCarbideProjectOfFourRuns();

        $this->assertDeletionRefused($ferniteCarbide, self::LAST_STEP_OF_THE_PROJECT_PRODUCT);

        self::assertSame([3, []], [\count($this->project->getSteps()), $this->projectAtEachFlush]);
    }

    public function testLastProductionStepOfTheProjectProductBesideACopyOfItIsRefusedWithoutWritingAnything(): void
    {
        $this->project->setProductTypeId(self::QUANTUM_MICROPROCESSOR);
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10);
        $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, 'copy', runs: 32, quantity: 1, depth: 0, meLevel: 0);

        $this->assertDeletionRefused($quantumMicroprocessor, self::LAST_STEP_OF_THE_PROJECT_PRODUCT);

        self::assertSame([2, [], []], [\count($this->project->getSteps()), $this->projectAtEachFlush, $this->removedEntities]);
    }

    public function testLastStepOfTheProjectProductHoldingStockIsRefusedAsTheLastStepOfTheProjectProductFirst(): void
    {
        $ferniteCarbide = $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0, meLevel: 0)->setInStockQuantity(100);

        $this->assertDeletionRefused($ferniteCarbide, self::LAST_STEP_OF_THE_PROJECT_PRODUCT);

        self::assertSame([1, 100, []], [\count($this->project->getSteps()), $ferniteCarbide->getInStockQuantity(), $this->projectAtEachFlush]);
    }

    #[DataProvider('dataOfASingleStepWithoutAnyOtherJobToTakeThem')]
    public function testSingleStepHoldingPurchasesStockOrAPurchasedMarkIsRefusedWithoutWritingAnything(int $inStockQuantity, bool $purchased, ?int $purchasedTypeId, int $purchasedQuantity, float $unitPrice): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfFourRuns();
        $ferniteAlloy->setInStockQuantity($inStockQuantity)->setPurchased($purchased);
        if ($purchasedTypeId !== null) {
            $ferniteAlloy->addPurchase($this->purchaseOf($purchasedTypeId, $purchasedQuantity, $unitPrice));
        }

        $this->assertDeletionRefused($ferniteAlloy, self::HOLDING_PURCHASES_STOCK_OR_A_PURCHASED_MARK);

        self::assertSame(
            [3, [2, 400], $inStockQuantity, $purchased, $purchasedTypeId === null ? 0 : 1, []],
            [\count($this->project->getSteps()), $this->runsAndQuantityOf($ferniteAlloy), $ferniteAlloy->getInStockQuantity(), $ferniteAlloy->isPurchased(), $ferniteAlloy->getPurchases()->count(), $this->projectAtEachFlush],
        );
    }

    public static function dataOfASingleStepWithoutAnyOtherJobToTakeThem(): iterable
    {
        yield 'stock' => [100, false, null, 0, 0.0];
        yield 'purchased mark' => [0, true, null, 0, 0.0];
        yield 'purchase of its product' => [0, false, self::FERNITE_ALLOY, 400, 1_874.5];
        yield 'purchase of a material' => [0, false, self::HYDROGEN_FUEL_BLOCK, 20, 16_250.0];
    }

    public function testLastStepOfTheProjectProductLinkedToAnEsiJobIsRefusedForItsMatchedJobFirst(): void
    {
        [$ferniteCarbide] = $this->ferniteCarbideProjectOfFourRuns();
        $ferniteCarbide->addJobMatch($this->matchedJob(esiJobId: 7_202_201, runs: 4, status: 'active'));

        $this->assertDeletionRefused($ferniteCarbide, self::LINKED_TO_AN_ESI_JOB);
    }

    public function testStepHoldingStockAndLinkedToAnEsiJobIsRefusedForItsMatchedJobFirst(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfFourRuns();
        $ferniteAlloy->addJobMatch($this->matchedJob(esiJobId: 7_202_202, runs: 2, status: 'ready'))->setInStockQuantity(100);

        $this->assertDeletionRefused($ferniteAlloy, self::LINKED_TO_AN_ESI_JOB);
    }

    public function testDeletionWhoseCascadeReachesAMaterialStepWithoutProductInTheSdeIsRefusedWithoutFlushing(): void
    {
        [, , $secondAlloyJob, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTenRuns();
        $hydrogenFuelBlock->setBlueprintTypeId(self::BLUEPRINT_MISSING_FROM_THE_SDE);

        $this->assertDeletionRefused($secondAlloyJob, 'Product of blueprint 999999999 missing from the SDE');

        self::assertSame([], $this->projectAtEachFlush);
    }

    public function testStepOfAProjectOfAnotherPilotIsNotFound(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfFourRuns();
        $this->project->setUser(new User());

        $this->expectExceptionObject(new NotFoundHttpException('Project not found'));

        $this->delete($ferniteAlloy);
    }

    public function testStepOfAnotherProjectIsNotFound(): void
    {
        $this->ferniteCarbideProjectOfFourRuns();
        $stepOfAnotherProject = (new IndustryProjectStep())->setProject((new IndustryProject())->setUser($this->pilot));

        $this->expectExceptionObject(new NotFoundHttpException('Step not found'));

        $this->delete($stepOfAnotherProject);
    }

    public function testIdentifiersThatAreNotUuidsAreNotFoundWithoutReadingAnyRepository(): void
    {
        $projectRepository = $this->createMock(IndustryProjectRepository::class);
        $projectRepository->expects(self::never())->method('find');
        $stepRepository = $this->createMock(IndustryProjectStepRepository::class);
        $stepRepository->expects(self::never())->method('find');
        $entityManager = $this->entityManagerRecordingTheProjectAtEachFlush();
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();
        $processor = new DeleteStepProcessor(
            $this->securityOfThePilot(),
            $projectRepository,
            $stepRepository,
            $this->stepCalculatorOnGoldenSde($productRepository, $this->createStub(IndustryStructureConfigRepository::class), $entityManager),
            $entityManager,
        );

        $this->expectExceptionObject(new NotFoundHttpException('Project not found'));

        $processor->process(null, new Delete(), ['id' => 'fernite-carbide', 'stepId' => 'fernite-alloy']);
    }

    private function assertDeletionRefused(IndustryProjectStep $step, string $refusal): void
    {
        try {
            $this->delete($step);
            self::fail(\sprintf('The deletion must be refused with "%s".', $refusal));
        } catch (BadRequestHttpException $exception) {
            self::assertSame($refusal, $exception->getMessage());
        }
    }

    private function ferniteCarbideProjectOfFourRuns(): array
    {
        return [
            $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0, meLevel: 0),
            ...$this->materialStepsOfFerniteCarbideProjectOfFourRuns(),
        ];
    }

    private function materialStepsOfFerniteCarbideProjectOfFourRuns(): array
    {
        $this->project->setRuns(4);

        return [
            $this->stepOfTheProject(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0),
            $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 30, depth: 2, meLevel: 10),
        ];
    }

    private function rootJobsOfFerniteCarbide(array $splitIndexesAndRuns): array
    {
        $totalGroupRuns = array_sum(array_column($splitIndexesAndRuns, 1));

        return array_map(
            fn (array $splitIndexAndRuns): IndustryProjectStep => $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: $splitIndexAndRuns[1], quantity: $splitIndexAndRuns[1] * 10_000, depth: 0, meLevel: 0)
                ->setSplitGroupId(self::FERNITE_CARBIDE_SPLIT_GROUP)->setSplitIndex($splitIndexAndRuns[0])->setTotalGroupRuns($totalGroupRuns),
            $splitIndexesAndRuns,
        );
    }

    private function ferniteCarbideProjectOfTenRuns(): array
    {
        $this->project->setRuns(10);

        return [
            $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 10, quantity: 100_000, depth: 0, meLevel: 0),
            $this->alloyJob(splitIndex: 0, runs: 3, totalGroupRuns: 5, runsFixed: false),
            $this->alloyJob(splitIndex: 1, runs: 2, totalGroupRuns: 5, runsFixed: false),
            $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 2, quantity: 75, depth: 2, meLevel: 10),
        ];
    }

    private function ferniteCarbideProjectOfTenRunsWithAnAddedJobOfFourRuns(): array
    {
        $this->project->setRuns(10);

        return [
            $this->stepOfTheProject(self::FERNITE_CARBIDE, 'reaction', runs: 10, quantity: 100_000, depth: 0, meLevel: 0),
            $this->alloyJob(splitIndex: 0, runs: 3, totalGroupRuns: 9, runsFixed: true),
            $this->alloyJob(splitIndex: 1, runs: 2, totalGroupRuns: 9, runsFixed: true),
            $this->alloyJob(splitIndex: 2, runs: 4, totalGroupRuns: 9, runsFixed: true),
            $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 3, quantity: 95, depth: 2, meLevel: 10),
        ];
    }

    private function alloyJob(int $splitIndex, int $runs, int $totalGroupRuns, bool $runsFixed): IndustryProjectStep
    {
        return $this->stepOfTheProject(self::FERNITE_ALLOY, 'reaction', runs: $runs, quantity: $runs * 200, depth: 1, meLevel: 0)
            ->setSplitGroupId(self::FERNITE_ALLOY_SPLIT_GROUP)->setSplitIndex($splitIndex)->setTotalGroupRuns($totalGroupRuns)->setRunsFixed($runsFixed);
    }

    private function stepOfTheProject(int $productTypeId, string $activityType, int $runs, int $quantity, int $depth, int $meLevel): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setMeLevel($meLevel)
            ->setSortOrder($depth);
        $this->project->addStep($step);

        return $step;
    }

    private function matchedJob(int $esiJobId, int $runs, string $status): IndustryStepJobMatch
    {
        return (new IndustryStepJobMatch())->setEsiJobId($esiJobId)->setRuns($runs)->setStatus($status);
    }

    private function purchaseOf(int $typeId, int $quantity, float $unitPrice): IndustryStepPurchase
    {
        return (new IndustryStepPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
    }

    private function jobValuesOf(IndustryProjectStep $job): array
    {
        return [$job->getRuns(), $job->getQuantity(), $job->isRunsFixed(), $job->getSplitIndex(), $job->getTotalGroupRuns()];
    }

    private function runsAndQuantityOf(IndustryProjectStep $step): array
    {
        return [$step->getRuns(), $step->getQuantity()];
    }

    private function entityManagerRecordingTheProjectAtEachFlush(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->projectAtEachFlush[] = array_map($this->jobValuesOf(...), $this->project->getSteps()->getValues());
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removedEntities[] = $entity;
        });

        return $entityManager;
    }

    private function delete(IndustryProjectStep $stepFound): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $stepRepository = $this->createStub(IndustryProjectStepRepository::class);
        $stepRepository->method('find')->willReturn($stepFound);
        $entityManager = $this->entityManagerRecordingTheProjectAtEachFlush();
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();

        $processor = new DeleteStepProcessor(
            $this->securityOfThePilot(),
            $projectRepository,
            $stepRepository,
            $this->stepCalculatorOnGoldenSde($productRepository, $this->createStub(IndustryStructureConfigRepository::class), $entityManager),
            $entityManager,
        );

        $processor->process(null, new Delete(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }

    private function securityOfThePilot(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);

        return $security;
    }
}
