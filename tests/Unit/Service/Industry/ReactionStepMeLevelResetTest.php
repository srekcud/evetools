<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Enum\ProjectCorrectionStatus;
use App\Enum\ProjectStatus;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\ProjectCorrectionOutcome;
use App\Service\Industry\ReactionStepMeLevelReset;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Query;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ReactionStepMeLevelReset::class)]
#[CoversMethod(IndustryStepCalculator::class, 'applyStepQuantities')]
#[Group('issue-190')]
final class ReactionStepMeLevelResetTest extends TestCase
{
    use BuildsProjectsOfTheOldRootCalculationOnGoldenSde;

    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000016673';
    private const string SECOND_FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000116673';
    private const string COMPLETED_FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000216673';
    private const string ARCHIVED_FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000316673';
    private const string RIFTER_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000000587';
    private const int RIFTER = 587;
    private const int RIFTER_BLUEPRINT = 691;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;
    private const int FERNITE_ALLOY_OUTPUT_PER_RUN = 200;
    private const int FERNITE_CARBIDE_OUTPUT_PER_RUN = 10_000;
    private const string FERNITE_ALLOY_SPLIT_GROUP = 'fernite-alloy';
    private const string DEADLOCK_DETECTED_BY_POSTGRESQL = 'SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected DETAIL:  Process 4242 waits for ShareLock on transaction 9001; blocked by process 4343.';
    private const string NAME_GIVEN_BY_THE_OWNER = '[GOONS] Mittani alt - Keepstar fuel stash';
    private const string FERNITE_CARBIDE_AT_ME_TEN_RESET = '1 reaction steps ME 10 -> 0, 2 steps recalculated, steps 3 -> 3';

    private array $stepsAtEachFlush = [];

    protected function setUp(): void
    {
        $this->buildFactoryOnGoldenSde();
    }

