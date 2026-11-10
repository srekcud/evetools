<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Schedule;
use App\Service\Industry\Project\PlanningCharacter;
use App\Service\Industry\Project\PlanningCharacterLane;
use App\Service\Industry\Project\PlanningJob;
use App\Service\Industry\Project\PlanningLine;
use App\Service\Industry\Project\PlanningLinkedJob;
use App\Service\Industry\Project\PlanningMaterial;
use App\Service\Industry\Project\PlanningRealSpan;
use App\Service\Industry\Project\PlanningSpan;
use App\Service\Industry\Project\PlanningUnscheduled;
use App\Service\Industry\Project\PlanningWait;
use App\Service\Industry\Project\ProjectPlanning;
use App\Service\Industry\Project\ProjectPlanningBuilder;
use App\Service\Industry\Project\ProjectPlanningInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectPlanningBuilder::class)]
final class ProjectPlanningBuilderTest extends TestCase
{
    private const int INDUSTRY = 3380;
    private const int ADVANCED_INDUSTRY = 3388;
    private const int REACTIONS = 45746;
    private const int MECHANICAL_ENGINEERING = 3395;
    private const int NUCLEAR_PHYSICS = 11451;
    private const int GRAVITON_PHYSICS = 11446;

    private const string DAY = '2026-03-01';

    public function testE7ManufacturingDurationAppliesTeIndustryAndAdvancedIndustry(): void
    {
        $planning = $this->build(
            [$this->line('l-plates', 3001, 'Capital Armor Plates', runs: 10, teLevel: 20, baseTimeSeconds: 6000)],
            [$this->character('Main', [self::INDUSTRY => 5, self::ADVANCED_INDUSTRY => 5])],
        );

        self::assertEquals($this->at('09:04:00'), $planning->plannedEnd);
    }

    public function testE8ReactionDurationAppliesOnlyTheReactionsSkill(): void
    {
        $planning = $this->build(
            [$this->line('l-carbide', 16673, 'Fernite Carbide', activity: 'reaction', runs: 5, teLevel: 0, baseTimeSeconds: 3600)],
            [$this->character('Main', [self::INDUSTRY => 5, self::ADVANCED_INDUSTRY => 5, self::REACTIONS => 4])],
        );

        self::assertEquals($this->at('04:12:00'), $planning->plannedEnd);
    }

    public function testEachBlueprintScienceSkillCutsOnePercentPerLevelAndAMissingSkillCountsAsLevelZero(): void
    {
        $planning = $this->build(
            [$this->line(
                'l-core',
                3002,
                'Fusion Reactor Unit',
                runs: 1,
                teLevel: 0,
                baseTimeSeconds: 1500,
                scienceSkillIds: [self::MECHANICAL_ENGINEERING, self::NUCLEAR_PHYSICS, self::GRAVITON_PHYSICS],
            )],
            [$this->character('Main', [self::MECHANICAL_ENGINEERING => 4, self::NUCLEAR_PHYSICS => 3])],
        );

        self::assertEquals($this->at('00:23:17'), $planning->plannedEnd);
    }

    #[Group('issue-338')]
    public function testStructureTimeModifierOfAManufacturingLineShortensItsDurationOnTopOfTheSkills(): void
    {
        $planning = $this->build(
            [$this->line('l-plates', 3001, 'Capital Armor Plates', runs: 10, teLevel: 20, baseTimeSeconds: 6000, structureTimeModifier: 0.5)],
            [$this->character('Main', [self::INDUSTRY => 5, self::ADVANCED_INDUSTRY => 5])],
        );

        self::assertEquals($this->at('04:32:00'), $planning->plannedEnd);
    }

    #[Group('issue-338')]
    public function testStructureTimeModifierOfAReactionLineShortensItsDuration(): void
    {
        $planning = $this->build(
            [$this->line('l-carbide', 16673, 'Fernite Carbide', activity: 'reaction', runs: 5, teLevel: 0, baseTimeSeconds: 3600, structureTimeModifier: 0.75)],
            [$this->character('Main', [self::REACTIONS => 4])],
        );

        self::assertEquals($this->at('03:09:00'), $planning->plannedEnd);
    }

    #[Group('issue-338')]
    public function testStructureTimeModifierIsPerLine(): void
    {
        $planning = $this->build(
            [
                $this->line('l-fast', 3040, 'Fast Hull', baseTimeSeconds: 7200, structureTimeModifier: 0.5),
                $this->line('l-slow', 3041, 'Slow Hull', baseTimeSeconds: 7200),
            ],
            [$this->character('Main', [], 2, 0)],
        );

        self::assertEquals(
            ['l-fast' => $this->at('01:00:00'), 'l-slow' => $this->at('02:00:00')],
            array_column(array_map(
                static fn (PlanningJob $job): array => ['id' => $job->lineId, 'end' => $job->planned->end],
                $planning->characters[0]->jobs,
            ), 'end', 'id'),
        );
    }

