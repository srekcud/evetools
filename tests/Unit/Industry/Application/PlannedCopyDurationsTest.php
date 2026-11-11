<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedCopyDuration;
use App\Industry\Application\PlannedCopyDurations;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureType;
use App\Industry\Application\TimeRig;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\Runs;
use App\Industry\Domain\ScienceRig;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\InMemoryCharacterSkillLevels;
use App\Tests\Unit\Industry\Application\Double\InMemoryResearchBaseTimes;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlannedCopyDurations::class)]
#[CoversClass(PlannedCopyDuration::class)]
#[Group('issue-524')]
final class PlannedCopyDurationsTest extends TestCase
{
    private const string OWNER_ID = '01970000-0000-7000-8000-000000000524';
    private const string SOTIYO_ID = '01970000-0000-7000-8000-0000000005a1';
    private const string RAITARU_ID = '01970000-0000-7000-8000-0000000005a2';
    private const string SOTIYO_WITH_SHIP_RIG_ID = '01970000-0000-7000-8000-0000000005a3';
    private const string DELETED_STRUCTURE_ID = '01970000-0000-7000-8000-0000000005a9';
    private const int OSTINGELE_SYSTEM_ID = 30_002_187;

    private const int SCIENCE = 3402;
    private const int ADVANCED_INDUSTRY = 3388;

    private const int HORNET = 2454;
    private const int RAVEN = 638;
    private const int HORNET_COPY_BASE_TIME_SECONDS = 240;
    private const int RAVEN_COPY_BASE_TIME_SECONDS = 14_400;

    private const int HORNET_COPY_RUNS = 600;
    private const int HORNET_MERGED_COPY_RUNS = 1_200;
    private const int HORNET_INVENTION_ATTEMPTS = 12;
    private const int ONE_RUN = 1;

    private const int AURELIE_ID = 2_120_000_101;
    private const int BASTIEN_ID = 2_120_000_102;
    private const int CYRA_ID = 2_120_000_103;

    private const float SOTIYO_TIME_ROLE_BONUS = 0.70;
    private const float RAITARU_TIME_ROLE_BONUS = 0.85;
    private const float RIG_II_TIME_REDUCTION_PERCENT = 24.0;
    private const float XL_SHIP_MATERIAL_REDUCTION_PERCENT = 2.4;

    private array $structures = [];

    private array $skillLevels = [];

    protected function setUp(): void
    {
        $this->structures = [
            $this->sotiyoWithXlLaboratoryOptimizationII(),
            $this->raitaruWithoutRig(),
            $this->sotiyoWithXlShipManufacturingEfficiencyII(),
        ];
    }

    public function testHornetCopyMeasuredInGameOfSixHundredRunsInANullsecSotiyoWithAnXlLaboratoryOptimizationIiByAPilotAtScienceVAndAdvancedIndustryVTakes31873Seconds(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);

        $duration = $this->durationOf(self::HORNET, self::HORNET_COPY_RUNS, self::SOTIYO_ID, StructureChoiceStatus::BestInFavoriteSystem, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame(['seconds' => 31_873, 'missingData' => [], 'characterName' => 'Aurelie Vanth'], self::shapeOf($duration));
    }

