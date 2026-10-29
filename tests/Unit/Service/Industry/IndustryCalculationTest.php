<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivity;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\SplitGroupQuantities;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EVE Online industry calculation formulas.
 *
 * EVE Material Formula: max(runs, ceil(round(base × runs × (1 - ME/100) × (1 - structureBase/100) × (1 - rigBonus/100), 2)))
 *
 * Structure base bonus and rig bonus are applied multiplicatively, not additively.
 */
class IndustryCalculationTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int CAPITAL_JUMP_DRIVE = 21025;
    private const int CAPITAL_JUMP_DRIVE_BLUEPRINT = 1_021_025;
    private const int CAPITAL_JUMP_DRIVE_BASE_TIME_PER_RUN = 16000;
    private const int CAPITAL_CARGO_BAY = 21027;
    private const int CAPITAL_CARGO_BAY_BLUEPRINT = 1_021_027;
    private const int CAPITAL_CARGO_BAY_BASE_TIME_PER_RUN = 16000;
    private const int REINFORCED_CARBON_FIBER = 57457;
    private const int REINFORCED_CARBON_FIBER_FORMULA = 1_057_457;
    private const int REINFORCED_CARBON_FIBER_BASE_TIME_PER_RUN = 10800;
    private const int RAVEN = 638;
    private const int RAVEN_BLUEPRINT = 688;
    private const int RAVEN_MANUFACTURING_BASE_TIME_PER_RUN = 18000;
    private const int RAVEN_COPYING_BASE_TIME_PER_RUN = 14400;
    private const int BLUEPRINT_WITHOUT_BASE_TIME = 999_999_999;
    private const int FIRST_SCIENCE_SKILL = 999_000_001;
    private const int SECOND_SCIENCE_SKILL = 999_000_002;
    private const array NO_SKILL = [];
    private const array INDUSTRY_FIVE = [CachedCharacterSkill::SKILL_INDUSTRY => 5];
    private const array ADVANCED_INDUSTRY_FIVE = [CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5];
    private const array INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE = [
        CachedCharacterSkill::SKILL_INDUSTRY => 5,
        CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
    ];
    private const array REACTIONS_FOUR = [CachedCharacterSkill::SKILL_REACTIONS => 4];

    private IndustryCalculationService $calculationService;

    protected function setUp(): void
    {
        $this->calculationService = new IndustryCalculationService(
            $this->createStub(\App\Service\TypeNameResolver::class),
            $this->createStub(\App\Service\Industry\IndustryBonusService::class),
            $this->createStub(\App\Repository\IndustryStructureConfigRepository::class),
            $this->createStub(\App\Repository\IndustryUserSettingsRepository::class),
            $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createStub(\App\Repository\Sde\StaStationRepository::class),
            $this->createStub(\App\Repository\CachedStructureRepository::class),
            $this->createStub(IndustryActivityRepository::class),
            $this->createStub(CachedCharacterSkillRepository::class),
        );
    }

    /**
     * Calculate material quantity using the real IndustryCalculationService.
     */
    private function calculateMaterial(int $baseQuantity, int $runs, int $me = 0, float $structureBaseBonus = 0.0, float $rigBonus = 0.0): int
    {
        return $this->calculationService->calculateMaterialQuantity($baseQuantity, $runs, $me, $structureBaseBonus, $rigBonus);
    }

    // ===========================================
    // Basic Material Calculation Tests
    // ===========================================

    public function testMaterialWithoutAnyBonus(): void
    {
        // 100 base × 26 runs × no bonus = 2600
        $result = $this->calculateMaterial(100, 26, 0, 0.0);
        $this->assertSame(2600, $result);
    }

    public function testMaterialWithME10Only(): void
    {
        // 100 base × 26 runs × 0.9 (ME10) = 2340
        $result = $this->calculateMaterial(100, 26, 10, 0.0);
        $this->assertSame(2340, $result);
    }

    public function testMaterialWithStructureBonusOnly(): void
    {
        // 100 base × 26 runs × 0.99 (1% base) × 0.958 (4.2% rig) = 2465.892 → 2466
        $result = $this->calculateMaterial(100, 26, 0, 1.0, 4.2);
        $this->assertSame(2466, $result);
    }

    public function testMaterialWithME10AndStructureBonus(): void
    {
        // 100 base × 26 runs × 0.9 (ME10) × 0.99 (1% base) × 0.958 (4.2% rig) = 2219.30 → 2220
        $result = $this->calculateMaterial(100, 26, 10, 1.0, 4.2);
        $this->assertSame(2220, $result);
    }

    // ===========================================
    // Rorqual Capital Component Tests (ME 10, base 1%, rig 4.2%)
    // Multiplicative: (1 - 0.01) × (1 - 0.042) = 0.99 × 0.958 = 0.94842
    // ===========================================

    public function testRorqualCapitalCloneVatBay(): void
    {
        // Base: 30, ME 10, structure 1% base + 4.2% rig
        // 30 × 1 × 0.9 × 0.99 × 0.958 = 25.6073... → 26
        $result = $this->calculateMaterial(30, 1, 10, 1.0, 4.2);
        $this->assertSame(26, $result);
    }

    public function testRorqualCapitalConstructionParts(): void
    {
        // Base: 40, ME 10, structure 1% base + 4.2% rig
        // 40 × 1 × 0.9 × 0.99 × 0.958 = 34.1431... → 35
        $result = $this->calculateMaterial(40, 1, 10, 1.0, 4.2);
        $this->assertSame(35, $result);
    }

    public function testRorqualCapitalCapacitorBattery(): void
    {
        // Base: 10, ME 10, structure 1% base + 4.2% rig
        // 10 × 1 × 0.9 × 0.99 × 0.958 = 8.5358... → 9
        $result = $this->calculateMaterial(10, 1, 10, 1.0, 4.2);
        $this->assertSame(9, $result);
    }

    public function testRorqualSmallComponentsMinimum(): void
    {
        // Base: 5, ME 10, structure 1% base + 4.2% rig
        // 5 × 1 × 0.9 × 0.99 × 0.958 = 4.2679... → 5 (min is runs=1)
        $result = $this->calculateMaterial(5, 1, 10, 1.0, 4.2);
        $this->assertSame(5, $result);
    }

    // ===========================================
    // Reaction Bonus Tests (refinery: 0% base, 2.64% rig = 2.4% × 1.1 nullsec)
    // ===========================================

    public function testReactionCarbonFiberInput(): void
    {
        // RCF reaction: 200 CF base per run, no ME, 0% base + 2.64% rig
        // 200 × 152 runs × 1.0 × 1.0 × 0.9736 = 29597.44 → 29598
        $result = $this->calculateMaterial(200, 152, 0, 0.0, 2.64);
        $this->assertSame(29598, $result);
    }

    public function testReactionThermosettingPolymerInput(): void
    {
        // Same as CF: 200 base × 152 runs × 0.9736 = 29598
        $result = $this->calculateMaterial(200, 152, 0, 0.0, 2.64);
        $this->assertSame(29598, $result);
    }

    public function testReactionOxyOrganicSolventsInput(): void
    {
        // 1 base × 152 runs × 0.9736 = 147.99 → 152 (min is runs)
        $result = $this->calculateMaterial(1, 152, 0, 0.0, 2.64);
        $this->assertSame(152, $result); // min(runs) applies
    }

    // ===========================================
    // Multiplicative vs Additive Bonus Tests
    // ===========================================

    public function testMultiplicativeBonusSotiyoT1NullsecGeneticSafeguardFilter(): void
    {
        // Genetic Safeguard Filter: base 75, ME10
        // Sotiyo T1 rig in nullsec: base 1%, rig 2.0% × 2.1 = 4.2%
        // OLD (wrong, additive): (1 - 5.2/100) = 0.948 → ceil(75 × 0.90 × 0.948) = ceil(63.99) = 64
        // NEW (correct, multiplicative): 0.99 × 0.958 = 0.94842 → ceil(75 × 0.90 × 0.99 × 0.958) = ceil(64.02) = 65
        $result = $this->calculateMaterial(75, 1, 10, 1.0, 4.2);
        $this->assertSame(65, $result);
    }

    // ===========================================
    // Structure Bonus Calculation Tests
    // ===========================================

    public function testEngineeringComplexNullsecTier1(): void
    {
        // EC base: 1%
        // Tier 1 rig: 2.0%
        // Nullsec multiplier: 2.1
        // Total: 1 + (2.0 × 2.1) = 5.2%
        $baseBonus = 1.0;
        $rigBonus = 2.0;
        $secMultiplier = 2.1;
        $total = $baseBonus + ($rigBonus * $secMultiplier);

        $this->assertSame(5.2, $total);
    }

    public function testEngineeringComplexNullsecTier2(): void
    {
        // EC base: 1%
        // Tier 2 rig: 2.4%
        // Nullsec: 2.1 (for manufacturing rigs)
        // Total: 1 + (2.4 × 2.1) = 6.04%
        $baseBonus = 1.0;
        $rigBonus = 2.4;
        $secMultiplier = 2.1;
        $total = $baseBonus + ($rigBonus * $secMultiplier);

        $this->assertSame(6.04, $total);
    }

    public function testRefineryNullsecTier2(): void
    {
        // Refinery: NO base material bonus (only time bonus)
        // L-Set Reactor II: 2.4%
        // Nullsec: 1.1 (reactor rigs have different multiplier!)
        // Total: 2.4 × 1.1 = 2.64%
        $baseBonus = 0.0;
        $rigBonus = 2.4;
        $secMultiplier = 1.1;
        $total = $baseBonus + ($rigBonus * $secMultiplier);

        $this->assertSame(2.64, $total);
    }

    public function testHighsecSecurityMultiplier(): void
    {
        // Highsec: 1.0
        // Tier 2 rig: 2.4%
        // Total rig bonus: 2.4 × 1.0 = 2.4%
        $rigBonus = 2.4;
        $secMultiplier = 1.0;
        $total = $rigBonus * $secMultiplier;

        $this->assertSame(2.4, $total);
    }

    public function testLowsecSecurityMultiplierManufacturing(): void
    {
        // Lowsec manufacturing: 1.9
        // Tier 2 rig: 2.4%
        // Total rig bonus: 2.4 × 1.9 = 4.56%
        $rigBonus = 2.4;
        $secMultiplier = 1.9;
        $total = $rigBonus * $secMultiplier;

        $this->assertSame(4.56, $total);
    }

    public function testLowsecSecurityMultiplierReactions(): void
    {
        // Lowsec reactions: 1.0 (same as highsec)
        // Tier 2 reactor rig: 2.4%
        // Total rig bonus: 2.4 × 1.0 = 2.4%
        $rigBonus = 2.4;
        $secMultiplier = 1.0;
        $total = $rigBonus * $secMultiplier;

        $this->assertSame(2.4, $total);
    }

    #[Group('issue-165')]
    #[DataProvider('manufacturingTimesPerRunByTeAndSkills')]
    public function testTimePerRunOfAManufacturingStepFollowsItsTeAndTheIndustrySkills(int $teLevel, ?array $skillLevels, int $expectedTimePerRun): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', $teLevel);

        self::assertSame($expectedTimePerRun, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, $skillLevels));
    }

    public static function manufacturingTimesPerRunByTeAndSkills(): iterable
    {
        yield 'TE 0 without character skills' => [0, null, 16000];
        yield 'TE 0 with no skill trained' => [0, self::NO_SKILL, 16000];
        yield 'TE 0 with Industry V' => [0, self::INDUSTRY_FIVE, 12800];
        yield 'TE 0 with Advanced Industry V' => [0, self::ADVANCED_INDUSTRY_FIVE, 13600];
        yield 'TE 0 with Industry V and Advanced Industry V' => [0, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE, 10880];
        yield 'TE 20 without character skills' => [20, null, 12800];
        yield 'TE 20 with Industry V' => [20, self::INDUSTRY_FIVE, 10240];
        yield 'TE 20 with Advanced Industry V' => [20, self::ADVANCED_INDUSTRY_FIVE, 10880];
        yield 'TE 20 with Industry V and Advanced Industry V' => [20, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE, 8704];
        yield 'TE 20 with Reactions IV only' => [20, self::REACTIONS_FOUR, 12800];
    }

    #[Group('issue-165')]
    #[DataProvider('reactionTimesPerRunByTeAndSkills')]
    public function testTimePerRunOfAReactionStepFollowsItsTeAndTheReactionsSkill(int $teLevel, ?array $skillLevels, int $expectedTimePerRun): void
    {
        $step = $this->stepOfAProject(self::REINFORCED_CARBON_FIBER, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction', $teLevel);

        self::assertSame($expectedTimePerRun, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, $skillLevels));
    }

    public static function reactionTimesPerRunByTeAndSkills(): iterable
    {
        yield 'TE 0 without character skills' => [0, null, 10800];
        yield 'TE 0 with Reactions IV' => [0, self::REACTIONS_FOUR, 9072];
        yield 'TE 20 without character skills' => [20, null, 8640];
        yield 'TE 20 with Reactions IV' => [20, self::REACTIONS_FOUR, 7258];
        yield 'TE 0 with Industry V and Advanced Industry V only' => [0, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE, 10800];
    }

    #[Group('issue-165')]
    public function testTimePerRunOfAManufacturingStepInARaitaruTakesItsStructureTimeBonus(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Raitaru')->setStructureType('raitaru')->setSecurityType('nullsec'));

        self::assertSame(7399, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-165')]
    public function testTimePerRunOfAReactionStepInATataraTakesItsStructureTimeBonus(): void
    {
        $step = $this->stepOfAProject(self::REINFORCED_CARBON_FIBER, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction', 0)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Tatara')->setStructureType('tatara')->setSecurityType('nullsec'));

        self::assertSame(6804, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, self::REACTIONS_FOUR));
    }

    #[Group('issue-165')]
    public function testScienceSkillOfTheBlueprintTakesOnePercentPerLevelOnTopOfTheIndustrySkills(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20);
        $skillLevels = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE + [self::FIRST_SCIENCE_SKILL => 3];

        $timePerRun = $this->calculationServiceOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [CachedCharacterSkill::SKILL_INDUSTRY, self::FIRST_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, $skillLevels);

        self::assertSame(8443, $timePerRun);
    }

    #[Group('issue-165')]
    public function testTwoScienceSkillsBothApply(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20);
        $skillLevels = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE + [self::FIRST_SCIENCE_SKILL => 3, self::SECOND_SCIENCE_SKILL => 2];

        $timePerRun = $this->calculationServiceOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL, self::SECOND_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, $skillLevels);

        self::assertSame(8275, $timePerRun);
    }

    #[Group('issue-165')]
    public function testScienceSkillsAreResolvedPerBlueprintAndActivityOnTheSameService(): void
    {
        $calculationService = $this->calculationServiceOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL],
            self::CAPITAL_CARGO_BAY_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::SECOND_SCIENCE_SKILL],
            self::REINFORCED_CARBON_FIBER_FORMULA.'-'.IndustryActivityType::Reaction->value => [self::SECOND_SCIENCE_SKILL],
        ]);
        $skillLevels = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE + self::REACTIONS_FOUR + [self::FIRST_SCIENCE_SKILL => 3, self::SECOND_SCIENCE_SKILL => 2];

        $manufacturingTimePerRun = $calculationService->calculateTimePerRun(
            $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20),
            $skillLevels,
        );
        $otherManufacturingTimePerRun = $calculationService->calculateTimePerRun(
            $this->stepOfAProject(self::CAPITAL_CARGO_BAY, self::CAPITAL_CARGO_BAY_BLUEPRINT, 'manufacturing', 20),
            $skillLevels,
        );
        $reactionTimePerRun = $calculationService->calculateTimePerRun(
            $this->stepOfAProject(self::REINFORCED_CARBON_FIBER, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction', 0),
            $skillLevels,
        );

        self::assertSame([8443, 8530, 8891], [$manufacturingTimePerRun, $otherManufacturingTimePerRun, $reactionTimePerRun]);
    }

    #[Group('issue-165')]
    public function testScienceSkillOfTheBlueprintNotTrainedLeavesTheTimePerRunOfTheIndustrySkills(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20);

        $timePerRun = $this->calculationServiceOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE);

        self::assertSame(8704, $timePerRun);
    }

    #[Group('issue-165')]
    public function testTimePerRunIsUnknownWhenTheSdeHasNoBaseTimeForTheBlueprint(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::BLUEPRINT_WITHOUT_BASE_TIME, 'manufacturing', 20);

        self::assertNull($this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-165')]
    public function testTimePerRunOfAReactionIsUnknownWhenTheSdeHasABaseTimeForTheManufacturingOfTheBlueprintOnly(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'reaction', 0);

        self::assertNull($this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, self::REACTIONS_FOUR));
    }

    #[Group('issue-134')]
    public function testTimePerRunIsRoundedUpToTheNextSecondEvenWhenItsFractionIsBelowOneHalf(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_CARGO_BAY, self::CAPITAL_CARGO_BAY_BLUEPRINT, 'manufacturing', 2);

        self::assertSame(10663, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-126')]
    public function testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint(): void
    {
        $copyStep = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'copy', 0);

        self::assertSame(14400, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($copyStep));
    }

    #[Group('issue-126')]
    public function testTimePerRunOfAManufacturingStepIsTheManufacturingBaseTimeEvenWhenTheBlueprintHasACopyingTime(): void
    {
        $manufacturingStep = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'manufacturing', 0);

        self::assertSame(18000, $this->calculationServiceOnSdeActivities()->calculateTimePerRun($manufacturingStep));
    }

    #[Group('issue-126')]
    public function testTimePerRunOfACopyStepIsUnknownWhenTheSdeHasNoCopyingTimeForTheBlueprint(): void
    {
        $copyStep = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'copy', 0);

        self::assertNull($this->calculationServiceOnSdeActivities()->calculateTimePerRun($copyStep));
    }

    private function calculationServiceOnSdeActivities(array $scienceSkillIdsByBlueprintAndActivity = []): IndustryCalculationService
    {
        $baseTimesByBlueprintAndActivity = [
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => self::CAPITAL_JUMP_DRIVE_BASE_TIME_PER_RUN,
            self::CAPITAL_CARGO_BAY_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => self::CAPITAL_CARGO_BAY_BASE_TIME_PER_RUN,
            self::REINFORCED_CARBON_FIBER_FORMULA.'-'.IndustryActivityType::Reaction->value => self::REINFORCED_CARBON_FIBER_BASE_TIME_PER_RUN,
            self::RAVEN_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => self::RAVEN_MANUFACTURING_BASE_TIME_PER_RUN,
            self::RAVEN_BLUEPRINT.'-'.IndustryActivityType::Copying->value => self::RAVEN_COPYING_BASE_TIME_PER_RUN,
        ];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params): string|false => isset($baseTimesByBlueprintAndActivity[$params[0].'-'.$params[1]])
                ? (string) $baseTimesByBlueprintAndActivity[$params[0].'-'.$params[1]]
                : false,
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array => array_map(
                static fn (int $skillId): array => ['skill_id' => (string) $skillId],
                $scienceSkillIdsByBlueprintAndActivity[$params[0].'-'.$params[1]] ?? [],
            ),
        );
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($baseTimesByBlueprintAndActivity): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($activityIds as $activityId) {
                        if (isset($baseTimesByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId])) {
                            $activities[$blueprintTypeId.'-'.$activityId] = (new IndustryActivity())
                                ->setTypeId($blueprintTypeId)
                                ->setActivityId($activityId)
                                ->setTime($baseTimesByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId]);
                        }
                    }
                }

                return $activities;
            },
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        return new IndustryCalculationService(
            $this->createStub(TypeNameResolver::class),
            new IndustryBonusService(
                $this->createStub(IndustryRigCategoryRepository::class),
                $this->createStub(IndustryStructureConfigRepository::class),
                $this->createStub(InvTypeRepository::class),
                $this->createStub(IndustryActivityProductRepository::class),
            ),
            $this->createStub(IndustryStructureConfigRepository::class),
            $settingsRepository,
            $entityManager,
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $activityRepository,
            $this->createStub(CachedCharacterSkillRepository::class),
        );
    }

    private function stepOfAProject(int $productTypeId, int $blueprintTypeId, string $activityType, int $teLevel): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setActivityType($activityType)
            ->setTeLevel($teLevel);
        (new IndustryProject())->setUser(new User())->addStep($step);

        return $step;
    }

    // ===========================================
    // Edge Cases
    // ===========================================

    public function testMinimumIsRuns(): void
    {
        // When calculated value is less than runs, use runs
        // 1 base × 10 runs × 0.9 × 0.99 × 0.958 = 8.536 → 9, but min is 10
        $result = $this->calculateMaterial(1, 10, 10, 1.0, 4.2);
        $this->assertSame(10, $result);
    }

    public function testRoundingPrecision(): void
    {
        // Test that rounding to 2 decimals before ceil works correctly
        // 100 × 26 × 0.9 × 0.99 × 0.958 = 2219.3028
        // round(2219.3028, 2) = 2219.30
        // ceil(2219.30) = 2220
        $result = $this->calculateMaterial(100, 26, 10, 1.0, 4.2);
        $this->assertSame(2220, $result);
    }

    public function testNoNegativeValues(): void
    {
        // ME and structure bonus should never produce negative results
        $result = $this->calculateMaterial(1, 1, 10, 5.0, 5.0);
        $this->assertGreaterThan(0, $result);
    }

    // ===========================================
    // Full Chain Test (Component → RCF → CF)
    // ===========================================

    public function testFullChainCapitalCloneVatBay(): void
    {
        // Step 1: CCVB needs RCF (ME 10, EC base 1% + rig 4.2%)
        // Base RCF: 100 per run, 26 runs
        // 100 × 26 × 0.9 × 0.99 × 0.958 = 2219.30 → 2220
        $rcfNeeded = $this->calculateMaterial(100, 26, 10, 1.0, 4.2);
        $this->assertSame(2220, $rcfNeeded);

        // Step 2: RCF runs
        $rcfRuns = SplitGroupQuantities::runsOfNeed($rcfNeeded, 200);
        $this->assertSame(12, $rcfRuns); // ceil(2220/200) = 12

        // Step 3: RCF reaction needs CF (0 ME, refinery 0% base + 2.64% rig)
        $cfNeeded = $this->calculateMaterial(200, $rcfRuns, 0, 0.0, 2.64);
        $this->assertSame(2337, $cfNeeded);

        // Step 4: CF runs
        $cfRuns = SplitGroupQuantities::runsOfNeed($cfNeeded, 200);
        $this->assertSame(12, $cfRuns); // ceil(2337/200) = 12
    }

    #[Group('issue-115')]
    public function testProjectDisplayNameIsUnknownWithoutCustomNameWhenItsProductIsMissingFromTheSde(): void
    {
        $project = (new IndustryProject())
            ->setUser(new User())
            ->setProductTypeId(self::ROBOTICS_TYPE_ID)
            ->setRuns(5);

        self::assertNull($this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock()->getProjectDisplayName($project));
    }

    #[Group('issue-115')]
    public function testProjectDisplayNameIsTheSdeNameWithoutCustomNameWhenItsProductIsKnown(): void
    {
        $project = (new IndustryProject())
            ->setUser(new User())
            ->setProductTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID)
            ->setRuns(40);

        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock()->getProjectDisplayName($project));
    }

    #[Group('issue-115')]
    public function testProjectDisplayNameIsTheCustomNameEvenWhenItsProductIsKnown(): void
    {
        $project = (new IndustryProject())
            ->setUser(new User())
            ->setProductTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID)
            ->setName('Fuel for the Keepstar')
            ->setRuns(40);

        self::assertSame('Fuel for the Keepstar', $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock()->getProjectDisplayName($project));
    }
}
