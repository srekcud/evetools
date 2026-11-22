<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Schedule;
use App\Service\Industry\Project\PlanningCharacter;
use App\Service\Industry\Project\PlanningJob;
use App\Service\Industry\Project\PlanningLeftOutLine;
use App\Service\Industry\Project\PlanningLine;
use App\Service\Industry\Project\PlanningMaterial;
use App\Service\Industry\Project\PlanningUnscheduled;
use App\Service\Industry\Project\PlanningWait;
use App\Service\Industry\Project\ProjectPlanning;
use App\Service\Industry\Project\ProjectPlanningBuilder;
use App\Service\Industry\Project\ProjectPlanningInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectPlanningBuilder::class)]
#[Group('issue-1204')]
final class ProjectPlanningBuilderCopyAndInventionTest extends TestCase
{
    private const int INDUSTRY = 3380;
    private const int ADVANCED_INDUSTRY = 3388;
    private const int SCIENCE = 3402;

    private const int HORNET = 2048;
    private const int RAVEN = 638;
    private const int HORNET_II = 2049;

    private const string DAY = '2026-03-01';

    public function testCopyIsPlannedBeforeTheManufacturingItFeedsAndThePlannedEndIsTheEndOfTheManufacturing(): void
    {
        $planning = $this->build(
            [
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480),
                $this->manufacturing('l-hornet', self::HORNET, 'Hornet', baseTimeSeconds: 4000),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertEquals([
            'l-copy-hornet' => ['00:00:00', '00:51:00'],
            'l-hornet' => ['00:51:00', '01:47:40'],
            'plannedEnd' => $this->at('01:47:40'),
        ], [
            'l-copy-hornet' => $this->plannedSpanOf($planning, 'l-copy-hornet'),
            'l-hornet' => $this->plannedSpanOf($planning, 'l-hornet'),
            'plannedEnd' => $planning->plannedEnd,
        ]);
    }

    public function testManufacturingWaitsForItsCopyEvenWhenAManufacturingSlotIsFree(): void
    {
        $planning = $this->build(
            [
                $this->manufacturing('l-hornet', self::HORNET, 'Hornet', baseTimeSeconds: 4000),
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 3, 0, 1)],
        );

        self::assertEquals(['00:51:00', '01:47:40'], $this->plannedSpanOf($planning, 'l-hornet'));
    }

    public function testTwoCopiesShareASingleLaboratorySlotOneAfterAnother(): void
    {
        $planning = $this->build(
            [
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480),
                $this->copy('l-copy-raven', self::RAVEN, 'Raven', runs: 4, baseTimeSeconds: 300),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertEquals([
            'l-copy-hornet' => ['00:00:00', '00:51:00'],
            'l-copy-raven' => ['00:51:00', '01:03:45'],
            'plannedEnd' => $this->at('01:03:45'),
        ], [
            'l-copy-hornet' => $this->plannedSpanOf($planning, 'l-copy-hornet'),
            'l-copy-raven' => $this->plannedSpanOf($planning, 'l-copy-raven'),
            'plannedEnd' => $planning->plannedEnd,
        ]);
    }

    public function testTwoCopiesRunInParallelOnTwoLaboratorySlots(): void
    {
        $planning = $this->build(
            [
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480),
                $this->copy('l-copy-raven', self::RAVEN, 'Raven', runs: 4, baseTimeSeconds: 300),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 2)],
        );

