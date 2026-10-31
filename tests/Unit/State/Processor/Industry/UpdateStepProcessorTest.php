<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Processor\Industry\UpdateStepProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
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
    use BuildsStepProcessorsOnGoldenSde;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int FERNITE_ALLOY_OUTPUT_PER_RUN = 200;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;
    private const int FORMULA_MISSING_FROM_THE_SDE = 999_999_998;
    private const string UNKNOWN_STRUCTURE_CONFIG_ID = '0192f0c4-7b1e-7a3c-9d2e-287000000003';
    private const string REACTION_MATERIAL_EFFICIENCY_REFUSAL = 'Cannot set a material efficiency on a reaction step';

    private User $pilot;
    private IndustryProject $project;
    private int $flushes = 0;

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
        $alloyJobWithoutRuns = $this->jobOfTheFerniteAlloyGroup(2, runs: 0, totalGroupRuns: 2)->setMeLevel(2);
        $meLevelEntered = new UpdateStepInput();
        $meLevelEntered->meLevel = 0;

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

    #[Group('issue-190')]
    public function testMeLevelEnteredOnAReactionStepIsRefusedWithoutChangingItsMaterialSteps(): void
    {
        [$ferniteCarbide, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $entityManager = $this->entityManagerCountingTheFlushes();

        try {
            $this->patch($ferniteCarbide, $this->meLevelEntered(10), $entityManager);
            self::fail('A material efficiency entered on a reaction step must be refused.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame(self::REACTION_MATERIAL_EFFICIENCY_REFUSAL, $refusal->getMessage());
        }

        self::assertSame(
            [0, [10, 2_000], [4, 150], 0],
            [$ferniteCarbide->getMeLevel(), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock), $this->flushes],
        );
    }

    #[Group('issue-190')]
    public function testMeLevelEnteredOnAReactionStepWithRunsIsRefusedWithoutApplyingTheRuns(): void
    {
        [$ferniteCarbide] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $entityManager = $this->entityManagerCountingTheFlushes();
        $meLevelAndRunsEntered = $this->meLevelEntered(10);
        $meLevelAndRunsEntered->runs = 25;

        try {
            $this->patch($ferniteCarbide, $meLevelAndRunsEntered, $entityManager);
            self::fail('A material efficiency entered on a reaction step must be refused with the runs entered with it.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame(self::REACTION_MATERIAL_EFFICIENCY_REFUSAL, $refusal->getMessage());
        }

        self::assertSame(
            [20, 200_000, 0, 0],
            [$ferniteCarbide->getRuns(), $ferniteCarbide->getQuantity(), $ferniteCarbide->getMeLevel(), $this->flushes],
        );
    }

    #[Group('issue-190')]
    public function testMeLevelOfAReactionStepIsRefusedBeforeTheStructureOfAnotherPilot(): void
    {
        [$ferniteCarbide] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $structureOfAnotherPilot = $this->structureConfig('Sotiyo of another pilot', 'sotiyo', new User());
        $meLevelAndStructureEntered = $this->meLevelEntered(10);
        $meLevelAndStructureEntered->structureConfigId = $structureOfAnotherPilot->getId()->toRfc4122();

        try {
            $this->patch($ferniteCarbide, $meLevelAndStructureEntered, $this->entityManagerCountingTheFlushes(), structureConfigRepository: $this->structureConfigRepositoryWith($structureOfAnotherPilot));
            self::fail('A material efficiency entered on a reaction step must be refused before the structure.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame(self::REACTION_MATERIAL_EFFICIENCY_REFUSAL, $refusal->getMessage());
        }

        self::assertSame([0, null, 0], [$ferniteCarbide->getMeLevel(), $ferniteCarbide->getStructureConfig(), $this->flushes]);
    }

    #[Group('issue-190')]
    public function testPurchaseMarkOfARootReactionStepIsRefusedBeforeItsMeLevel(): void
    {
        [$ferniteCarbide] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $purchaseMarkAndMeLevelEntered = $this->meLevelEntered(10);
        $purchaseMarkAndMeLevelEntered->purchased = true;

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot mark root products as purchased');

        $this->patch($ferniteCarbide, $purchaseMarkAndMeLevelEntered, $this->entityManagerCountingTheFlushes());
    }

    #[Group('issue-190')]
    public function testMeLevelZeroEnteredOnAReactionStepWithAnOldMeLevelRecalculatesItsMaterialSteps(): void
    {
        [$ferniteCarbide, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);

        $updatedSteps = $this->patch($ferniteCarbide, $this->meLevelEntered(0), $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [0, [10, 2_000], [4, 150]],
            [$ferniteCarbide->getMeLevel(), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
        self::assertSame([[self::FERNITE_CARBIDE, 200_000], [self::FERNITE_ALLOY, 2_000], [self::HYDROGEN_FUEL_BLOCK, 150]], $this->productAndQuantityOf($updatedSteps));
    }

    #[Group('issue-190')]
    public function testMeLevelZeroEnteredOnAReactionStepAtMeLevelZeroRecalculatesNothing(): void
    {
        [$ferniteCarbide, $ferniteAlloy] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);

        $updatedStep = $this->patch($ferniteCarbide, $this->meLevelEntered(0), $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([[self::FERNITE_CARBIDE, 200_000], [10, 2_000]], [[$updatedStep->productTypeId, $updatedStep->quantity], $this->runsAndQuantityOf($ferniteAlloy)]);
    }

    #[Group('issue-190')]
    public function testTeLevelEnteredOnAReactionStepIsKept(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $teLevelEntered = new UpdateStepInput();
        $teLevelEntered->teLevel = 10;

        $updatedStep = $this->patch($ferniteAlloy, $teLevelEntered, $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([10, 0, 2_000], [$ferniteAlloy->getTeLevel(), $ferniteAlloy->getMeLevel(), $ferniteAlloy->getQuantity()]);
    }

    #[Group('issue-287')]
    public function testStructureOfThePilotAttachedToAStepRecalculatesItsMaterialStep(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $raitaruOfThePilot = $this->structureConfig('Raitaru of the pilot', 'raitaru', $this->pilot);

        $updatedSteps = $this->patch($quantumMicroprocessor, $this->structureEntered($raitaruOfThePilot->getId()->toRfc4122()), $this->createStub(EntityManagerInterface::class), structureConfigRepository: $this->structureConfigRepositoryWith($quantumMicroprocessor->getStructureConfig(), $raitaruOfThePilot));

        self::assertSame(['Raitaru of the pilot', [1, 485]], [$quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide)]);
        self::assertSame([[self::QUANTUM_MICROPROCESSOR, 32], [self::TITANIUM_CARBIDE, 485]], $this->productAndQuantityOf($updatedSteps));
    }

    #[Group('issue-287')]
    public function testStructureOfAnotherPilotIsRefusedAsStructureNotFoundAndTheStepKeepsItsStructure(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $structureOfAnotherPilot = $this->structureConfig('Sotiyo of another pilot', 'sotiyo', new User());

        try {
            $this->patch($quantumMicroprocessor, $this->structureEntered($structureOfAnotherPilot->getId()->toRfc4122()), $this->entityManagerCountingTheFlushes(), structureConfigRepository: $this->structureConfigRepositoryWith($quantumMicroprocessor->getStructureConfig(), $structureOfAnotherPilot));
            self::fail('The structure of another pilot must not be attached to a step.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Structure not found', $refusal->getMessage());
        }

        self::assertSame(['Station of the pilot', [1, 490], 0], [$quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide), $this->flushes]);
    }

    #[Group('issue-287')]
    public function testUnknownStructureIsRefusedAsStructureNotFoundAndTheStepKeepsItsStructure(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();

        try {
            $this->patch($quantumMicroprocessor, $this->structureEntered(self::UNKNOWN_STRUCTURE_CONFIG_ID), $this->entityManagerCountingTheFlushes(), structureConfigRepository: $this->structureConfigRepositoryWith($quantumMicroprocessor->getStructureConfig()));
            self::fail('An unknown structure must not remove the structure of the step.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Structure not found', $refusal->getMessage());
        }

        self::assertSame(['Station of the pilot', [1, 490], 0], [$quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide), $this->flushes]);
    }

    #[Group('issue-287')]
    public function testStructureOfAnotherPilotEnteredWithAMeLevelIsRefusedWithoutApplyingTheMeLevel(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $structureOfAnotherPilot = $this->structureConfig('Sotiyo of another pilot', 'sotiyo', new User());
        $meLevelAndStructureEntered = $this->structureEntered($structureOfAnotherPilot->getId()->toRfc4122());
        $meLevelAndStructureEntered->meLevel = 8;

        try {
            $this->patch($quantumMicroprocessor, $meLevelAndStructureEntered, $this->entityManagerCountingTheFlushes(), structureConfigRepository: $this->structureConfigRepositoryWith($quantumMicroprocessor->getStructureConfig(), $structureOfAnotherPilot));
            self::fail('The structure of another pilot must be refused with the material efficiency entered with it.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Structure not found', $refusal->getMessage());
        }

        self::assertSame([10, 'Station of the pilot', [1, 490], 0], [$quantumMicroprocessor->getMeLevel(), $quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide), $this->flushes]);
    }

    #[Group('issue-287')]
    public function testStructureAlreadyAttachedToTheStepRecalculatesNothing(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $stationOfThePilot = $quantumMicroprocessor->getStructureConfig();
        self::assertInstanceOf(IndustryStructureConfig::class, $stationOfThePilot);

        $updatedStep = $this->patch($quantumMicroprocessor, $this->structureEntered($stationOfThePilot->getId()->toRfc4122()), $this->createStub(EntityManagerInterface::class), structureConfigRepository: $this->structureConfigRepositoryWith($stationOfThePilot));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame(['Station of the pilot', [1, 490]], [$quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide)]);
    }

    #[Group('issue-287')]
    public function testPatchWithoutStructureKeepsTheStepStructure(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $teLevelEntered = new UpdateStepInput();
        $teLevelEntered->teLevel = 4;

        $updatedStep = $this->patch($quantumMicroprocessor, $teLevelEntered, $this->createStub(EntityManagerInterface::class), structureConfigRepository: $this->structureConfigRepositoryWith($quantumMicroprocessor->getStructureConfig()));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([4, 'Station of the pilot', [1, 490]], [$quantumMicroprocessor->getTeLevel(), $quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide)]);
    }

    #[Group('issue-287')]
    public function testDeletedStructureOfThePilotIsAttachedToTheStepAndRecalculatesItsMaterialStep(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $deletedRaitaruOfThePilot = $this->structureConfig('Deleted Raitaru of the pilot', 'raitaru', $this->pilot)->setIsDeleted(true);

        $updatedSteps = $this->patch($quantumMicroprocessor, $this->structureEntered($deletedRaitaruOfThePilot->getId()->toRfc4122()), $this->createStub(EntityManagerInterface::class), structureConfigRepository: $this->structureConfigRepositoryWith($quantumMicroprocessor->getStructureConfig(), $deletedRaitaruOfThePilot));

        self::assertSame(['Deleted Raitaru of the pilot', [1, 485]], [$quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide)]);
        self::assertSame([[self::QUANTUM_MICROPROCESSOR, 32], [self::TITANIUM_CARBIDE, 485]], $this->productAndQuantityOf($updatedSteps));
    }

    #[Group('issue-190')]
    public function testMeLevelAndStructureEnteredAsTheyAreRecalculateNothingEvenWhenAMaterialStepIsStale(): void
    {
        [$quantumMicroprocessor, $titaniumCarbide] = $this->quantumMicroprocessorProjectInAStation();
        $quantumMicroprocessor->setMeLevel(0);
        $titaniumCarbide->setQuantity(999);
        $stationOfThePilot = $quantumMicroprocessor->getStructureConfig();
        self::assertInstanceOf(IndustryStructureConfig::class, $stationOfThePilot);
        $meLevelAndStructureEntered = $this->structureEntered($stationOfThePilot->getId()->toRfc4122());
        $meLevelAndStructureEntered->meLevel = 0;

        $updatedStep = $this->patch($quantumMicroprocessor, $meLevelAndStructureEntered, $this->createStub(EntityManagerInterface::class), structureConfigRepository: $this->structureConfigRepositoryWith($stationOfThePilot));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([0, 'Station of the pilot', [1, 999]], [$quantumMicroprocessor->getMeLevel(), $quantumMicroprocessor->getStructureConfig()?->getName(), $this->runsAndQuantityOf($titaniumCarbide)]);
    }

    #[Group('issue-287')]
    public function testStockOfARootStepIsRefusedBeforeItsMeLevelAndItsStructure(): void
    {
        [$ferniteCarbide] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $structureOfAnotherPilot = $this->structureConfig('Athanor of another pilot', 'athanor', new User());
        $stockMeLevelAndStructureEntered = $this->meLevelEntered(10);
        $stockMeLevelAndStructureEntered->inStockQuantity = 5_000;
        $stockMeLevelAndStructureEntered->structureConfigId = $structureOfAnotherPilot->getId()->toRfc4122();

        try {
            $this->patch($ferniteCarbide, $stockMeLevelAndStructureEntered, $this->entityManagerCountingTheFlushes(), structureConfigRepository: $this->structureConfigRepositoryWith($structureOfAnotherPilot));
            self::fail('A stock entered on a root step must be refused before its material efficiency and its structure.');
        } catch (BadRequestHttpException $refusal) {
            self::assertSame('Cannot mark root products as in stock', $refusal->getMessage());
        }

        self::assertSame([0, 0, null, 0], [$ferniteCarbide->getInStockQuantity(), $ferniteCarbide->getMeLevel(), $ferniteCarbide->getStructureConfig(), $this->flushes]);
    }

    #[Group('issue-287')]
    public function testZeroStockEnteredOnARootStepIsAccepted(): void
    {
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::PHENOLIC_COMPOSITES + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 3, quantity: 6_600, depth: 0, meLevel: 0);
        $zeroStockEntered = new UpdateStepInput();
        $zeroStockEntered->inStockQuantity = 0;

        $updatedStep = $this->patch($phenolicComposites, $zeroStockEntered, $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([0, [3, 6_600]], [$phenolicComposites->getInStockQuantity(), $this->runsAndQuantityOf($phenolicComposites)]);
    }

    #[Group('issue-287')]
    public function testStockEnteredOnAMaterialStepIsKept(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $stockEntered = new UpdateStepInput();
        $stockEntered->inStockQuantity = 1_500;

        $updatedStep = $this->patch($ferniteAlloy, $stockEntered, $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([1_500, [10, 2_000]], [$ferniteAlloy->getInStockQuantity(), $this->runsAndQuantityOf($ferniteAlloy)]);
    }

    #[Group('issue-287')]
    public function testPurchaseMarkEnteredOnAMaterialStepIsKept(): void
    {
        [, , $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $purchaseMarkEntered = new UpdateStepInput();
        $purchaseMarkEntered->purchased = true;

        $updatedStep = $this->patch($hydrogenFuelBlock, $purchaseMarkEntered, $this->createStub(EntityManagerInterface::class));

        self::assertInstanceOf(ProjectStepResource::class, $updatedStep);
        self::assertSame([true, [4, 150]], [$hydrogenFuelBlock->isPurchased(), $this->runsAndQuantityOf($hydrogenFuelBlock)]);
    }

    #[Group('issue-287')]
    public function testOneRunEnteredOnARootStepGivesItTheUnitsPerRunOfItsFormula(): void
    {
        $phenolicComposites = $this->stepOfTheProject(self::PHENOLIC_COMPOSITES, self::PHENOLIC_COMPOSITES + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 3, quantity: 6_600, depth: 0, meLevel: 0);

        $this->patch($phenolicComposites, $this->runsEntered(1), $this->createStub(EntityManagerInterface::class));

        self::assertSame([1, 2_200], $this->runsAndQuantityOf($phenolicComposites));
    }

    #[Group('issue-287')]
    public function testOneHundredThousandRunsEnteredOnARootReactionOfTenThousandUnitsPerRunGiveOneBillionUnits(): void
    {
        [$ferniteCarbide, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);

        $this->patch($ferniteCarbide, $this->runsEntered(100_000), $this->createStub(EntityManagerInterface::class));

        self::assertSame(
            [[100_000, 1_000_000_000], [50_000, 10_000_000], [18_750, 750_000]],
            [$this->runsAndQuantityOf($ferniteCarbide), $this->runsAndQuantityOf($ferniteAlloy), $this->runsAndQuantityOf($hydrogenFuelBlock)],
        );
    }

    public function testRunsEnteredAboveTheNeedOfAMaterialStepWithoutOverproductionAreFixedAtTheNeed(): void
    {
        [, $ferniteAlloy, $hydrogenFuelBlock] = $this->ferniteCarbideProjectOfTwentyRuns(ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);

        $this->patch($ferniteAlloy, $this->runsEntered(15), $this->createStub(EntityManagerInterface::class));

        self::assertSame([[10, 2_000], true, [4, 150]], [$this->runsAndQuantityOf($ferniteAlloy), $ferniteAlloy->isRunsFixed(), $this->runsAndQuantityOf($hydrogenFuelBlock)]);
    }

    public function testStepOfAProjectOfAnotherPilotIsRefusedAsProjectNotFound(): void
    {
        [$quantumMicroprocessor] = $this->quantumMicroprocessorProjectInAStation();
        $this->project->setUser(new User());

        try {
            $this->patch($quantumMicroprocessor, $this->meLevelEntered(8), $this->entityManagerCountingTheFlushes());
            self::fail('A step of a project of another pilot must not be updated.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Project not found', $refusal->getMessage());
        }

        self::assertSame([10, 0], [$quantumMicroprocessor->getMeLevel(), $this->flushes]);
    }

    public function testStepOfAnotherProjectIsRefusedAsStepNotFound(): void
    {
        $this->quantumMicroprocessorProjectInAStation();
        $ferniteCarbideOfAnotherProject = (new IndustryProjectStep())
            ->setProductTypeId(self::FERNITE_CARBIDE)
            ->setBlueprintTypeId(self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityType('reaction')
            ->setRuns(7)
            ->setQuantity(70_000)
            ->setDepth(0)
            ->setMeLevel(0);
        (new IndustryProject())->setUser($this->pilot)->addStep($ferniteCarbideOfAnotherProject);

        try {
            $this->patch($ferniteCarbideOfAnotherProject, $this->runsEntered(9), $this->entityManagerCountingTheFlushes());
            self::fail('A step of another project must not be updated through this project.');
        } catch (NotFoundHttpException $refusal) {
            self::assertSame('Step not found', $refusal->getMessage());
        }

        self::assertSame([[7, 70_000], 0], [$this->runsAndQuantityOf($ferniteCarbideOfAnotherProject), $this->flushes]);
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

    private function ferniteCarbideProjectOfTwentyRuns(int $ferniteCarbideMeLevel, int $ferniteAlloyRuns, int $ferniteAlloyQuantity, int $hydrogenFuelBlockQuantity): array
    {
        return [
            $this->stepOfTheProject(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 20, quantity: 200_000, depth: 0, meLevel: $ferniteCarbideMeLevel),
            $this->stepOfTheProject(self::FERNITE_ALLOY, self::FERNITE_ALLOY + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: $ferniteAlloyRuns, quantity: $ferniteAlloyQuantity, depth: 1, meLevel: 0),
            $this->stepOfTheProject(self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 4, quantity: $hydrogenFuelBlockQuantity, depth: 2, meLevel: 10),
        ];
    }

    private function quantumMicroprocessorProjectInAStation(): array
    {
        $quantumMicroprocessor = $this->stepOfTheProject(self::QUANTUM_MICROPROCESSOR, self::QUANTUM_MICROPROCESSOR + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10)
            ->setStructureConfig($this->structureConfig('Station of the pilot', 'station', $this->pilot));
        $titaniumCarbide = $this->stepOfTheProject(self::TITANIUM_CARBIDE, self::TITANIUM_CARBIDE + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET, 'reaction', runs: 1, quantity: 490, depth: 1, meLevel: 0);

        return [$quantumMicroprocessor, $titaniumCarbide];
    }

    private function structureConfig(string $name, string $structureType, User $owner): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())->setName($name)->setStructureType($structureType)->setUser($owner);
    }

    private function structureConfigRepositoryWith(?IndustryStructureConfig ...$structureConfigs): IndustryStructureConfigRepository
    {
        $structureConfigsById = [];
        foreach (array_filter($structureConfigs) as $structureConfig) {
            $structureConfigsById[$structureConfig->getId()->toRfc4122()] = $structureConfig;
        }
        $repository = $this->createStub(IndustryStructureConfigRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryStructureConfig => $structureConfigsById[(string) $id] ?? null,
        );

        return $repository;
    }

    private function structureEntered(string $structureConfigId): UpdateStepInput
    {
        $input = new UpdateStepInput();
        $input->structureConfigId = $structureConfigId;

        return $input;
    }

    private function meLevelEntered(int $meLevel): UpdateStepInput
    {
        $input = new UpdateStepInput();
        $input->meLevel = $meLevel;

        return $input;
    }

    private function runsAndQuantityOf(IndustryProjectStep $step): array
    {
        return [$step->getRuns(), $step->getQuantity()];
    }

    private function entityManagerCountingTheFlushes(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $entityManager;
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

    private function patch(IndustryProjectStep $step, UpdateStepInput $input, EntityManagerInterface $entityManager, ?EntityManagerInterface $calculatorEntityManager = null, ?IndustryStructureConfigRepository $structureConfigRepository = null): ProjectStepResource|array
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
        $structureConfigRepository ??= $this->createStub(IndustryStructureConfigRepository::class);
        $productRepository = $this->productRepositoryOfTheGoldenBlueprints();

        $processor = new UpdateStepProcessor(
            $security,
            $projectRepository,
            $stepRepository,
            $productRepository,
            $structureConfigRepository,
            $this->stepCalculatorOnGoldenSde($productRepository, $structureConfigRepository, $calculatorEntityManager ?? $entityManager),
            $mapper,
            $entityManager,
        );
        return $processor->process($input, new Patch(), ['id' => Uuid::v4()->toRfc4122(), 'stepId' => Uuid::v4()->toRfc4122()]);
    }
}
