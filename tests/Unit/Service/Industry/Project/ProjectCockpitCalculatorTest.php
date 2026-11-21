<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Enum\ProjectStatus;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\CockpitPhase;
use App\Service\Industry\Project\ProjectCockpit;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectCockpitInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectCockpitCalculator::class)]
#[Group('issue-336')]
final class ProjectCockpitCalculatorTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int ISOGEN_TYPE_ID = 37;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int FUSION_THRUSTER_TYPE_ID = 11531;
    private const int RAM_STARSHIP_TECH_TYPE_ID = 11478;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int HAMMERHEAD_II_TYPE_ID = 2185;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int HAMMERHEAD_II_BLUEPRINT_TYPE_ID = 2186;

    private const array NOTHING_TO_DO = ['nothing_to_do', false, null, null, null, null, null, null];

    private ProjectCockpitCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProjectCockpitCalculator();
    }

    #[Group('issue-1178')]
    public function testInProgressProjectWithAnUncoveredMaterialAndAFulfilledJobLineBlocksSupply(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-robotics', self::ROBOTICS_TYPE_ID, 'Robotics', 25, 15),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 2, 2, 0),
        ], [], 0, false);

        self::assertSame(['supply', 'blocked', 0, 1, 'materials_missing', 1], $this->phaseRow($cockpit, 'supply'));
    }

    #[Group('issue-1178')]
    public function testInProgressProjectWithTwoUncoveredMaterialsAndNoJobLaunchedKeepsSupplyCurrentAndSourcesTheLargestRemainder(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 24_000, 24_000),
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 6_000, 6_200),
            $this->material('line-mexallon', self::MEXALLON_TYPE_ID, 'Mexallon', 1_800, 1_800),
            $this->material('line-robotics', self::ROBOTICS_TYPE_ID, 'Robotics', 25, 15),
            $this->material('line-fusion-thruster', self::FUSION_THRUSTER_TYPE_ID, 'Fusion Thruster', 80, 30),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, 3, 0),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 0, 2, 0),
        ], [], 0, false);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'current', 3, 5, null, null],
            ['produce', 'upcoming', 0, 2, null, null],
            ['sell', 'upcoming', 0, 2, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($cockpit));
        self::assertSame(
            ['source_material', false, 'materials', null, 'line-fusion-thruster', self::FUSION_THRUSTER_TYPE_ID, 'Fusion Thruster', 50],
            $this->actionRow($cockpit),
        );
    }

    #[Group('issue-1178')]
    public function testInProgressProjectWithAnUncoveredMaterialAndAJobMatchedToAnEsiJobBlocksSupplyAndWarnsTheSourcingAction(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 24_000, 24_000),
            $this->material('line-robotics', self::ROBOTICS_TYPE_ID, 'Robotics', 25, 15),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, 3, 1),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 0, 2, 0),
        ], [], 0, false);

        self::assertSame(['supply', 'blocked', 1, 2, 'materials_missing', 1], $this->phaseRow($cockpit, 'supply'));
        self::assertSame(['produce', 'current', 0, 2, null, null], $this->phaseRow($cockpit, 'produce'));
        self::assertSame(
            ['source_material', true, 'materials', null, 'line-robotics', self::ROBOTICS_TYPE_ID, 'Robotics', 10],
            $this->actionRow($cockpit),
        );
    }

    #[Group('issue-1178')]
    public function testInProgressProjectWithAnUncoveredMaterialAndADeliveredJobRunBlocksSupply(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-robotics', self::ROBOTICS_TYPE_ID, 'Robotics', 25, 15),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, 3, 0, 1),
        ], [], 0, false);

        self::assertSame(['supply', 'blocked', 0, 1, 'materials_missing', 1], $this->phaseRow($cockpit, 'supply'));
        self::assertTrue($this->actionRow($cockpit)[1]);
    }

    public function testInProgressProjectWithCoveredMaterialsAndOneOfSixJobsDeliveredLaunchesTheFirstBlueprintJobByTypeName(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 2, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 9_000),
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 120, 120),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 400, 0, 40, 0),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 300, 3, 3),
            $this->job('line-fusion-thruster', 'component', self::FUSION_THRUSTER_TYPE_ID, 'Fusion Thruster', 45, 0, 9, 0),
            $this->job('line-ishtar-blueprint', 'blueprint', self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 3, 0, 12, 0),
            $this->job('line-hammerhead-blueprint', 'blueprint', self::HAMMERHEAD_II_BLUEPRINT_TYPE_ID, 'Hammerhead II Blueprint', 20, 0, 20, 6),
        ], [], 0, false);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'done', 2, 2, null, null],
            ['produce', 'current', 1, 6, null, null],
            ['sell', 'upcoming', 0, 403, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($cockpit));
        self::assertSame(
            ['launch_job', false, 'materials', null, 'line-hammerhead-blueprint', self::HAMMERHEAD_II_BLUEPRINT_TYPE_ID, 'Hammerhead II Blueprint', 14],
            $this->actionRow($cockpit),
        );
    }

    public function testInProgressProjectLaunchesAComponentJobBeforeAFinalJobWhoseTypeNameSortsFirst(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-isogen', self::ISOGEN_TYPE_ID, 'Isogen', 700, 700),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 250, 0, 25, 0),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 400, 100, 4, 1),
            $this->job('line-fusion-thruster', 'component', self::FUSION_THRUSTER_TYPE_ID, 'Fusion Thruster', 60, 60, 6, 6),
            $this->job('line-hammerhead-blueprint', 'blueprint', self::HAMMERHEAD_II_BLUEPRINT_TYPE_ID, 'Hammerhead II Blueprint', 10, 10, 10, 10),
        ], [], 0, true);

        self::assertSame(
            ['launch_job', false, 'materials', null, 'line-ram', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 3],
            $this->actionRow($cockpit),
        );
    }

    public function testLaunchJobOnAJobLineWithoutRunsHasAnUnknownRemainingRunCount(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 90, 90),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 4, 1, null, 0),
        ], [], 0, false);

        self::assertSame(
            ['launch_job', false, 'materials', null, 'line-ishtar', self::ISHTAR_TYPE_ID, 'Ishtar', null],
            $this->actionRow($cockpit),
        );
    }

    public function testEqualRemaindersToSourceAreBrokenByTypeNameAscending(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 100, 60),
            $this->material('line-isogen', self::ISOGEN_TYPE_ID, 'Isogen', 40, 0),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 150, 0, 15, 0),
        ], [], 0, true);

        self::assertSame(
            ['source_material', false, 'materials', null, 'line-isogen', self::ISOGEN_TYPE_ID, 'Isogen', 40],
            $this->actionRow($cockpit),
        );
    }

    public function testInProgressProjectWithEveryJobDeliveredIsMovedToSellingByTheManagerWhileAMemberHasNothingToDo(): void
    {
        $lines = [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 200, 200, 2, 2),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 2, 2, 2),
        ];

        $managerCockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, $lines, [], 0, true);
        $memberCockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, $lines, [], 0, false);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'done', 1, 1, null, null],
            ['produce', 'current', 2, 2, null, null],
            ['sell', 'upcoming', 0, 2, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($managerCockpit));
        self::assertSame(['start_selling', false, null, 'selling', null, null, null, null], $this->actionRow($managerCockpit));
        self::assertSame(self::NOTHING_TO_DO, $this->actionRow($memberCockpit));
    }

    public function testSellingProjectWithSixtyOfAHundredFinalUnitsSoldAsksTheManagerAndTheMemberToRecordForty(): void
    {
        $lines = [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 30_000, 30_000),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 100, 100, 10, 10),
        ];
        $sold = [self::HAMMERHEAD_II_TYPE_ID => 60];

        $managerCockpit = $this->cockpitOf(ProjectStatus::Selling, 1, $lines, $sold, 0, true);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Selling, 1, $lines, $sold, 0, false);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'done', 1, 1, null, null],
            ['produce', 'done', 1, 1, null, null],
            ['sell', 'current', 60, 100, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($managerCockpit));
        self::assertSame(['record_sale', false, 'sales', null, null, null, null, 40], $this->actionRow($managerCockpit));
        self::assertSame(['record_sale', false, 'sales', null, null, null, null, 40], $this->actionRow($memberCockpit));
    }

    public function testSellingProjectWithEveryFinalUnitSoldIsCompletedByTheManagerWhileAMemberHasNothingToDo(): void
    {
        $lines = [
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 8_000, 8_000),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 100, 100, 10, 10),
        ];
        $sold = [self::HAMMERHEAD_II_TYPE_ID => 100];

        $managerCockpit = $this->cockpitOf(ProjectStatus::Selling, 1, $lines, $sold, 0, true);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Selling, 1, $lines, $sold, 0, false);

        self::assertSame(['sell', 'done', 100, 100, null, null], $this->phaseRow($managerCockpit, 'sell'));
        self::assertSame(['close', 'current', 0, 1, null, null], $this->phaseRow($managerCockpit, 'close'));
        self::assertSame(['complete_project', false, null, 'completed', null, null, null, null], $this->actionRow($managerCockpit));
        self::assertSame(self::NOTHING_TO_DO, $this->actionRow($memberCockpit));
    }

    public function testSellingProjectWithAnUndeliveredJobAndAnUncoveredMaterialBlocksProduceAndSupply(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000, 12_000),
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 150, 147),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 200, 3, 3),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 2, 2, 2),
        ], [self::ISHTAR_TYPE_ID => 1], 0, true);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'blocked', 1, 2, 'materials_missing', 1],
            ['produce', 'blocked', 1, 2, 'jobs_unfinished', 1],
            ['sell', 'current', 1, 2, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($cockpit));
        self::assertSame(['record_sale', false, 'sales', null, null, null, null, 1], $this->actionRow($cockpit));
    }

    public function testSaleOfATypeThatIsNotAFinalProductIsLeftOutOfTheUnitsSold(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, 1, [
            $this->material('line-mexallon', self::MEXALLON_TYPE_ID, 'Mexallon', 2_400, 2_400),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 500, 500, 5, 5),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 120, 120, 12, 12),
        ], [self::RAM_STARSHIP_TECH_TYPE_ID => 50, self::HAMMERHEAD_II_TYPE_ID => 30], 0, true);

        self::assertSame(['sell', 'current', 30, 120, null, null], $this->phaseRow($cockpit, 'sell'));
        self::assertSame(['record_sale', false, 'sales', null, null, null, null, 90], $this->actionRow($cockpit));
    }

    public function testSalesBeyondTheUnitsToSellLeaveNothingToRecordAndLetTheManagerCompleteTheProject(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, 1, [
            $this->material('line-isogen', self::ISOGEN_TYPE_ID, 'Isogen', 900, 900),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 3, 3, 3),
        ], [self::ISHTAR_TYPE_ID => 5], 0, true);

        self::assertSame(['complete_project', false, null, 'completed', null, null, null, null], $this->actionRow($cockpit));
    }

    public function testProjectWithoutMaterialLinesHasAnEmptySupplyCounterThatIsNeverBlocked(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, 1, [
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 6, 6, 6, 6),
        ], [self::ISHTAR_TYPE_ID => 2], 0, false);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'done', 0, 0, null, null],
            ['produce', 'done', 1, 1, null, null],
            ['sell', 'current', 2, 6, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($cockpit));
    }

    public function testDraftProjectIsStartedByTheManagerWhilePrepareIsCurrentAtZeroOfOneAndAMemberHasNothingToDo(): void
    {
        $lines = [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 14_000, 0),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 1, 0, 1, 0),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 50, 0, 5, 0),
        ];

        $managerCockpit = $this->cockpitOf(ProjectStatus::Draft, 2, $lines, [], 0, true);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Draft, 2, $lines, [], 0, false);

        self::assertSame([
            ['prepare', 'current', 0, 1, null, null],
            ['supply', 'upcoming', 0, 1, null, null],
            ['produce', 'upcoming', 0, 2, null, null],
            ['sell', 'upcoming', 0, 51, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($memberCockpit));
        self::assertSame(self::NOTHING_TO_DO, $this->actionRow($memberCockpit));
        self::assertSame(['start_project', false, null, 'in_progress', null, null, null, null], $this->actionRow($managerCockpit));
    }

    public function testTwoPendingContributionsAreReviewedByTheManagerBeforeAMemberSourcesAPublishedProjectMaterial(): void
    {
        $lines = [
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 3_000, 3_000),
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 75, 20),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 1, 0, 1, 0),
        ];

        $managerCockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 2, true);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 2, false);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'current', 1, 2, null, null],
            ['produce', 'upcoming', 0, 1, null, null],
            ['sell', 'upcoming', 0, 1, null, null],
            ['close', 'upcoming', 0, 1, null, null],
        ], $this->phaseRows($managerCockpit));
        self::assertSame(['review_contributions', false, 'contributions', null, null, null, null, 2], $this->actionRow($managerCockpit));
        self::assertSame(
            ['source_material', false, 'materials', null, 'line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 55],
            $this->actionRow($memberCockpit),
        );
    }

    #[Group('issue-1219')]
    public function testPendingJoinRequestsAreHandledByTheManagerBeforeTheContributionsWhileAMemberIsNotAskedToAct(): void
    {
        $lines = [$this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 3_000, 3_000)];

        $managerCockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 2, true, pendingJoinRequestCount: 3);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 2, false, pendingJoinRequestCount: 3);

        self::assertSame(['review_join_requests', false, 'members', null, null, null, null, 3], $this->actionRow($managerCockpit));
        self::assertSame(self::NOTHING_TO_DO, $this->actionRow($memberCockpit));
    }

    #[Group('issue-1219')]
    public function testManagerWithoutPendingJoinRequestKeepsTheContributionsToReview(): void
    {
        $lines = [$this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 3_000, 3_000)];

        $cockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 2, true, pendingJoinRequestCount: 0);

        self::assertSame(['review_contributions', false, 'contributions', null, null, null, null, 2], $this->actionRow($cockpit));
    }

    #[Group('issue-1219')]
    public function testCompletedProjectLeadsToTheRecapEvenWithPendingJoinRequests(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Completed, 1, [], [], 0, true, pendingJoinRequestCount: 2);

        self::assertSame('copy_recap', $cockpit->nextAction->code);
    }

    public function testPublishedProjectWithEveryMaterialCoveredIsStartedByTheManagerWhileAMemberHasNothingToDo(): void
    {
        $lines = [
            $this->material('line-isogen', self::ISOGEN_TYPE_ID, 'Isogen', 1_200, 1_250),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 30, 0, 3, 0),
        ];

        $managerCockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 0, true);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Published, 1, $lines, [], 0, false);

        self::assertSame(['start_project', false, null, 'in_progress', null, null, null, null], $this->actionRow($managerCockpit));
        self::assertSame(self::NOTHING_TO_DO, $this->actionRow($memberCockpit));
    }

    public function testCompletedProjectWithAnUncoveredMaterialHasFiveDonePhasesAndIsCompletedEvenForAManagerWithPendingContributions(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Completed, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 18_000, 18_000),
            $this->material('line-mexallon', self::MEXALLON_TYPE_ID, 'Mexallon', 2_000, 1_500),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 1, 2, 1),
        ], [self::ISHTAR_TYPE_ID => 1], 3, true);

        self::assertSame([
            ['prepare', 'done', 1, 1, null, null],
            ['supply', 'done', 1, 2, null, null],
            ['produce', 'done', 0, 1, null, null],
            ['sell', 'done', 1, 2, null, null],
            ['close', 'done', 1, 1, null, null],
        ], $this->phaseRows($cockpit));
        self::assertSame(['copy_recap', false, 'finances', null, null, null, null, null], $this->actionRow($cockpit));
    }

    #[Group('issue-1218')]
    public function testCompletedProjectWithUnreceivedTransfersLeadsToSettlingThemWhateverTheRoleOfTheViewer(): void
    {
        $managerCockpit = $this->cockpitOf(ProjectStatus::Completed, 1, [], [], 3, true, 2);
        $memberCockpit = $this->cockpitOf(ProjectStatus::Completed, 1, [], [], 0, false, 2);

        self::assertSame(['settle_transfers', false, 'finances', null, null, null, null, 2], $this->actionRow($managerCockpit));
        self::assertSame(['settle_transfers', false, 'finances', null, null, null, null, 2], $this->actionRow($memberCockpit));
    }

    #[Group('issue-1218')]
    public function testCompletedProjectWithEveryTransferReceivedOrNoneToMakeLeadsToCopyingTheRecap(): void
    {
        $allReceived = $this->cockpitOf(ProjectStatus::Completed, 1, [], [], 0, false, 0);
        $noTransfer = $this->cockpitOf(ProjectStatus::Completed, 1, [], [], 0, true, 0);

        self::assertSame(['copy_recap', false, 'finances', null, null, null, null, null], $this->actionRow($allReceived));
        self::assertSame(['copy_recap', false, 'finances', null, null, null, null, null], $this->actionRow($noTransfer));
    }

    #[Group('issue-1218')]
    public function testCompletedProjectWithUnknownTransfersLeadsToCopyingTheRecapNotToSettlingThem(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Completed, 1, [], [], 0, true, null);

        self::assertSame(['copy_recap', false, 'finances', null, null, null, null, null], $this->actionRow($cockpit));
    }

    #[Group('issue-1218')]
    public function testUnreceivedTransferCountDoesNotChangeTheActionOfAProjectThatIsNotCompleted(): void
    {
        $lines = [
            $this->material('line-pyerite', self::PYERITE_TYPE_ID, 'Pyerite', 8_000, 8_000),
            $this->job('line-hammerhead', 'final', self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 100, 100, 10, 10),
        ];

        $cockpit = $this->cockpitOf(ProjectStatus::Selling, 1, $lines, [self::HAMMERHEAD_II_TYPE_ID => 100], 0, true, 2);

        self::assertSame(['complete_project', false, null, 'completed', null, null, null, null], $this->actionRow($cockpit));
    }

    public function testInProgressProjectWhoseJobLinesAreCoveredByDeliveredEsiJobsCountsThemProducedAndStartsSelling(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 200, 0, 2, 2, 2),
        ], [], 0, true);

        self::assertSame(['produce', 'current', 1, 1, null, null], $this->phaseRow($cockpit, 'produce'));
        self::assertSame(['start_selling', false, null, 'selling', null, null, null, null], $this->actionRow($cockpit));
    }

    public function testJobLineWhoseDeliveredRunsFallShortOfItsRunsStaysToLaunchWithTheRunsNotYetMatched(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, 3, 2, 1),
        ], [], 0, true);

        self::assertSame(['produce', 'current', 0, 1, null, null], $this->phaseRow($cockpit, 'produce'));
        self::assertSame(
            ['launch_job', false, 'materials', null, 'line-ram', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 1],
            $this->actionRow($cockpit),
        );
    }

    public function testSellingProjectWhoseJobLinesAreCoveredByDeliveredEsiJobsHasNoJobsUnfinishedBlock(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, 1, [
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 0, 2, 2, 2),
        ], [], 0, true);

        self::assertSame(['produce', 'done', 1, 1, null, null], $this->phaseRow($cockpit, 'produce'));
    }

    public function testJobLineWithoutRunsIsNotProducedByDeliveredRunsAlone(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, null, 0, 3),
        ], [], 0, true);

        self::assertSame(['produce', 'current', 0, 1, null, null], $this->phaseRow($cockpit, 'produce'));
    }

    #[Group('issue-1195')]
    public function testInProgressProjectWhoseJobRunsAreAllLaunchedAwaitsTheJobsUntilTheLatestEndDate(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->jobEnding('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 3, 3, 1, '2026-11-22 08:00:00'),
            $this->jobEnding('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 2, 2, 0, '2026-11-23 10:30:00'),
        ], [], 0, true);

        self::assertSame(['await_jobs', false, 'materials', null, null, null, null, null], $this->actionRow($cockpit));
        self::assertSame('2026-11-23 10:30:00', $cockpit->nextAction->endsAt?->format('Y-m-d H:i:s'));
    }

    #[Group('issue-1195')]
    public function testAwaitedJobsWithoutAnyKnownEndDateKeepAnUnknownEndInsteadOfADefault(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 300, 0, 3, 3, 1),
        ], [], 0, true);

        self::assertSame(['await_jobs', false, 'materials', null, null, null, null, null], $this->actionRow($cockpit));
        self::assertNull($cockpit->nextAction->endsAt);
    }

    #[Group('issue-1195')]
    public function testLaunchJobSkipsALineWhoseRunsAreAllLaunchedAndPicksTheLineStillToLaunch(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->jobEnding('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 3, 3, 1, '2026-11-22 08:00:00'),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 5, 0, 5, 2, 0),
        ], [], 0, true);

        self::assertSame(
            ['launch_job', false, 'materials', null, 'line-ishtar', self::ISHTAR_TYPE_ID, 'Ishtar', 3],
            $this->actionRow($cockpit),
        );
        self::assertNull($cockpit->nextAction->endsAt);
    }

    #[Group('issue-1195')]
    public function testMemberSeesTheAwaitedJobsLikeTheManager(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 5_000),
            $this->jobEnding('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 3, 3, 1, '2026-11-22 08:00:00'),
        ], [], 0, false);

        self::assertSame('await_jobs', $cockpit->nextAction->code);
    }

    #[Group('issue-1195')]
    public function testUncoveredMaterialStillComesBeforeTheAwaitedJobs(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 5_000, 4_000),
            $this->jobEnding('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 3, 3, 1, '2026-11-22 08:00:00'),
        ], [], 0, true);

        self::assertSame('source_material', $cockpit->nextAction->code);
    }

    #[Group('issue-1175')]
    public function testCoveredMaterialsWithPurchasesStillToDeliverKeepSupplyCurrentAndAskToDeliverThePurchases(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 9_000),
            $this->material('line-morphite', self::MORPHITE_TYPE_ID, 'Morphite', 120, 120),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
        ], [], 0, true, 2);

        self::assertSame(['supply', 'current', 2, 2, null, null], $this->phaseRow($cockpit, 'supply'));
        self::assertSame(['deliver_purchases', false, 'materials', null, null, null, null, 2], $this->actionRow($cockpit));
        self::assertSame(2, $cockpit->purchasesToDeliverCount);
    }

    #[Group('issue-1175')]
    public function testMemberAlsoSeesTheDeliveryOfThePurchases(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 9_000),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
        ], [], 0, false, 1);

        self::assertSame('deliver_purchases', $cockpit->nextAction->code);
    }

    #[Group('issue-1175')]
    public function testPendingContributionsStillComeBeforeTheDeliveryButTheCountIsKept(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 9_000),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
        ], [], 2, true, 3);

        self::assertSame(['review_contributions', 3], [$cockpit->nextAction->code, $cockpit->purchasesToDeliverCount]);
    }

    #[Group('issue-1175')]
    public function testUncoveredMaterialStillComesBeforeTheDeliveryOfPurchases(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 8_000),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
        ], [], 0, true, 2);

        self::assertSame('source_material', $cockpit->nextAction->code);
    }

    #[Group('issue-1175')]
    public function testOnceProductionHasStartedThePurchasesNoLongerBlockTheJobsNorKeepTheCount(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 9_000),
            $this->job('line-ram', 'component', self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 3, 0, 3, 3),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
        ], [], 0, true, 2);

        self::assertSame(['launch_job', 0], [$cockpit->nextAction->code, $cockpit->purchasesToDeliverCount]);
    }

    #[Group('issue-1175')]
    public function testWithoutPurchasesToDeliverTheJobIsLaunchedAndTheCountIsZero(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, 1, [
            $this->material('line-tritanium', self::TRITANIUM_TYPE_ID, 'Tritanium', 9_000, 9_000),
            $this->job('line-ishtar', 'final', self::ISHTAR_TYPE_ID, 'Ishtar', 3, 0, 3, 0),
        ], [], 0, true, 0);

        self::assertSame(['launch_job', 0], [$cockpit->nextAction->code, $cockpit->purchasesToDeliverCount]);
    }

    private function material(string $id, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): CockpitBomLine
    {
        return new CockpitBomLine($id, $typeId, $typeName, $requiredQuantity, $fulfilledQuantity, false, null, null, 0, 0);
    }

    private function job(
        string $id,
        string $jobGroup,
        int $typeId,
        string $typeName,
        int $requiredQuantity,
        int $fulfilledQuantity,
        ?int $runs,
        int $matchedRuns,
        int $deliveredRuns = 0,
    ): CockpitBomLine {
        return new CockpitBomLine($id, $typeId, $typeName, $requiredQuantity, $fulfilledQuantity, true, $jobGroup, $runs, $matchedRuns, $deliveredRuns);
    }

    private function jobEnding(
        string $id,
        string $jobGroup,
        int $typeId,
        string $typeName,
        int $runs,
        int $matchedRuns,
        int $deliveredRuns,
        string $latestJobEndDate,
    ): CockpitBomLine {
        return new CockpitBomLine($id, $typeId, $typeName, $runs, 0, true, $jobGroup, $runs, $matchedRuns, $deliveredRuns, new \DateTimeImmutable($latestJobEndDate));
    }

    private function cockpitOf(
        ProjectStatus $status,
        int $productCount,
        array $bomLines,
        array $soldQuantityByTypeId,
        int $pendingContributionCount,
        bool $viewerIsManager,
        ?int $unreceivedTransferCount = null,
        int $pendingJoinRequestCount = 0,
        int $purchasesToDeliverCount = 0,
    ): ProjectCockpit {
        return $this->calculator->calculate(new ProjectCockpitInput(
            $status,
            $productCount,
            $bomLines,
            $soldQuantityByTypeId,
            $pendingContributionCount,
            $viewerIsManager,
            $unreceivedTransferCount,
            $pendingJoinRequestCount,
            $purchasesToDeliverCount,
        ));
    }

    private function phaseRows(ProjectCockpit $cockpit): array
    {
        return array_map(
            static fn (CockpitPhase $phase): array => [
                $phase->key,
                $phase->state,
                $phase->counterDone,
                $phase->counterTotal,
                $phase->blockedReason?->code,
                $phase->blockedReason?->count,
            ],
            $cockpit->phases,
        );
    }

    private function phaseRow(ProjectCockpit $cockpit, string $key): ?array
    {
        foreach ($this->phaseRows($cockpit) as $row) {
            if ($row[0] === $key) {
                return $row;
            }
        }

        return null;
    }

    private function actionRow(ProjectCockpit $cockpit): array
    {
        $action = $cockpit->nextAction;

        return [$action->code, $action->blocked, $action->tab, $action->targetStatus, $action->bomLineId, $action->typeId, $action->typeName, $action->quantity];
    }
}