        self::assertEquals([
            'l-copy-hornet' => ['00:00:00', '00:51:00'],
            'l-copy-raven' => ['00:00:00', '00:12:45'],
            'plannedEnd' => $this->at('00:51:00'),
        ], [
            'l-copy-hornet' => $this->plannedSpanOf($planning, 'l-copy-hornet'),
            'l-copy-raven' => $this->plannedSpanOf($planning, 'l-copy-raven'),
            'plannedEnd' => $planning->plannedEnd,
        ]);
    }

    public function testCopyDurationAppliesTheScienceAndAdvancedIndustrySkillsAndAMissingSkillCountsAsLevelZero(): void
    {
        $planning = $this->build(
            [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480)],
            [$this->character('Main', [self::SCIENCE => 5, self::INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertEquals($this->at('01:00:00'), $planning->plannedEnd);
    }

    public function testCopyDurationAppliesTheStructureTimeModifierOnTopOfTheSkills(): void
    {
        $planning = $this->build(
            [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480, structureTimeModifier: 0.5)],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertEquals($this->at('00:25:30'), $planning->plannedEnd);
    }

    public function testCopyAppearsInTheLaneOfTheCharacterWithTheCopyActivityAndItsRuns(): void
    {
        $planning = $this->build(
            [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480)],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertSame(
            [['l-copy-hornet', self::HORNET, 'Hornet', 'copy', 10]],
            array_map(
                static fn (PlanningJob $job): array => [$job->lineId, $job->typeId, $job->typeName, $job->activity, $job->runs],
                $planning->characters[0]->jobs,
            ),
        );
    }

    public function testCopyWithoutPlannedStructureIsDurationUnknownItsManufacturingDependencyUnscheduledAndThePlannedEndNull(): void
    {
        $planning = $this->build(
            [
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: null),
                $this->manufacturing('l-hornet', self::HORNET, 'Hornet', baseTimeSeconds: 4000),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertEquals([
            'unscheduled' => [
                'l-copy-hornet' => ['Hornet', PlanningUnscheduled::DURATION_UNKNOWN],
                'l-hornet' => ['Hornet', Schedule::DEPENDENCY_UNSCHEDULED],
            ],
            'plannedEnd' => null,
        ], ['unscheduled' => $this->unscheduledOf($planning), 'plannedEnd' => $planning->plannedEnd]);
    }

    public function testCharacterWithoutLaboratorySlotCannotRunACopy(): void
    {
        $planning = $this->build(
            [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480)],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 4, 3, 0)],
        );

        self::assertEquals([
            'unscheduled' => ['l-copy-hornet' => ['Hornet', Schedule::NO_ELIGIBLE_WORKER]],
            'plannedEnd' => null,
        ], ['unscheduled' => $this->unscheduledOf($planning), 'plannedEnd' => $planning->plannedEnd]);
    }

    public function testCharacterWithCachedSkillsButUnknownLaboratorySlotsIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Character "Main" has cached skills but unknown slots.');

        $this->build(
            [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480)],
            [$this->character('Main', [self::SCIENCE => 5], 1, 0, null)],
        );
    }

    public function testCopyWithoutLinkedJobIsReplannedFromNowAndShiftsTheExpectedEndAndTheDelay(): void
    {
        $planning = $this->build(
            [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480)],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
            $this->at('02:00:00'),
        );

        self::assertEquals([
            'plannedEnd' => $this->at('00:51:00'),
            'expectedEnd' => $this->at('02:51:00'),
            'delaySeconds' => 7200,
        ], [
            'plannedEnd' => $planning->plannedEnd,
            'expectedEnd' => $planning->expectedEnd,
            'delaySeconds' => $planning->delaySeconds,
        ]);
    }

    public function testCopyDoesNotWaitForTheComponentsOfTheProductItIsCopiedFor(): void
    {
        $component = new PlanningLine('l-component', 1000, 'Component', 'manufacturing', 1, 0, self::HORNET, 3600, [], []);
        $planning = $this->build(
            [
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480),
                $component,
                $this->manufacturing('l-hornet', self::HORNET, 'Hornet', baseTimeSeconds: 4000),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 3, 0, 1)],
        );

        self::assertEquals(
            [['00:00:00', '00:51:00'], ['00:51:00', '01:47:40']],
            [$this->plannedSpanOf($planning, 'l-copy-hornet'), $this->plannedSpanOf($planning, 'l-hornet')],
        );
    }

    public function testLateCopyIsNotBlamedOnMissingMaterialsBecauseACopyConsumesNone(): void
    {
        $planning = (new ProjectPlanningBuilder())->build(
            new ProjectPlanningInput(
                $this->at('00:00:00'),
                [$this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480)],
                [new PlanningMaterial('Tritanium', 100, 0)],
                [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
            ),
            $this->at('02:00:00'),
        );

        self::assertSame(PlanningWait::UNKNOWN, $planning->delayCause?->kind);
    }

    public function testInventionIsListedAsNotPlannedAndLeavesThePlanTheLanesAndTheEndsUntouched(): void
    {
        $planning = $this->build(
            [
                $this->copy('l-copy-hornet', self::HORNET, 'Hornet', runs: 10, baseTimeSeconds: 480),
                $this->manufacturing('l-hornet-ii', self::HORNET_II, 'Hornet II', baseTimeSeconds: 2000),
            ],
            [$this->character('Main', [self::SCIENCE => 5, self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
            inventionLines: [new PlanningLeftOutLine('l-invention-hornet-ii', 'Hornet II')],
        );

        self::assertEquals([
            'unscheduled' => ['l-invention-hornet-ii' => ['Hornet II', PlanningUnscheduled::INVENTION_NOT_PLANNED]],
            'jobs' => ['l-copy-hornet', 'l-hornet-ii'],
            'ends' => [$this->at('00:51:00'), $this->at('00:51:00'), 0],
        ], [
            'unscheduled' => $this->unscheduledOf($planning),
            'jobs' => array_map(static fn (PlanningJob $job): string => $job->lineId, $planning->characters[0]->jobs),
            'ends' => [$planning->plannedEnd, $planning->expectedEnd, $planning->delaySeconds],
        ]);
    }

    public function testInventionsComeAfterTheUnscheduledLinesOfThePlan(): void
    {
        $planning = $this->build(
            [$this->manufacturing('l-raven', self::RAVEN, 'Raven', baseTimeSeconds: null)],
            [$this->character('Main', [self::SCIENCE => 5], 1, 0, 1)],
            inventionLines: [
                new PlanningLeftOutLine('l-invention-hornet-ii', 'Hornet II'),
                new PlanningLeftOutLine('l-invention-raven-navy', 'Raven Navy Issue'),
            ],
        );

        self::assertSame([
            ['l-raven', 'Raven', PlanningUnscheduled::DURATION_UNKNOWN],
            ['l-invention-hornet-ii', 'Hornet II', PlanningUnscheduled::INVENTION_NOT_PLANNED],
            ['l-invention-raven-navy', 'Raven Navy Issue', PlanningUnscheduled::INVENTION_NOT_PLANNED],
        ], array_map(
            static fn (PlanningUnscheduled $line): array => [$line->lineId, $line->typeName, $line->reason],
            $planning->unscheduled,
        ));
    }

    public function testInventionAloneGivesNoPlannedEndAndNoJobInTheLane(): void
    {
        $planning = $this->build(
            [],
            [$this->character('Main', [self::SCIENCE => 5], 1, 0, 1)],
            inventionLines: [new PlanningLeftOutLine('l-invention-hornet-ii', 'Hornet II')],
        );

        self::assertEquals([
            'unscheduled' => ['l-invention-hornet-ii' => ['Hornet II', PlanningUnscheduled::INVENTION_NOT_PLANNED]],
            'jobs' => [],
            'plannedEnd' => null,
        ], [
            'unscheduled' => $this->unscheduledOf($planning),
            'jobs' => $planning->characters[0]->jobs,
            'plannedEnd' => $planning->plannedEnd,
        ]);
    }

    public function testPlanWithoutCopyNorInventionKeepsItsDurationsAndHasNothingUnscheduled(): void
    {
        $planning = $this->build(
            [$this->manufacturing('l-hornet', self::HORNET, 'Hornet', baseTimeSeconds: 4000)],
            [$this->character('Main', [self::ADVANCED_INDUSTRY => 5], 1, 0, 1)],
        );

        self::assertEquals(
            [$this->at('00:56:40'), []],
            [$planning->plannedEnd, $planning->unscheduled],
        );
    }

    /**
     * @param list<PlanningLine>        $lines
     * @param list<PlanningCharacter>   $characters
     * @param list<PlanningLeftOutLine> $inventionLines
     */
    private function build(array $lines, array $characters, ?\DateTimeImmutable $now = null, array $inventionLines = []): ProjectPlanning
    {
        return (new ProjectPlanningBuilder())->build(
            new ProjectPlanningInput($this->at('00:00:00'), $lines, [], $characters, $inventionLines),
            $now ?? $this->at('00:00:00'),
        );
    }

    private function copy(string $lineId, int $typeId, string $typeName, int $runs, ?int $baseTimeSeconds, float $structureTimeModifier = 1.0): PlanningLine
    {
        return new PlanningLine(
            $lineId,
            $typeId,
            $typeName,
            'copy',
            $runs,
            0,
            null,
            $baseTimeSeconds,
            [],
            [],
            new Multiplier($structureTimeModifier),
        );
    }

    private function manufacturing(string $lineId, int $typeId, string $typeName, ?int $baseTimeSeconds): PlanningLine
    {
        return new PlanningLine($lineId, $typeId, $typeName, 'manufacturing', 1, 0, null, $baseTimeSeconds, [], []);
    }

    /**
     * @param array<int, int>|null $skillLevels
     */
    private function character(string $name, ?array $skillLevels, ?int $manufacturingSlots, ?int $reactionSlots, ?int $scienceSlots): PlanningCharacter
    {
        return new PlanningCharacter($name, $skillLevels, $manufacturingSlots, $reactionSlots, $scienceSlots);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::DAY.'T'.$time.'+00:00');
    }

    /**
     * @return array{string, string}|null
     */
    private function plannedSpanOf(ProjectPlanning $planning, string $lineId): ?array
    {
        foreach ($planning->characters as $lane) {
            foreach ($lane->jobs as $job) {
                if ($job->lineId === $lineId && null !== $job->planned) {
                    return [$job->planned->start->format('H:i:s'), $job->planned->end->format('H:i:s')];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function unscheduledOf(ProjectPlanning $planning): array
    {
        $unscheduled = [];
        foreach ($planning->unscheduled as $line) {
            $unscheduled[$line->lineId] = [$line->typeName, $line->reason];
        }

        return $unscheduled;
    }
}
