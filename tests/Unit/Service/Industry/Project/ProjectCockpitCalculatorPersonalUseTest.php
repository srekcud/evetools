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
#[Group('issue-1210')]
final class ProjectCockpitCalculatorPersonalUseTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int ISHTAR_TYPE_ID = 12005;

    private ProjectCockpitCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProjectCockpitCalculator();
    }

    public function testPersonalUseProjectInProgressWithEverythingDeliveredHasFourPhasesAndCompletesTheProject(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, true, [
            $this->material('line-tritanium', 100, 100),
            $this->finalJob('line-ishtar', 1, 1),
        ]);

        self::assertSame([
            ['prepare', 'done', 1, 1],
            ['supply', 'done', 1, 1],
            ['produce', 'current', 1, 1],
            ['close', 'upcoming', 0, 1],
        ], $this->phaseRows($cockpit));
        self::assertSame(['complete_project', 'completed'], [$cockpit->nextAction->code, $cockpit->nextAction->targetStatus]);
    }

    public function testPersonalUseProjectInProgressWithEverythingDeliveredGivesANonManagerNothingToDo(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, true, [
            $this->material('line-tritanium', 100, 100),
            $this->finalJob('line-ishtar', 1, 1),
        ], viewerIsManager: false);

        self::assertSame('nothing_to_do', $cockpit->nextAction->code);
    }

    public function testPersonalUseProjectWithAFinalJobToLaunchKeepsLaunchJobAndHasNoSellPhase(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, true, [
            $this->finalJob('line-ishtar', 3),
        ]);

        self::assertSame(['launch_job', 'line-ishtar'], [$cockpit->nextAction->code, $cockpit->nextAction->bomLineId]);
        self::assertSame(['prepare', 'supply', 'produce', 'close'], $this->phaseKeys($cockpit));
    }

    public function testPersonalUseProjectInSellingStatusWithUnitsLeftCompletesInsteadOfRecordingASale(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Selling, true, [
            $this->finalJob('line-ishtar', 5, 5),
        ]);

        self::assertSame([
            ['prepare', 'done', 1, 1],
            ['supply', 'done', 0, 0],
            ['produce', 'done', 1, 1],
            ['close', 'current', 0, 1],
        ], $this->phaseRows($cockpit));
        self::assertSame(['complete_project', 'completed'], [$cockpit->nextAction->code, $cockpit->nextAction->targetStatus]);
    }

    public function testPersonalUseDraftProjectHasPrepareCurrentThenThreeUpcomingPhases(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Draft, true, [
            $this->finalJob('line-ishtar', 2),
        ]);

        self::assertSame([
            ['prepare', 'current', 0, 1],
            ['supply', 'upcoming', 0, 0],
            ['produce', 'upcoming', 0, 1],
            ['close', 'upcoming', 0, 1],
        ], $this->phaseRows($cockpit));
    }

    public function testPersonalUseCompletedProjectHasFourDonePhasesAndCopiesTheRecap(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::Completed, true, [
            $this->finalJob('line-ishtar', 2, 2),
        ]);

        self::assertSame([
            ['prepare', 'done', 1, 1],
            ['supply', 'done', 0, 0],
            ['produce', 'done', 1, 1],
            ['close', 'done', 1, 1],
        ], $this->phaseRows($cockpit));
        self::assertSame('copy_recap', $cockpit->nextAction->code);
    }

    public function testPersonalUseCompletedProjectWithUnreceivedTransfersFromEarlierSalesStillCopiesTheRecap(): void
    {
        $cockpit = $this->calculator->calculate(new ProjectCockpitInput(
            ProjectStatus::Completed,
            1,
            [$this->finalJob('line-ishtar', 1, 1)],
            [],
            0,
            true,
            unreceivedTransferCount: 2,
            personalUse: true,
        ));

        self::assertSame('copy_recap', $cockpit->nextAction->code);
    }

    public function testCompletedProjectWithoutPersonalUseSettlesItsUnreceivedTransfers(): void
    {
        $cockpit = $this->calculator->calculate(new ProjectCockpitInput(
            ProjectStatus::Completed,
            1,
            [$this->finalJob('line-ishtar', 1, 1)],
            [],
            0,
            true,
            unreceivedTransferCount: 2,
        ));

        self::assertSame('settle_transfers', $cockpit->nextAction->code);
    }

    public function testProjectWithoutPersonalUseKeepsFivePhasesAndStartsSellingOnceEverythingIsDelivered(): void
    {
        $cockpit = $this->cockpitOf(ProjectStatus::InProgress, false, [
            $this->material('line-tritanium', 100, 100),
            $this->finalJob('line-ishtar', 1, 1),
        ]);

        self::assertSame(['prepare', 'supply', 'produce', 'sell', 'close'], $this->phaseKeys($cockpit));
        self::assertSame(['start_selling', 'selling'], [$cockpit->nextAction->code, $cockpit->nextAction->targetStatus]);
    }

    private function material(string $id, int $requiredQuantity, int $fulfilledQuantity): CockpitBomLine
    {
        return new CockpitBomLine($id, self::TRITANIUM_TYPE_ID, 'Tritanium', $requiredQuantity, $fulfilledQuantity, false, null, null, 0, 0);
    }

    private function finalJob(string $id, int $quantity, int $deliveredRuns = 0): CockpitBomLine
    {
        return new CockpitBomLine($id, self::ISHTAR_TYPE_ID, 'Ishtar', $quantity, 0, true, 'final', $quantity, $deliveredRuns, $deliveredRuns);
    }

    /**
     * @param list<CockpitBomLine> $bomLines
     */
    private function cockpitOf(ProjectStatus $status, bool $personalUse, array $bomLines, bool $viewerIsManager = true): ProjectCockpit
    {
        return $this->calculator->calculate(new ProjectCockpitInput($status, 1, $bomLines, [], 0, $viewerIsManager, personalUse: $personalUse));
    }

    /**
     * @return list<string>
     */
    private function phaseKeys(ProjectCockpit $cockpit): array
    {
        return array_map(static fn (CockpitPhase $phase): string => $phase->key, $cockpit->phases);
    }

    /**
     * @return list<array{string, string, int, int}>
     */
    private function phaseRows(ProjectCockpit $cockpit): array
    {
        return array_map(
            static fn (CockpitPhase $phase): array => [$phase->key, $phase->state, $phase->counterDone, $phase->counterTotal],
            $cockpit->phases,
        );
    }
}