    #[Group('issue-338')]
    public function testStructureTimeBonusIsIncludedWhenEveryLineKnowsItsStructureTime(): void
    {
        $planning = $this->build(
            [
                $this->line('l-a', 3050, 'Hull A', structureTimeModifier: 0.9),
                $this->line('l-b', 3051, 'Hull B'),
            ],
            [$this->character('Main', [])],
        );

        self::assertTrue($planning->structureTimeBonusIncluded);
    }

    #[Group('issue-338')]
    public function testStructureTimeBonusIsNotIncludedWhenAPlannedStructureIsNoLongerConfiguredAndTheLineKeepsItsBaseDuration(): void
    {
        $planning = $this->build(
            [
                $this->line('l-a', 3050, 'Hull A', structureTimeModifier: 0.9),
                $this->line('l-b', 3051, 'Hull B', baseTimeSeconds: 3600, structureTimeKnown: false),
            ],
            [$this->character('Main', [], 2, 0)],
        );

        self::assertEquals(
            [false, $this->at('01:00:00')],
            [$planning->structureTimeBonusIncluded, $planning->plannedEnd],
        );
    }

    public function testLineWithoutDurationIsDurationUnknownAndItsConsumerDependencyUnscheduled(): void
    {
        $planning = $this->build(
            [
                $this->line('l-component', 3010, 'Capital Propulsion Engine', teLevel: 20, parentTypeId: 3011, baseTimeSeconds: null),
                $this->line('l-ship', 3011, 'Revelation', teLevel: 10, baseTimeSeconds: 9000),
                $this->line('l-module', 3012, 'Siege Module', teLevel: null, baseTimeSeconds: 4000),
            ],
            [$this->character('Main', [self::INDUSTRY => 3])],
        );

        self::assertEquals([
            'unscheduled' => [
                'l-component' => ['Capital Propulsion Engine', PlanningUnscheduled::DURATION_UNKNOWN],
                'l-ship' => ['Revelation', Schedule::DEPENDENCY_UNSCHEDULED],
                'l-module' => ['Siege Module', PlanningUnscheduled::DURATION_UNKNOWN],
            ],
            'plannedEnd' => null,
        ], ['unscheduled' => $this->unscheduledOf($planning), 'plannedEnd' => $planning->plannedEnd]);
    }

    public function testWithoutAnyCharacterWithSkillsEveryLineHasNoEligibleWorker(): void
    {
        $planning = $this->build(
            [
                $this->line('l-hull', 3020, 'Construction Blocks', baseTimeSeconds: 1200),
                $this->line('l-gel', 16682, 'Hypersynaptic Fibers', activity: 'reaction', baseTimeSeconds: 10800),
            ],
            [$this->character('Unsynced', null)],
        );

        self::assertEquals([
            'unscheduled' => [
                'l-hull' => ['Construction Blocks', Schedule::NO_ELIGIBLE_WORKER],
                'l-gel' => ['Hypersynaptic Fibers', Schedule::NO_ELIGIBLE_WORKER],
            ],
            'plannedEnd' => null,
        ], ['unscheduled' => $this->unscheduledOf($planning), 'plannedEnd' => $planning->plannedEnd]);
    }

    public function testProjectWithoutJobLineListsCharacterLanesWithoutJobsAndNullEnds(): void
    {
        $planning = (new ProjectPlanningBuilder())->build(
            new ProjectPlanningInput(
                $this->at('00:00:00'),
                [],
                [],
                [
                    $this->character('Main', [self::INDUSTRY => 5, self::REACTIONS => 2], 9, 4),
                    $this->character('Unsynced', null, null, null),
                ],
            ),
            $this->at('06:00:00'),
        );

        self::assertEquals(new ProjectPlanning(
            $this->at('00:00:00'),
            true,
            null,
            null,
            null,
            null,
            [
                new PlanningCharacterLane(
                    'Main',
                    false,
                    ['manufacturing' => 9, 'reaction' => 4],
                    ['industry' => 5, 'advancedIndustry' => 0, 'reactions' => 2],
                    null,
                    [],
                ),
                new PlanningCharacterLane('Unsynced', true, null, null, null, []),
            ],
            [],
        ), $planning);
    }

