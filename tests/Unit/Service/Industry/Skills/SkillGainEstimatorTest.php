<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Skills;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\User;
use App\Enum\SkillEffect;
use App\Enum\SkillGainUnknownReason;
use App\Enum\SkillTrainingUnknownReason;
use App\Industry\Application\InventionSkillRequirement;
use App\Industry\Application\InventionSkillRequirements;
use App\Repository\CachedCharacterSkillRepository;
use App\Service\Industry\Revenue\IndustryRevenueReader;
use App\Service\Industry\Revenue\IndustryRevenueReport;
use App\Service\Industry\Revenue\IndustryRevenueTotals;
use App\Service\Industry\Skills\OwnProjectJobs;
use App\Service\Industry\Skills\OwnProjectJobsLoader;
use App\Service\Industry\Skills\SkillGainEstimator;
use App\Service\Industry\Skills\SkillGainLine;
use App\Service\Industry\Skills\SkillGainReport;
use App\Service\Industry\Skills\SkillTrainingEstimate;
use App\Service\Industry\Skills\SkillTrainingEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillGainEstimator::class)]
#[Group('issue-359')]
final class SkillGainEstimatorTest extends TestCase
{
    private const int INDUSTRY = 3380;
    private const int ADVANCED_INDUSTRY = 3388;
    private const int REACTIONS = 45746;
    private const int MASS_PRODUCTION = 3387;
    private const int ADVANCED_MASS_PRODUCTION = 24625;
    private const int MASS_REACTIONS = 45749;
    private const int LABORATORY_OPERATION = 3406;
    private const int FIRST_SCIENCE = 2001;
    private const int SECOND_SCIENCE = 2002;
    private const int ENCRYPTION = 3001;
    private const int T1_BLUEPRINT = 5001;
    private const int MANUFACTURING = 1;
    private const int REACTION = 9;
    private const int REACTION_FORMULA = 11;
    private const int COPYING = 5;
    private const int INVENTION = 8;
    private const float ISK_PER_HOUR = 1_200_000.0;
    private const float HOURS_TOLERANCE = 0.0001;
    private const int ONE_DAY = 86_400;

    private User $user;
    private Character $character;
    private array $jobs = [];
    private bool $jobsComplete = true;
    private ?float $iskPerHour = self::ISK_PER_HOUR;
    private array $skillRows = [];
    private array $trainingSecondsBySkillId = [];
    private array $trainingUnknownBySkillId = [];
    private array $trainingCalls = [];

    protected function setUp(): void
    {
        $this->user = new User();
        $this->character = (new Character())->setName('Gain Pilot')->setEveCharacterId(2_112_000_402);
        $this->user->addCharacter($this->character);
        $this->skillRows = [self::INDUSTRY => $this->skill(self::INDUSTRY, 2)];
    }