    public function testFerniteCarbideAtMeTenIsResetAndItsMaterialStepsAreRecalculatedFlushedOnceAndPublishedToTheOwner(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);
        $this->entityManager = $this->entityManagerRecordingTheStepsAtEachFlush($project);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_AT_ME_TEN_RESET, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[[0, 20, 200_000], [0, 10, 2_000], [10, 4, 150]]], $this->stepsAtEachFlush);
        self::assertSame(self::PROJECT_ME_LEVEL, $project->getMeLevel());
        self::assertSame([['/user/'.self::PILOT_ID.'/sync/industry-project', 'completed']], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    #[Group('issue-301')]
    public function testProjectNamedByItsOwnerIsReportedUnderTheSdeNameOfItsProductOnly(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135)
            ->setName(self::NAME_GIVEN_BY_THE_OWNER);

        $outcomes = $this->resetOf($project)->run(true);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_AT_ME_TEN_RESET, null]],
            self::summaryOf($outcomes),
        );
    }

    public function testDryRunReportsTheSameLineAndNeitherFlushesNorPublishes(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);

        $outcomes = $this->resetOf($project)->run(true);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_AT_ME_TEN_RESET, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame(['clear'], $this->entityManagerCalls);
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    public function testResetProjectIsNotSelectedAgain(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);
        $reset = $this->resetOf($project);
        $reset->run(false);

        self::assertSame([], $reset->run(false));
    }

    public function testBothJobsOfASplitFerniteAlloyGroupAtMeTenAreResetAndCountedApart(): void
    {
        $project = $this->ferniteCarbideProjectOfTenRuns(firstFerniteAlloyJobMeLevel: 10, secondFerniteAlloyJobMeLevel: 10, hydrogenFuelBlockQuantity: 73);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 10, ProjectCorrectionStatus::Changed, '2 reaction steps ME 10 -> 0, 1 steps recalculated, steps 4 -> 4', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[0, 10, 100_000], [0, 3, 600], [0, 2, 400], [10, 2, 75]], self::meRunsAndQuantityOfEachStep($project));
        self::assertSame([5, 5], self::totalGroupRunsOfEachJob($this->jobsOfProduct($project, self::FERNITE_ALLOY)));
    }

    public function testOnlyTheJobAtMeTwoOfTheSplitGroupIsResetAndItsMeIsFlushedEvenWhenNoStepIsRecalculated(): void
    {
        $project = $this->ferniteCarbideProjectOfTenRuns(firstFerniteAlloyJobMeLevel: 2, secondFerniteAlloyJobMeLevel: 0, hydrogenFuelBlockQuantity: 75);
        $this->entityManager = $this->entityManagerRecordingTheStepsAtEachFlush($project);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 10, ProjectCorrectionStatus::Changed, '1 reaction steps ME 2 -> 0, 0 steps recalculated, steps 4 -> 4', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[[0, 10, 100_000], [0, 3, 600], [0, 2, 400], [10, 2, 75]]], $this->stepsAtEachFlush);
    }

    public function testFerniteAlloyOfFixedRunsKeepsItsRunsWhenFerniteCarbideIsReset(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);
        [$ferniteAlloy] = $this->jobsOfProduct($project, self::FERNITE_ALLOY);
        $ferniteAlloy->setRunsFixed(true);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, '1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 3 -> 3', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[0, 20, 200_000], [0, 9, 1_800], [10, 4, 145]], self::meRunsAndQuantityOfEachStep($project));
        self::assertTrue($ferniteAlloy->isRunsFixed());
    }

    public function testPurchasedFerniteAlloyAtMeTenIsResetAndStaysPurchased(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 145);
        [$ferniteAlloy] = $this->jobsOfProduct($project, self::FERNITE_ALLOY);
        $ferniteAlloy->setMeLevel(10)->setPurchased(true);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, '1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 3 -> 3', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[0, 20, 200_000], [0, 10, 2_000], [10, 4, 150]], self::meRunsAndQuantityOfEachStep($project));
        self::assertTrue($ferniteAlloy->isPurchased());
    }

    public function testCompletedArchivedAtMeZeroAndWithoutReactionProjectsAreNotSelected(): void
    {
        $completedProject = $this->ferniteCarbideProjectOfTwentyRuns(self::COMPLETED_FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135)
            ->setStatus(ProjectStatus::Completed);
        $archivedProject = $this->ferniteCarbideProjectOfTwentyRuns(self::ARCHIVED_FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135)
            ->setStatus(ProjectStatus::Archived);
        $projectAtMeZero = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 150);
        $rifterProject = $this->rifterProjectWithItsBlueprintCopy();

        $outcomes = $this->resetOf($completedProject, $archivedProject, $projectAtMeZero, $rifterProject)->run(false);

        self::assertSame([], $outcomes);
        self::assertSame([], $this->writesOfTheEntityManager());
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
        self::assertSame(
            [[10, 20, 200_000], [0, 9, 1_800], [10, 4, 135]],
            self::meRunsAndQuantityOfEachStep($completedProject),
        );
        self::assertSame([[10, 5, 5], [10, 1, 1]], self::meRunsAndQuantityOfEachStep($rifterProject));
    }

    #[Group('issue-301')]
    public function testProjectWithAChildWithoutProductInTheSdeFailsWithoutFlushAndTheNextProjectIsReset(): void
    {
        $projectWithAChildWithoutProduct = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135, ferniteAlloyBlueprintTypeId: self::BLUEPRINT_MISSING_FROM_THE_SDE);
        $secondProject = $this->ferniteCarbideProjectOfTwentyRuns(self::SECOND_FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);

        $outcomes = $this->resetOf($projectWithAChildWithoutProduct, $secondProject)->run(false);

        self::assertSame(
            [
                [self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Failed, null, BlueprintProductMissingFromSdeException::class.': Product of blueprint 999999999 missing from the SDE'],
                [self::SECOND_FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_AT_ME_TEN_RESET, null],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame(['clear', 'flush', 'clear'], $this->entityManagerCalls);
        self::assertSame([['/user/'.self::PILOT_ID.'/sync/industry-project', 'completed']], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    #[Group('issue-301')]
    public function testDryRunReportsTheFailedAndTheResetProjectWithoutFlushingOrPublishing(): void
    {
        $projectWithAChildWithoutProduct = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135, ferniteAlloyBlueprintTypeId: self::BLUEPRINT_MISSING_FROM_THE_SDE);
        $secondProject = $this->ferniteCarbideProjectOfTwentyRuns(self::SECOND_FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);

        $outcomes = $this->resetOf($projectWithAChildWithoutProduct, $secondProject)->run(true);

        self::assertSame(
            [
                [self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Failed, null, BlueprintProductMissingFromSdeException::class.': Product of blueprint 999999999 missing from the SDE'],
                [self::SECOND_FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, self::FERNITE_CARBIDE_AT_ME_TEN_RESET, null],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame(['clear', 'clear'], $this->entityManagerCalls);
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    #[Group('issue-301')]
    public function testFlushClosingTheEntityManagerFailsTheProjectAndLeavesTheNextProjectNotProcessed(): void
    {
        $firstProject = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);
        $secondProject = $this->ferniteCarbideProjectOfTwentyRuns(self::SECOND_FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);
        $this->entityManager = $this->entityManagerClosedByAFailedFlush();

        $outcomes = $this->resetOf($firstProject, $secondProject)->run(false);

        self::assertSame(
            [
                [self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Failed, null, DeadlockException::class],
                [self::SECOND_FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Failed, null, 'not processed: the entity manager was closed by the failure of project '.self::FERNITE_CARBIDE_PROJECT_ID],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    public function testReactionWithoutMaterialStepIsResetAndFlushedWithZeroStepsRecalculated(): void
    {
        $project = $this->ferniteCarbideProject(self::FERNITE_CARBIDE_PROJECT_ID, 20);
        self::stepOf($project, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_REACTION_FORMULA, 'reaction', depth: 0, runs: 20, quantity: 200_000, meLevel: 10);
        $this->entityManager = $this->entityManagerRecordingTheStepsAtEachFlush($project);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, '1 reaction steps ME 10 -> 0, 0 steps recalculated, steps 1 -> 1', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[[0, 20, 200_000]]], $this->stepsAtEachFlush);
    }

    public function testDistinctMeLevelsBeforeTheResetAreListedInAscendingNumericOrder(): void
    {
        $project = $this->ferniteCarbideProjectOfTenRuns(firstFerniteAlloyJobMeLevel: 10, secondFerniteAlloyJobMeLevel: 2, hydrogenFuelBlockQuantity: 74);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 10, ProjectCorrectionStatus::Changed, '2 reaction steps ME 2/10 -> 0, 1 steps recalculated, steps 4 -> 4', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[0, 10, 100_000], [0, 3, 600], [0, 2, 400], [10, 2, 75]], self::meRunsAndQuantityOfEachStep($project));
    }

    public function testNegativeMeLevelAndMeLevelAboveTenOfReactionsAreResetToZero(): void
    {
        $project = $this->ferniteCarbideProjectOfTenRuns(firstFerniteAlloyJobMeLevel: 12, secondFerniteAlloyJobMeLevel: -1, hydrogenFuelBlockQuantity: 75);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 10, ProjectCorrectionStatus::Changed, '2 reaction steps ME -1/12 -> 0, 0 steps recalculated, steps 4 -> 4', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([[0, 10, 100_000], [0, 3, 600], [0, 2, 400], [10, 2, 75]], self::meRunsAndQuantityOfEachStep($project));
    }

    public function testSurplusJobOfASplitGroupIsRemovedByTheCascadeAndCountedInTheStepsAfter(): void
    {
        $project = $this->ferniteCarbideProject(self::FERNITE_CARBIDE_PROJECT_ID, 10);
        self::stepOf($project, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_REACTION_FORMULA, 'reaction', depth: 0, runs: 10, quantity: 100_000, meLevel: 10);
        foreach ([200, 200, 200, 200, 100, 0] as $splitIndex => $quantity) {
            self::ferniteAlloyJobOf($project, $splitIndex, runs: 1, quantity: $quantity, totalGroupRuns: 6, meLevel: 0);
        }
        self::stepOf($project, self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', depth: 2, runs: 2, quantity: 75, meLevel: 10);

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 10, ProjectCorrectionStatus::Changed, '1 reaction steps ME 10 -> 0, 5 steps recalculated, steps 8 -> 7', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame(
            [[0, 10, 100_000], [0, 1, 200], [0, 1, 200], [0, 1, 200], [0, 1, 200], [0, 1, 200], [10, 2, 75]],
            self::meRunsAndQuantityOfEachStep($project),
        );
        self::assertSame([5, 5, 5, 5, 5], self::totalGroupRunsOfEachJob($this->jobsOfProduct($project, self::FERNITE_ALLOY)));
    }

    public function testTeLevelStockJobMatchAndPurchaseOfAResetReactionAreKept(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 0, ferniteAlloyRuns: 10, ferniteAlloyQuantity: 2_000, hydrogenFuelBlockQuantity: 145);
        [$ferniteAlloy] = $this->jobsOfProduct($project, self::FERNITE_ALLOY);
        $ferniteAlloy
            ->setMeLevel(10)
            ->setTeLevel(16)
            ->setInStockQuantity(500)
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(6_190_016)->setRuns(4))
            ->addPurchase((new IndustryStepPurchase())->setTypeId(self::FERNITE_ALLOY)->setQuantity(300));

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, '1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 3 -> 3', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame(
            [0, 16, 500, 1, 1, 10, 2_000],
            [
                $ferniteAlloy->getMeLevel(),
                $ferniteAlloy->getTeLevel(),
                $ferniteAlloy->getInStockQuantity(),
                $ferniteAlloy->getJobMatches()->count(),
                $ferniteAlloy->getPurchases()->count(),
                $ferniteAlloy->getRuns(),
                $ferniteAlloy->getQuantity(),
            ],
        );
    }

    public function testUpdatePublishedToTheOwnerCarriesTheIdOfTheResetProject(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::SECOND_FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);

        $this->resetOf($project)->run(false);

        self::assertSame(
            [['/user/'.self::PILOT_ID.'/sync/industry-project', self::SECOND_FERNITE_CARBIDE_PROJECT_ID]],
            array_map(
                static fn (Update $update): array => [$update->getTopics()[0], json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR)['data']['projectId']],
                $this->publishedUpdates,
            ),
        );
    }

    #[Group('issue-301')]
    public function testOwnerWithoutIdentifierFailsTheProjectBeforeAnyFlush(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135)
            ->setUser(new User());

        $outcomes = $this->resetOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Failed, null, 'LogicException: A project owner read from the database has no identifier']],
            self::summaryOf($outcomes),
        );
        self::assertSame(['clear'], $this->entityManagerCalls);
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    #[Group('issue-301')]
    public function testProjectWithoutIdentifierFailsBeforeAnyFlush(): void
    {
        $project = $this->ferniteCarbideProjectOfTwentyRuns(self::FERNITE_CARBIDE_PROJECT_ID, ferniteCarbideMeLevel: 10, ferniteAlloyRuns: 9, ferniteAlloyQuantity: 1_800, hydrogenFuelBlockQuantity: 135);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, null);

        $outcomes = $this->resetReadingEachProjectUnderItsId([self::FERNITE_CARBIDE_PROJECT_ID => $project])->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'type 16673 (name missing from the SDE)', 20, ProjectCorrectionStatus::Failed, null, 'LogicException: A project read from the database has no identifier']],
            self::summaryOf($outcomes),
        );
        self::assertSame(['clear'], $this->entityManagerCalls);
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    private function ferniteCarbideProject(string $projectId, int $runs): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($this->pilot)
            ->setProductTypeId(self::FERNITE_CARBIDE)
            ->setRuns($runs)
            ->setMeLevel(self::PROJECT_ME_LEVEL)
            ->setTeLevel(self::PROJECT_TE_LEVEL)
            ->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS);

        return $this->identified($project, $projectId);
    }

    private function ferniteCarbideProjectOfTwentyRuns(string $projectId, int $ferniteCarbideMeLevel, int $ferniteAlloyRuns, int $ferniteAlloyQuantity, int $hydrogenFuelBlockQuantity, int $ferniteAlloyBlueprintTypeId = self::FERNITE_ALLOY_REACTION_FORMULA): IndustryProject
    {
        $project = $this->ferniteCarbideProject($projectId, 20);
        self::stepOf($project, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_REACTION_FORMULA, 'reaction', depth: 0, runs: 20, quantity: 20 * self::FERNITE_CARBIDE_OUTPUT_PER_RUN, meLevel: $ferniteCarbideMeLevel);
        self::stepOf($project, self::FERNITE_ALLOY, $ferniteAlloyBlueprintTypeId, 'reaction', depth: 1, runs: $ferniteAlloyRuns, quantity: $ferniteAlloyQuantity, meLevel: 0);
        self::stepOf($project, self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', depth: 2, runs: 4, quantity: $hydrogenFuelBlockQuantity, meLevel: 10);

        return $project;
    }

    private function ferniteCarbideProjectOfTenRuns(int $firstFerniteAlloyJobMeLevel, int $secondFerniteAlloyJobMeLevel, int $hydrogenFuelBlockQuantity): IndustryProject
    {
        $project = $this->ferniteCarbideProject(self::FERNITE_CARBIDE_PROJECT_ID, 10);
        self::stepOf($project, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_REACTION_FORMULA, 'reaction', depth: 0, runs: 10, quantity: 10 * self::FERNITE_CARBIDE_OUTPUT_PER_RUN, meLevel: 0);
        self::ferniteAlloyJobOf($project, 0, runs: 3, quantity: 3 * self::FERNITE_ALLOY_OUTPUT_PER_RUN, totalGroupRuns: 5, meLevel: $firstFerniteAlloyJobMeLevel);
        self::ferniteAlloyJobOf($project, 1, runs: 2, quantity: 2 * self::FERNITE_ALLOY_OUTPUT_PER_RUN, totalGroupRuns: 5, meLevel: $secondFerniteAlloyJobMeLevel);
        self::stepOf($project, self::HYDROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', depth: 2, runs: 2, quantity: $hydrogenFuelBlockQuantity, meLevel: 10);

        return $project;
    }

    private function rifterProjectWithItsBlueprintCopy(): IndustryProject
    {
        $project = $this->identified(
            (new IndustryProject())
                ->setUser($this->pilot)
                ->setProductTypeId(self::RIFTER)
                ->setRuns(5)
                ->setMeLevel(self::PROJECT_ME_LEVEL)
                ->setTeLevel(self::PROJECT_TE_LEVEL)
                ->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS),
            self::RIFTER_PROJECT_ID,
        );
        self::stepOf($project, self::RIFTER, self::RIFTER_BLUEPRINT, 'manufacturing', depth: 0, runs: 5, quantity: 5, meLevel: 10);
        self::stepOf($project, self::RIFTER, self::RIFTER_BLUEPRINT, 'copy', depth: 1, runs: 1, quantity: 1, meLevel: 10);

        return $project;
    }

    private static function ferniteAlloyJobOf(IndustryProject $project, int $splitIndex, int $runs, int $quantity, int $totalGroupRuns, int $meLevel): IndustryProjectStep
    {
        return self::stepOf($project, self::FERNITE_ALLOY, self::FERNITE_ALLOY_REACTION_FORMULA, 'reaction', depth: 1, runs: $runs, quantity: $quantity, meLevel: $meLevel)
            ->setSplitGroupId(self::FERNITE_ALLOY_SPLIT_GROUP)
            ->setSplitIndex($splitIndex)
            ->setTotalGroupRuns($totalGroupRuns);
    }

    private static function stepOf(IndustryProject $project, int $productTypeId, int $blueprintTypeId, string $activityType, int $depth, int $runs, int $quantity, int $meLevel): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setDepth($depth)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setMeLevel($meLevel)
            ->setSortOrder($project->getSteps()->count());
        $project->addStep($step);

        return $step;
    }

    private function resetOf(IndustryProject ...$projectsInCreationOrder): ReactionStepMeLevelReset
    {
        $projectsById = [];
        foreach ($projectsInCreationOrder as $project) {
            $projectsById[$project->getId()?->toRfc4122()] = $project;
        }

        return $this->resetReadingEachProjectUnderItsId($projectsById);
    }

    private function resetReadingEachProjectUnderItsId(array $projectsById): ReactionStepMeLevelReset
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findIdsInCreationOrder')->willReturn(array_map(
            static fn (string $projectId): Uuid => Uuid::fromString($projectId),
            array_keys($projectsById),
        ));
        $projectRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProject => $projectsById[(string) $id] ?? null,
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        return self::autowired(ReactionStepMeLevelReset::class, [
            $projectRepository,
            $this->entityManager,
            $this->calculationService,
            new IndustryStepCalculator($this->calculationService, $this->materialRepositoryOnGoldenSde(), $this->productRepositoryOnGoldenSde(), $this->entityManager),
            new MercurePublisherService($this->mercureHubRecordingUpdates(), new NullLogger()),
            $logger,
        ]);
    }

    private function entityManagerRecordingTheStepsAtEachFlush(IndustryProject $project): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('flush')->willReturnCallback(function () use ($project): void {
            $this->stepsAtEachFlush[] = self::meRunsAndQuantityOfEachStep($project);
        });

        return $entityManager;
    }

    private function entityManagerClosedByAFailedFlush(): EntityManagerInterface
    {
        $isOpen = true;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$isOpen): bool {
            return $isOpen;
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$isOpen): void {
            $isOpen = false;

            throw new DeadlockException(
                PdoDriverException::new(new \PDOException(self::DEADLOCK_DETECTED_BY_POSTGRESQL)),
                new Query('UPDATE industry_project_steps SET me_level = ? WHERE id = ?', [0, 4242], []),
            );
        });

        return $entityManager;
    }

    private static function meRunsAndQuantityOfEachStep(IndustryProject $project): array
    {
        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getMeLevel(), $step->getRuns(), $step->getQuantity()],
            array_values($project->getSteps()->toArray()),
        );
    }

    private static function totalGroupRunsOfEachJob(array $jobs): array
    {
        return array_map(static fn (IndustryProjectStep $job): ?int => $job->getTotalGroupRuns(), $jobs);
    }

    private static function summaryOf(array $outcomes): array
    {
        return array_map(
            static fn (ProjectCorrectionOutcome $outcome): array => [$outcome->projectId, $outcome->label, $outcome->runs, $outcome->status, $outcome->change, $outcome->reason],
            $outcomes,
        );
    }
}