    public function testRavenCopyInARaitaruWithoutRigByAPilotWhoseScienceAndAdvancedIndustryAreKnownAtZeroTakesTheRoleBonusAlone(): void
    {
        $this->givenSkills(self::BASTIEN_ID, 0, 0);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::BASTIEN_ID => 'Bastien Orr']);

        self::assertSame(['seconds' => 12_240, 'missingData' => [], 'characterName' => 'Bastien Orr'], self::shapeOf($duration));
    }

    public function testRavenCopyInARaitaruByAPilotAtScienceIvAndAdvancedIndustryIiiIsRoundedOnceTo8911Seconds(): void
    {
        $this->givenSkills(self::BASTIEN_ID, 4, 3);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::BASTIEN_ID => 'Bastien Orr']);

        self::assertSame(['seconds' => 8_911, 'missingData' => [], 'characterName' => 'Bastien Orr'], self::shapeOf($duration));
    }

    public function testFastestCopierOfTheOwnerIsRetainedAndNamed(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 3);
        $this->givenSkills(self::BASTIEN_ID, 4, 5);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::AURELIE_ID => 'Aurelie Vanth', self::BASTIEN_ID => 'Bastien Orr']);

        self::assertSame(['seconds' => 8_323, 'missingData' => [], 'characterName' => 'Bastien Orr'], self::shapeOf($duration));
    }

    public function testCharacterWithoutAKnownAdvancedIndustryLevelIsLeftOutForAKnownUnskilledOne(): void
    {
        $this->skillLevels[self::AURELIE_ID] = [self::SCIENCE => 5];
        $this->givenSkills(self::BASTIEN_ID, 0, 0);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::AURELIE_ID => 'Aurelie Vanth', self::BASTIEN_ID => 'Bastien Orr']);

        self::assertSame(['seconds' => 12_240, 'missingData' => [], 'characterName' => 'Bastien Orr'], self::shapeOf($duration));
    }

    public function testCopiersOfTheSameSpeedAreSeparatedByName(): void
    {
        $this->givenSkills(self::CYRA_ID, 3, 2);
        $this->givenSkills(self::AURELIE_ID, 3, 2);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::CYRA_ID => 'Cyra Lindqvist', self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame('Aurelie Vanth', $duration->characterName);
    }

    public function testCopyPlannedWithoutAStructureAbleToCopyIsUnknownAndNeverTimedInANpcStation(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, null, StructureChoiceStatus::NotConfigured, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame([null, [['no_copy_structure', null]]], [$duration->seconds, self::reasonsOf($duration)]);
    }

    public function testCopyForInventionOfTwelveAttemptsTakesOneRunPerAttempt(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);

        $duration = $this->durationOf(self::HORNET, self::HORNET_INVENTION_ATTEMPTS, self::SOTIYO_ID, StructureChoiceStatus::BestInFavoriteSystem, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame(['seconds' => 637, 'missingData' => [], 'characterName' => 'Aurelie Vanth'], self::shapeOf($duration));
    }

    public function testMergedCopyLineOfTwoJobsOfSixHundredRunsIsOneSequentialDuration(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);

        $duration = $this->durationOf(self::HORNET, self::HORNET_MERGED_COPY_RUNS, self::SOTIYO_ID, StructureChoiceStatus::BestInFavoriteSystem, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame(['seconds' => 63_746, 'missingData' => [], 'characterName' => 'Aurelie Vanth'], self::shapeOf($duration));
    }

    public function testManufacturingRigOfTheStructureDoesNotShortenTheCopy(): void
    {
        $this->givenSkills(self::BASTIEN_ID, 0, 0);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::SOTIYO_WITH_SHIP_RIG_ID, StructureChoiceStatus::BestConfigured, [self::BASTIEN_ID => 'Bastien Orr']);

        self::assertSame(['seconds' => 10_080, 'missingData' => [], 'characterName' => 'Bastien Orr'], self::shapeOf($duration));
    }

    public function testCopyPlannedInAStructureTheOwnerNoLongerHasIsUnknown(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::DELETED_STRUCTURE_ID, StructureChoiceStatus::BestConfigured, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame([null, [['planned_structure_deleted', null]]], [$duration->seconds, self::reasonsOf($duration)]);
    }

    public function testOwnerWithoutAnyCharacterWhoseCopySkillsAreKnownHasAnUnknownDurationNeverADefaultLevel(): void
    {
        $this->skillLevels[self::AURELIE_ID] = [self::ADVANCED_INDUSTRY => 4];

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::AURELIE_ID => 'Aurelie Vanth', self::BASTIEN_ID => 'Bastien Orr']);

        self::assertSame([null, [['copy_skills', null]]], [$duration->seconds, self::reasonsOf($duration)]);
    }

    public function testCopyLineOfABomBuiltBeforeTheCopyStructureWasPlannedIsUnknown(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);

        $duration = $this->durationOf(self::RAVEN, self::ONE_RUN, null, null, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame([null, [['copy_structure_not_planned', null]]], [$duration->seconds, self::reasonsOf($duration)]);
    }

    public function testCopyWhoseBaseTimeIsMissingFromTheSdeIsUnknownAndNeverZero(): void
    {
        $this->givenSkills(self::AURELIE_ID, 5, 5);
        $productWithoutCopyTime = 990_524_001;

        $duration = $this->durationOf($productWithoutCopyTime, self::ONE_RUN, self::RAITARU_ID, StructureChoiceStatus::BestConfigured, [self::AURELIE_ID => 'Aurelie Vanth']);

        self::assertSame([null, [['copy_base_time', $productWithoutCopyTime]]], [$duration->seconds, self::reasonsOf($duration)]);
    }

    private function durationOf(int $productTypeId, int $runs, ?string $plannedStructureId, ?StructureChoiceStatus $plannedStructureStatus, array $characterNamesByEveCharacterId): PlannedCopyDuration
    {
        $durations = new PlannedCopyDurations(
            new InMemoryResearchBaseTimes([
                self::HORNET => [ActivityKind::Copying->name => self::HORNET_COPY_BASE_TIME_SECONDS],
                self::RAVEN => [ActivityKind::Copying->name => self::RAVEN_COPY_BASE_TIME_SECONDS],
            ]),
            new InMemoryCharacterSkillLevels($this->skillLevels),
            new InMemoryUserProductionStructures([self::OWNER_ID => $this->structures]),
        );

        return $durations->of(self::OWNER_ID, $characterNamesByEveCharacterId, $productTypeId, new Runs($runs), $plannedStructureId, $plannedStructureStatus);
    }

    private function givenSkills(int $eveCharacterId, int $scienceLevel, int $advancedIndustryLevel): void
    {
        $this->skillLevels[$eveCharacterId] = [self::SCIENCE => $scienceLevel, self::ADVANCED_INDUSTRY => $advancedIndustryLevel];
    }

    private static function shapeOf(PlannedCopyDuration $duration): array
    {
        return ['seconds' => $duration->seconds, 'missingData' => self::reasonsOf($duration), 'characterName' => $duration->characterName];
    }

    private static function reasonsOf(PlannedCopyDuration $duration): array
    {
        return array_map(static fn (MissingData $missingData): array => [$missingData->reason, $missingData->typeId], $duration->missingData);
    }

    private function sotiyoWithXlLaboratoryOptimizationII(): ProductionStructure
    {
        return new ProductionStructure(
            self::SOTIYO_ID,
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            self::OSTINGELE_SYSTEM_ID,
            new \DateTimeImmutable('2026-03-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            [],
            new Multiplier(ProductionStructures::SOTIYO_COST_ROLE_BONUS),
            ProductionStructures::NO_FACILITY_TAX,
            timeRoleBonus: new Multiplier(self::SOTIYO_TIME_ROLE_BONUS),
            scienceRigs: array_map(
                static fn (ActivityKind $activity): ScienceRig => new ScienceRig($activity, self::rigBonusOf(self::RIG_II_TIME_REDUCTION_PERCENT), null),
                [ActivityKind::Copying, ActivityKind::Invention, ActivityKind::ResearchMaterial, ActivityKind::ResearchTime],
            ),
        );
    }

    private function raitaruWithoutRig(): ProductionStructure
    {
        return new ProductionStructure(
            self::RAITARU_ID,
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            self::OSTINGELE_SYSTEM_ID,
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            [],
            new Multiplier(ProductionStructures::RAITARU_COST_ROLE_BONUS),
            ProductionStructures::NO_FACILITY_TAX,
            timeRoleBonus: new Multiplier(self::RAITARU_TIME_ROLE_BONUS),
        );
    }

    private function sotiyoWithXlShipManufacturingEfficiencyII(): ProductionStructure
    {
        return new ProductionStructure(
            self::SOTIYO_WITH_SHIP_RIG_ID,
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            self::OSTINGELE_SYSTEM_ID,
            new \DateTimeImmutable('2026-04-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            [new StructureRig(self::rigBonusOf(self::XL_SHIP_MATERIAL_REDUCTION_PERCENT), ['Ship'])],
            new Multiplier(ProductionStructures::SOTIYO_COST_ROLE_BONUS),
            ProductionStructures::NO_FACILITY_TAX,
            timeRoleBonus: new Multiplier(self::SOTIYO_TIME_ROLE_BONUS),
            timeRigs: [new TimeRig(self::rigBonusOf(self::RIG_II_TIME_REDUCTION_PERCENT), ['Ship'])],
        );
    }

    private static function rigBonusOf(float $reductionPercent): RigBonus
    {
        return new RigBonus($reductionPercent, new RigSecurityModifiers(1.0, 1.9, 2.1));
    }
}