    public function testATimeSkillSavesItsShareOfTheManufacturingHoursOfTheMonth(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100)];

        $line = $this->lineOf(self::INDUSTRY);

        $this->assertSame([2, 3, SkillEffect::ManufacturingTime, null], [$line->currentLevel, $line->targetLevel, $line->effect, $line->gainUnknownReason]);
        $this->assertEqualsWithDelta(4.3478, $line->hoursSavedPerMonth, self::HOURS_TOLERANCE);
        $this->assertEqualsWithDelta(100 * 0.04 / 0.92 * self::ISK_PER_HOUR, $line->monthlyGain, 1.0);
    }

    public function testASkillAbsentFromASynchronizedCharacterIsAtLevelZero(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100)];

        $line = $this->lineOf(self::ADVANCED_INDUSTRY);

        $this->assertSame([0, 1], [$line->currentLevel, $line->targetLevel]);
        $this->assertEqualsWithDelta(3.0, $line->hoursSavedPerMonth, self::HOURS_TOLERANCE);
    }

    public function testReactionsSaveTheHoursOfBothReactionActivitiesAndNoManufacturingHours(): void
    {
        $this->jobs = [$this->job(self::REACTION, 50), $this->job(self::REACTION_FORMULA, 10), $this->job(self::MANUFACTURING, 100)];

        $line = $this->lineOf(self::REACTIONS);

        $this->assertSame(SkillEffect::ReactionTime, $line->effect);
        $this->assertEqualsWithDelta(2.4, $line->hoursSavedPerMonth, self::HOURS_TOLERANCE);
    }

    public function testManufacturingSkillsIgnoreTheReactionHours(): void
    {
        $this->jobs = [$this->job(self::REACTION, 500), $this->job(self::MANUFACTURING, 100)];

        $this->assertEqualsWithDelta(4.3478, $this->lineOf(self::INDUSTRY)->hoursSavedPerMonth, self::HOURS_TOLERANCE);
    }

    public function testEachSkillRequiredByAnInventionJobHasItsOwnLine(): void
    {
        $this->inventionSkills(3, 3, 2);
        $this->jobs = [$this->job(self::INVENTION, 10, self::T1_BLUEPRINT)];

        $first = $this->lineOf(self::FIRST_SCIENCE);
        $second = $this->lineOf(self::SECOND_SCIENCE);
        $encryption = $this->lineOf(self::ENCRYPTION);

        $this->assertSame(SkillEffect::InventionChance, $first->effect);
        $this->assertEqualsWithDelta(0.25974, $first->hoursSavedPerMonth, self::HOURS_TOLERANCE);
        $this->assertEqualsWithDelta(0.25974, $second->hoursSavedPerMonth, self::HOURS_TOLERANCE);
        $this->assertEqualsWithDelta(0.19608, $encryption->hoursSavedPerMonth, self::HOURS_TOLERANCE);
        $this->assertEqualsWithDelta(0.25974 * self::ISK_PER_HOUR, $first->monthlyGain, 1.0);
    }

    public function testTheInventionHoursOfSeveralJobsAreSummed(): void
    {
        $this->inventionSkills(3, 3, 2);
        $this->jobs = [$this->job(self::INVENTION, 10, self::T1_BLUEPRINT), $this->job(self::INVENTION, 20, self::T1_BLUEPRINT)];

        $this->assertEqualsWithDelta(0.77922, $this->lineOf(self::FIRST_SCIENCE)->hoursSavedPerMonth, self::HOURS_TOLERANCE);
        $this->assertCount(3, array_filter($this->report()->lines, static fn (SkillGainLine $line): bool => $line->effect === SkillEffect::InventionChance));
    }

    public function testAnInventionSkillAbsentFromTheCacheIsAtLevelZero(): void
    {
        $this->inventionSkills(3, 3, 2);
        unset($this->skillRows[self::ENCRYPTION]);
        $this->jobs = [$this->job(self::INVENTION, 10, self::T1_BLUEPRINT)];

        $this->assertSame([0, 1], [$this->lineOf(self::ENCRYPTION)->currentLevel, $this->lineOf(self::ENCRYPTION)->targetLevel]);
    }

    public function testThereIsNoInventionLineWithoutInventionJob(): void
    {
        $this->inventionSkills(3, 3, 2);
        $this->jobs = [$this->job(self::MANUFACTURING, 10)];

        $this->assertCount(0, array_filter($this->report()->lines, static fn (SkillGainLine $line): bool => $line->effect === SkillEffect::InventionChance));
    }

    public function testAnExtraSlotWorksLikeTheAverageSlotOfItsCategory(): void
    {
        $this->skillRows[self::MASS_PRODUCTION] = $this->skill(self::MASS_PRODUCTION, 1);
        $this->jobs = [$this->job(self::MANUFACTURING, 120)];

        $mass = $this->lineOf(self::MASS_PRODUCTION);
        $advanced = $this->lineOf(self::ADVANCED_MASS_PRODUCTION);

        $this->assertSame([SkillEffect::ManufacturingSlot, 60.0], [$mass->effect, $mass->hoursSavedPerMonth]);
        $this->assertSame(60.0, $advanced->hoursSavedPerMonth);
        $this->assertEqualsWithDelta(60 * self::ISK_PER_HOUR, $mass->monthlyGain, 1.0);
    }

    public function testReactionSlotsUseTheReactionHours(): void
    {
        $this->jobs = [$this->job(self::REACTION, 30), $this->job(self::REACTION_FORMULA, 30), $this->job(self::MANUFACTURING, 500)];

        $line = $this->lineOf(self::MASS_REACTIONS);

        $this->assertSame([SkillEffect::ReactionSlot, 60.0], [$line->effect, $line->hoursSavedPerMonth]);
    }

    public function testScienceSlotsCountEveryScienceActivity(): void
    {
        $this->jobs = [$this->job(self::COPYING, 25), $this->job(self::INVENTION, 15), $this->job(self::MANUFACTURING, 500)];
        $this->inventionSkills(3, 3, 2);

        $line = $this->lineOf(self::LABORATORY_OPERATION);

        $this->assertSame([SkillEffect::ScienceSlot, 40.0], [$line->effect, $line->hoursSavedPerMonth]);
    }

    public function testASkillAtLevelFiveHasNoLine(): void
    {
        $this->skillRows[self::INDUSTRY] = $this->skill(self::INDUSTRY, 5);

        $this->assertNull($this->findLine(self::INDUSTRY));
    }

    public function testNoHoursToSaveIsAKnownGainOfZero(): void
    {
        $this->jobs = [];

        $line = $this->lineOf(self::INDUSTRY);

        $this->assertSame([0.0, 0.0, null], [$line->hoursSavedPerMonth, $line->monthlyGain, $line->gainUnknownReason]);
    }

    public function testEveryGainIsUnknownWhenAProjectJobIsNotCached(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100)];
        $this->jobsComplete = false;

        foreach ($this->report()->lines as $line) {
            $this->assertSame([null, null, SkillGainUnknownReason::ProjectJobsNotCached, null], [$line->hoursSavedPerMonth, $line->monthlyGain, $line->gainUnknownReason, $line->gainPerTrainingDay]);
        }
        $this->assertSame(self::ONE_DAY, $this->lineOf(self::INDUSTRY)->trainingSeconds);
    }

    public function testEveryGainIsUnknownWhenTheIskPerHourIsUnknown(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100)];
        $this->iskPerHour = null;

        $report = $this->report();

        $this->assertNull($report->iskPerHour);
        foreach ($report->lines as $line) {
            $this->assertSame([null, SkillGainUnknownReason::IskPerHourUnknown], [$line->monthlyGain, $line->gainUnknownReason]);
        }
    }

    public function testEveryGainIsUnknownWhenTheCharacterHasNoCachedSkill(): void
    {
        $this->skillRows = [];

        $lines = $this->report()->lines;

        $this->assertNotSame([], $lines);
        foreach ($lines as $line) {
            $this->assertSame([SkillGainUnknownReason::SkillsNotSynchronized, 0], [$line->gainUnknownReason, $line->currentLevel]);
        }
    }

    public function testUnsynchronizedSkillsWinOverUncachedJobsAndUnknownIskPerHour(): void
    {
        $this->skillRows = [];
        $this->jobsComplete = false;
        $this->iskPerHour = null;

        $this->assertSame(SkillGainUnknownReason::SkillsNotSynchronized, $this->report()->lines[0]->gainUnknownReason);
    }

    public function testUncachedJobsWinOverAnUnknownIskPerHour(): void
    {
        $this->jobsComplete = false;
        $this->iskPerHour = null;

        $this->assertSame(SkillGainUnknownReason::ProjectJobsNotCached, $this->report()->lines[0]->gainUnknownReason);
    }

    public function testTheReportCarriesTheIskPerHourUsedToValueTheHours(): void
    {
        $this->assertSame(self::ISK_PER_HOUR, $this->report()->iskPerHour);
    }

    public function testThePointsGivenToTheTrainingEstimatorAreThoseOfTheCachedLineAndZeroForAnAbsentOne(): void
    {
        $this->skillRows[self::INDUSTRY] = $this->skill(self::INDUSTRY, 2, 12_000);
        $this->skillRows[self::REACTIONS] = $this->skill(self::REACTIONS, 1, 777, true);

        $this->report();

        $this->assertSame([self::INDUSTRY, 2, 12_000], $this->trainingCallOf(self::INDUSTRY));
        $this->assertSame([self::REACTIONS, 1, null], $this->trainingCallOf(self::REACTIONS));
        $this->assertSame([self::ADVANCED_INDUSTRY, 0, 0], $this->trainingCallOf(self::ADVANCED_INDUSTRY));
    }

    public function testTheGainPerTrainingDayDividesTheMonthlyGainByTheTrainingDays(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100)];
        $this->trainingSecondsBySkillId[self::INDUSTRY] = 4 * self::ONE_DAY;

        $line = $this->lineOf(self::INDUSTRY);

        $this->assertSame(4 * self::ONE_DAY, $line->trainingSeconds);
        $this->assertEqualsWithDelta($line->monthlyGain / 4, $line->gainPerTrainingDay, 1.0);
    }

    public function testAnUnknownTrainingDurationKeepsTheGainAndHasNoGainPerDay(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100)];
        $this->trainingUnknownBySkillId[self::INDUSTRY] = SkillTrainingUnknownReason::AttributesNotSynchronized;

        $line = $this->lineOf(self::INDUSTRY);

        $this->assertSame([null, SkillTrainingUnknownReason::AttributesNotSynchronized, null], [$line->trainingSeconds, $line->trainingUnknownReason, $line->gainPerTrainingDay]);
        $this->assertNotNull($line->monthlyGain);
    }

    public function testLinesAreSortedByGainPerTrainingDayThenTheUnknownOnes(): void
    {
        $this->jobs = [$this->job(self::MANUFACTURING, 100), $this->job(self::REACTION, 100)];
        $this->trainingSecondsBySkillId = [self::INDUSTRY => 10 * self::ONE_DAY, self::ADVANCED_INDUSTRY => self::ONE_DAY, self::REACTIONS => 2 * self::ONE_DAY];
        $this->trainingUnknownBySkillId[self::MASS_PRODUCTION] = SkillTrainingUnknownReason::SkillNotInSde;

        $skillIds = array_map(static fn (SkillGainLine $line): int => $line->skillId, $this->report()->lines);

        $this->assertSame(
            [self::ADVANCED_INDUSTRY, self::REACTIONS, self::INDUSTRY],
            array_values(array_intersect($skillIds, [self::INDUSTRY, self::ADVANCED_INDUSTRY, self::REACTIONS])),
        );
        $this->assertSame(self::MASS_PRODUCTION, end($skillIds));
    }

    private function skill(int $skillId, int $level, ?int $points = null, bool $manual = false): CachedCharacterSkill
    {
        return (new CachedCharacterSkill())
            ->setCharacter($this->character)
            ->setSkillId($skillId)
            ->setLevel($level)
            ->setSkillPoints($points)
            ->setIsManual($manual);
    }

    private function inventionSkills(int $firstScience, int $secondScience, int $encryption): void
    {
        $this->skillRows[self::FIRST_SCIENCE] = $this->skill(self::FIRST_SCIENCE, $firstScience);
        $this->skillRows[self::SECOND_SCIENCE] = $this->skill(self::SECOND_SCIENCE, $secondScience);
        $this->skillRows[self::ENCRYPTION] = $this->skill(self::ENCRYPTION, $encryption);
    }

    private function job(int $activityId, int $hours, int $blueprintTypeId = 1): CachedIndustryJob
    {
        $start = new \DateTimeImmutable('2026-10-20T00:00:00+00:00');

        return (new CachedIndustryJob())
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setStartDate($start)
            ->setEndDate($start->modify("+{$hours} hours"));
    }

    private function lineOf(int $skillId): SkillGainLine
    {
        $line = $this->findLine($skillId);
        $this->assertNotNull($line, "no line for skill {$skillId}");

        return $line;
    }

    private function findLine(int $skillId): ?SkillGainLine
    {
        foreach ($this->report()->lines as $line) {
            if ($line->skillId === $skillId) {
                return $line;
            }
        }

        return null;
    }

    private function trainingCallOf(int $skillId): array
    {
        foreach ($this->trainingCalls as $call) {
            if ($call[0] === $skillId) {
                return $call;
            }
        }
        $this->fail("no training estimate asked for skill {$skillId}");
    }

    private function report(): SkillGainReport
    {
        $jobsLoader = $this->createStub(OwnProjectJobsLoader::class);
        $jobsLoader->method('loadFor')->willReturnCallback(fn (): OwnProjectJobs => new OwnProjectJobs($this->jobs, $this->jobsComplete));

        $revenueReader = $this->createStub(IndustryRevenueReader::class);
        $revenueReader->method('reportFor')->willReturnCallback(
            fn (): IndustryRevenueReport => new IndustryRevenueReport(new IndustryRevenueTotals(0.0, 0, 0, 0.0, null, $this->iskPerHour), [], []),
        );

        $skills = $this->createStub(CachedCharacterSkillRepository::class);
        $skills->method('findAllSkillsForCharacter')->willReturnCallback(fn (): array => $this->skillRows);

        $requirements = $this->createStub(InventionSkillRequirements::class);
        $requirements->method('of')->willReturnCallback(
            static fn (int $blueprintTypeId): InventionSkillRequirement => new InventionSkillRequirement([self::FIRST_SCIENCE, self::SECOND_SCIENCE], self::ENCRYPTION),
        );

        $training = $this->createStub(SkillTrainingEstimator::class);
        $training->method('nextLevel')->willReturnCallback(function (Character $character, int $skillId, int $level, ?int $points): SkillTrainingEstimate {
            $this->trainingCalls[] = [$skillId, $level, $points];
            if (isset($this->trainingUnknownBySkillId[$skillId])) {
                return new SkillTrainingEstimate(null, $this->trainingUnknownBySkillId[$skillId]);
            }

            return new SkillTrainingEstimate($this->trainingSecondsBySkillId[$skillId] ?? self::ONE_DAY, null);
        });

        return (new SkillGainEstimator($jobsLoader, $revenueReader, $skills, $requirements, $training))->estimate(
            $this->user,
            $this->character,
            new \DateTimeImmutable('2026-10-10'),
            new \DateTimeImmutable('2026-11-09'),
        );
    }
}