    public function testLaneCarriesSlotsTimeSkillsLatestPlannedEndAndJobsSortedByPlannedStart(): void
    {
        $planning = $this->build(
            [
                $this->line('l-titan', 3030, 'Avatar', runs: 1, teLevel: 0, baseTimeSeconds: 10000),
                $this->line('l-plates', 3031, 'Capital Armor Plates', runs: 2, teLevel: 10, parentTypeId: 3030, baseTimeSeconds: 5000),
            ],
            [$this->character('Main', [self::INDUSTRY => 4, self::ADVANCED_INDUSTRY => 2, self::REACTIONS => 1], 1, 3)],
        );

        self::assertEquals([new PlanningCharacterLane(
            'Main',
            false,
            ['manufacturing' => 1, 'reaction' => 3],
            ['industry' => 4, 'advancedIndustry' => 2, 'reactions' => 1],
            $this->at('04:10:02'),
            [
                new PlanningJob('l-plates', 3031, 'Capital Armor Plates', 'manufacturing', 2, new PlanningSpan($this->at('00:00:00'), $this->at('01:58:26')), null, null),
                new PlanningJob('l-titan', 3030, 'Avatar', 'manufacturing', 1, new PlanningSpan($this->at('01:58:26'), $this->at('04:10:02')), null, null),
            ],
        )], $planning->characters);
    }

    public function testJobRunByACharacterOutsideTheOwnerIsShownInANewLaneAfterTheOwnerCharacters(): void
    {
        $planning = $this->build(
            [$this->line('l-drone', 3040, 'Warrior II', baseTimeSeconds: 3600, linkedJobs: [
                $this->linked('active', 1, '00:30:00', '01:30:00', 'Outsider'),
            ])],
            [$this->character('Unsynced', null, null, null), $this->character('Main', [], 1, 1)],
            $this->at('01:00:00'),
        );

        self::assertEquals([
            new PlanningCharacterLane('Unsynced', true, null, null, null, []),
            new PlanningCharacterLane(
                'Main',
                false,
                ['manufacturing' => 1, 'reaction' => 1],
                ['industry' => 0, 'advancedIndustry' => 0, 'reactions' => 0],
                $this->at('01:00:00'),
                [],
            ),
            new PlanningCharacterLane('Outsider', false, null, null, null, [new PlanningJob(
                'l-drone',
                3040,
                'Warrior II',
                'manufacturing',
                1,
                new PlanningSpan($this->at('00:00:00'), $this->at('01:00:00')),
                new PlanningRealSpan($this->at('00:30:00'), $this->at('01:30:00'), 'active'),
                new PlanningWait(PlanningWait::UNKNOWN, [], 1800),
            )]),
        ], $planning->characters);
    }

    public function testRealSpanIgnoresCancelledAndRevertedJobsAndIsDoneWhenDeliveredRunsCoverTheLine(): void
    {
        $planning = $this->build(
            [$this->line('l-ammo', 3050, 'Hail L', runs: 10, baseTimeSeconds: 600, linkedJobs: [
                $this->linked('delivered', 4, '01:00:00', '03:00:00', 'Main'),
                $this->linked('cancelled', 10, '00:00:00', '09:00:00', 'Main'),
                $this->linked('delivered', 6, '02:00:00', '05:00:00', 'Main'),
                $this->linked('reverted', 3, '00:15:00', '08:00:00', 'Main'),
            ])],
            [$this->character('Main', [])],
            $this->at('06:00:00'),
        );

        self::assertEquals(
            new PlanningRealSpan($this->at('01:00:00'), $this->at('05:00:00'), 'done'),
            $this->jobOf($planning, 'l-ammo')?->real,
        );
    }

    public function testLinkedJobWithAnUnexpectedStatusIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->build(
            [$this->line('l-ammo', 3050, 'Hail L', baseTimeSeconds: 600, linkedJobs: [
                $this->linked('failed', 1, '01:00:00', '02:00:00', 'Main'),
            ])],
            [$this->character('Main', [])],
        );
    }

    public function testE9ProductLaunchedAfterItsLateComponentWaitsForThatJobAndShiftsTheExpectedEnd(): void
    {
        $planning = $this->build(
            [
                $this->line('l-component', 100, 'Capital Armor Plates', baseTimeSeconds: 28800, parentTypeId: 200, linkedJobs: [
                    $this->linked('delivered', 1, '00:00:00', '12:00:00', 'Main'),
                ]),
                $this->line('l-product', 200, 'Revelation', baseTimeSeconds: 7200, linkedJobs: [
                    $this->linked('active', 1, '12:00:00', '14:00:00', 'Main'),
                ]),
            ],
            [$this->character('Main', [], 2, 0)],
            $this->at('13:00:00'),
            [new PlanningMaterial('Morphite', 300, 120)],
        );

        self::assertEquals([
            'plannedEnd' => $this->at('10:00:00'),
            'expectedEnd' => $this->at('14:00:00'),
            'delaySeconds' => 14400,
            'delayCause' => new PlanningWait(PlanningWait::WAITING_JOB, ['Capital Armor Plates'], 14400),
        ], [
            'plannedEnd' => $planning->plannedEnd,
            'expectedEnd' => $planning->expectedEnd,
            'delaySeconds' => $planning->delaySeconds,
            'delayCause' => $planning->delayCause,
        ]);
    }

    public function testLateJobWithoutLateDependencyWaitsForTheMissingMaterialsOfTheProject(): void
    {
        $planning = $this->build(
            [$this->line('l-ship', 3060, 'Hurricane', baseTimeSeconds: 7200, linkedJobs: [
                $this->linked('active', 1, '03:00:00', '05:00:00', 'Main'),
            ])],
            [$this->character('Main', [])],
            $this->at('04:00:00'),
            [
                new PlanningMaterial('Morphite', 100, 40),
                new PlanningMaterial('Tritanium', 500000, 500000),
                new PlanningMaterial('Zydrine', 30, 0),
            ],
        );

        self::assertEquals(
            new PlanningWait(PlanningWait::WAITING_MATERIALS, ['Morphite', 'Zydrine'], 10800),
            $planning->delayCause,
        );
    }

    public function testDeliveryEarlierThanPlannedGivesANegativeDelay(): void
    {
        $planning = $this->build(
            [$this->line('l-freighter', 3070, 'Charon', baseTimeSeconds: 36000, linkedJobs: [
                $this->linked('delivered', 1, '00:00:00', '06:00:00', 'Main'),
            ])],
            [$this->character('Main', [])],
            $this->at('07:00:00'),
        );

        self::assertEquals(
            ['expectedEnd' => $this->at('06:00:00'), 'delaySeconds' => -14400],
            ['expectedEnd' => $planning->expectedEnd, 'delaySeconds' => $planning->delaySeconds],
        );
    }

    public function testExpectedEndIsTheLatestOfTheRealEndsAndTheLinesReplannedFromNow(): void
    {
        $planning = $this->build(
            [
                $this->line('l-unlaunched', 3080, 'Large Shield Extender II', baseTimeSeconds: 7200),
                $this->line('l-running', 3081, 'Damage Control II', baseTimeSeconds: 3600, linkedJobs: [
                    $this->linked('active', 1, '01:00:00', '06:00:00', 'Main'),
                ]),
            ],
            [$this->character('Main', [], 1, 0)],
            $this->at('05:00:00'),
        );

        self::assertEquals(
            ['plannedEnd' => $this->at('03:00:00'), 'expectedEnd' => $this->at('07:00:00'), 'delaySeconds' => 14400],
            ['plannedEnd' => $planning->plannedEnd, 'expectedEnd' => $planning->expectedEnd, 'delaySeconds' => $planning->delaySeconds],
        );
    }

    /**
     * @param list<PlanningLine>      $lines
     * @param list<PlanningCharacter> $characters
     * @param list<PlanningMaterial>  $materials
     */
    private function build(array $lines, array $characters, ?\DateTimeImmutable $now = null, array $materials = []): ProjectPlanning
    {
        return (new ProjectPlanningBuilder())->build(
            new ProjectPlanningInput($this->at('00:00:00'), $lines, $materials, $characters),
            $now ?? $this->at('00:00:00'),
        );
    }

    /**
     * @param list<int>               $scienceSkillIds
     * @param list<PlanningLinkedJob> $linkedJobs
     */
    private function line(
        string $lineId,
        int $typeId,
        string $typeName,
        string $activity = 'manufacturing',
        int $runs = 1,
        ?int $teLevel = 0,
        ?int $parentTypeId = null,
        ?int $baseTimeSeconds = 3600,
        array $scienceSkillIds = [],
        array $linkedJobs = [],
        float $structureTimeModifier = 1.0,
        bool $structureTimeKnown = true,
    ): PlanningLine {
        return new PlanningLine(
            $lineId,
            $typeId,
            $typeName,
            $activity,
            $runs,
            $teLevel,
            $parentTypeId,
            $baseTimeSeconds,
            $scienceSkillIds,
            $linkedJobs,
            new Multiplier($structureTimeModifier),
            $structureTimeKnown,
        );
    }

    /**
     * @param array<int, int>|null $skillLevels
     */
    private function character(string $name, ?array $skillLevels, ?int $manufacturingSlots = 1, ?int $reactionSlots = 1): PlanningCharacter
    {
        return new PlanningCharacter($name, $skillLevels, $manufacturingSlots, $reactionSlots);
    }

    private function linked(string $status, int $runs, string $start, string $end, string $characterName): PlanningLinkedJob
    {
        return new PlanningLinkedJob($status, $runs, $this->at($start), $this->at($end), $characterName);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::DAY.'T'.$time.'+00:00');
    }

    private function jobOf(ProjectPlanning $planning, string $lineId): ?PlanningJob
    {
        foreach ($planning->characters as $lane) {
            foreach ($lane->jobs as $job) {
                if ($job->lineId === $lineId) {
                    return $job;
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
