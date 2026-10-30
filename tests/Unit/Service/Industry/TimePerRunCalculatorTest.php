<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
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
use App\Service\Industry\TimePerRunCalculator;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimePerRunCalculator::class)]
class TimePerRunCalculatorTest extends TestCase
{
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
    private const int PRODUCT_OF_19000_SECONDS = 999_100_001;
    private const int BLUEPRINT_OF_19000_SECONDS = 999_200_001;
    private const int PRODUCT_OF_10000_SECONDS = 999_100_002;
    private const int BLUEPRINT_OF_10000_SECONDS = 999_200_002;
    private const int PRODUCT_OF_16003_SECONDS = 999_100_003;
    private const int BLUEPRINT_OF_16003_SECONDS = 999_200_003;
    private const int PRODUCT_OF_155_SECONDS = 999_100_004;
    private const int BLUEPRINT_OF_155_SECONDS = 999_200_004;
    private const int PRODUCT_OF_3117_SECONDS = 999_100_005;
    private const int BLUEPRINT_OF_3117_SECONDS = 999_200_005;
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

    private array $cachedSkillsByCharacterName = [];

    #[Group('issue-165')]
    #[DataProvider('manufacturingTimesPerRunByTeAndSkills')]
    public function testTimePerRunOfAManufacturingStepFollowsItsTeAndTheIndustrySkills(int $teLevel, ?array $skillLevels, int $expectedTimePerRun): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', $teLevel);

        self::assertSame($expectedTimePerRun, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, $skillLevels));
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

        self::assertSame($expectedTimePerRun, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, $skillLevels));
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

        self::assertSame(7399, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-165')]
    public function testTimePerRunOfAReactionStepInATataraTakesItsStructureTimeBonus(): void
    {
        $step = $this->stepOfAProject(self::REINFORCED_CARBON_FIBER, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction', 0)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Tatara')->setStructureType('tatara')->setSecurityType('nullsec'));

        self::assertSame(6804, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::REACTIONS_FOUR));
    }

    #[Group('issue-165')]
    public function testScienceSkillOfTheBlueprintTakesOnePercentPerLevelOnTopOfTheIndustrySkills(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20);
        $skillLevels = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE + [self::FIRST_SCIENCE_SKILL => 3];

        $timePerRun = $this->timePerRunCalculatorOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [CachedCharacterSkill::SKILL_INDUSTRY, self::FIRST_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, $skillLevels);

        self::assertSame(8443, $timePerRun);
    }

    #[Group('issue-165')]
    public function testTwoScienceSkillsBothApply(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20);
        $skillLevels = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE + [self::FIRST_SCIENCE_SKILL => 3, self::SECOND_SCIENCE_SKILL => 2];

        $timePerRun = $this->timePerRunCalculatorOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL, self::SECOND_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, $skillLevels);

        self::assertSame(8275, $timePerRun);
    }

    #[Group('issue-165')]
    public function testScienceSkillsAreResolvedPerBlueprintAndActivityOnTheSameService(): void
    {
        $timePerRunCalculator = $this->timePerRunCalculatorOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL],
            self::CAPITAL_CARGO_BAY_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::SECOND_SCIENCE_SKILL],
            self::REINFORCED_CARBON_FIBER_FORMULA.'-'.IndustryActivityType::Reaction->value => [self::SECOND_SCIENCE_SKILL],
        ]);
        $skillLevels = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE + self::REACTIONS_FOUR + [self::FIRST_SCIENCE_SKILL => 3, self::SECOND_SCIENCE_SKILL => 2];

        $manufacturingTimePerRun = $timePerRunCalculator->calculateTimePerRun(
            $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20),
            $skillLevels,
        );
        $otherManufacturingTimePerRun = $timePerRunCalculator->calculateTimePerRun(
            $this->stepOfAProject(self::CAPITAL_CARGO_BAY, self::CAPITAL_CARGO_BAY_BLUEPRINT, 'manufacturing', 20),
            $skillLevels,
        );
        $reactionTimePerRun = $timePerRunCalculator->calculateTimePerRun(
            $this->stepOfAProject(self::REINFORCED_CARBON_FIBER, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction', 0),
            $skillLevels,
        );

        self::assertSame([8443, 8530, 8891], [$manufacturingTimePerRun, $otherManufacturingTimePerRun, $reactionTimePerRun]);
    }

    #[Group('issue-165')]
    public function testScienceSkillOfTheBlueprintNotTrainedLeavesTheTimePerRunOfTheIndustrySkills(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing', 20);

        $timePerRun = $this->timePerRunCalculatorOnSdeActivities([
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE);

        self::assertSame(8704, $timePerRun);
    }

    #[Group('issue-165')]
    public function testTimePerRunIsUnknownWhenTheSdeHasNoBaseTimeForTheBlueprint(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::BLUEPRINT_WITHOUT_BASE_TIME, 'manufacturing', 20);

        self::assertNull($this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-165')]
    public function testTimePerRunOfAReactionIsUnknownWhenTheSdeHasABaseTimeForTheManufacturingOfTheBlueprintOnly(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'reaction', 0);

        self::assertNull($this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::REACTIONS_FOUR));
    }

    #[Group('issue-134')]
    public function testTimePerRunIsRoundedUpToTheNextSecondEvenWhenItsFractionIsBelowOneHalf(): void
    {
        $step = $this->stepOfAProject(self::CAPITAL_CARGO_BAY, self::CAPITAL_CARGO_BAY_BLUEPRINT, 'manufacturing', 2);

        self::assertSame(10663, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-277')]
    #[DataProvider('timesPerRunWhoseProductCarriesFloatingPointNoise')]
    public function testTimePerRunIsNotRoundedUpByTheFloatingPointNoiseOfItsProduct(int $productTypeId, int $blueprintTypeId, int $teLevel, int $expectedTimePerRun): void
    {
        $step = $this->stepOfAProject($productTypeId, $blueprintTypeId, 'manufacturing', $teLevel);

        self::assertSame($expectedTimePerRun, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    public static function timesPerRunWhoseProductCarriesFloatingPointNoise(): iterable
    {
        yield 'base time 19000 at TE 0 with Industry V and Advanced Industry V' => [self::PRODUCT_OF_19000_SECONDS, self::BLUEPRINT_OF_19000_SECONDS, 0, 12920];
        yield 'base time 10000 at TE 0 with Industry V and Advanced Industry V' => [self::PRODUCT_OF_10000_SECONDS, self::BLUEPRINT_OF_10000_SECONDS, 0, 6800];
    }

    #[Group('issue-277')]
    public function testTimePerRunWithATrueFractionOfASecondIsStillRoundedUp(): void
    {
        $step = $this->stepOfAProject(self::PRODUCT_OF_16003_SECONDS, self::BLUEPRINT_OF_16003_SECONDS, 'manufacturing', 20);

        self::assertSame(8706, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-277')]
    public function testTimePerRunWithATrueFractionOfFourMillionthsOfASecondIsRoundedUp(): void
    {
        $step = $this->stepOfAProject(self::PRODUCT_OF_155_SECONDS, self::BLUEPRINT_OF_155_SECONDS, 'manufacturing', 10);

        $timePerRun = $this->timePerRunCalculatorOnSdeActivities([
            self::BLUEPRINT_OF_155_SECONDS.'-'.IndustryActivityType::Manufacturing->value => [self::FIRST_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, [
            CachedCharacterSkill::SKILL_INDUSTRY => 2,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 1,
            self::FIRST_SCIENCE_SKILL => 2,
        ]);

        self::assertSame(123, $timePerRun);
    }

    #[Group('issue-277')]
    public function testTimePerRunWithATrueFractionBelowHalfAMillionthOfASecondIsNotRoundedUp(): void
    {
        $step = $this->stepOfAProject(self::PRODUCT_OF_3117_SECONDS, self::BLUEPRINT_OF_3117_SECONDS, 'manufacturing', 6);

        $timePerRun = $this->timePerRunCalculatorOnSdeActivities([
            self::BLUEPRINT_OF_3117_SECONDS.'-'.IndustryActivityType::Manufacturing->value => [self::SECOND_SCIENCE_SKILL],
        ])->calculateTimePerRun($step, [
            CachedCharacterSkill::SKILL_INDUSTRY => 3,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 1,
            self::SECOND_SCIENCE_SKILL => 3,
        ]);

        self::assertSame(2426, $timePerRun);
    }

    #[Group('issue-126')]
    public function testTimePerRunOfACopyStepIsTheCopyingBaseTimeOfTheBlueprint(): void
    {
        $copyStep = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'copy', 0);

        self::assertSame(14400, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($copyStep));
    }

    #[Group('issue-126')]
    public function testTimePerRunOfAManufacturingStepIsTheManufacturingBaseTimeEvenWhenTheBlueprintHasACopyingTime(): void
    {
        $manufacturingStep = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'manufacturing', 0);

        self::assertSame(18000, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($manufacturingStep));
    }

    #[Group('issue-126')]
    public function testTimePerRunOfACopyStepIsUnknownWhenTheSdeHasNoCopyingTimeForTheBlueprint(): void
    {
        $copyStep = $this->stepOfAProject(self::CAPITAL_JUMP_DRIVE, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'copy', 0);

        self::assertNull($this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($copyStep));
    }

    #[Group('issue-282')]
    public function testLoadedSkillLevelsAreUnknownForACharacterWithoutCachedSkillAndKnownForACharacterWithACachedLevelZero(): void
    {
        $user = $this->userWithCachedSkills([
            'Fresh' => [],
            'Zero' => [CachedCharacterSkill::SKILL_INDUSTRY => 0],
            'Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4],
        ]);

        self::assertSame(
            [
                ['name' => 'Fresh', 'skills' => null],
                ['name' => 'Zero', 'skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 0]],
                ['name' => 'Main', 'skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4]],
            ],
            $this->timePerRunCalculatorOnSdeActivities()->loadCharacterSkillLevels($user),
        );
    }

    #[Group('issue-282')]
    public function testLoadedSkillLevelsOfACharacterWithManualSkillsOnlyAreKnown(): void
    {
        $user = $this->userWithCachedSkills(['Manual' => [CachedCharacterSkill::SKILL_INDUSTRY => 4]], isManual: true);

        self::assertSame(
            [['name' => 'Manual', 'skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 4]]],
            $this->timePerRunCalculatorOnSdeActivities()->loadCharacterSkillLevels($user),
        );
    }

    #[Group('issue-282')]
    public function testSkillsOfAManufacturingStepAreUnknownWhenTheOnlyCharacterHasNoCachedSkill(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => []]);

        self::assertSame(
            ['skills' => null, 'name' => null, 'skillsUnknown' => true],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::RAVEN_BLUEPRINT, 'manufacturing'),
        );
    }

    #[Group('issue-282')]
    public function testCharacterWithKnownSkillsAtLevelZeroIsRetainedRatherThanAFirstCharacterWithUnknownSkills(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => [], 'Zero' => [CachedCharacterSkill::SKILL_INDUSTRY => 0]]);

        self::assertSame(
            ['skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 0], 'name' => 'Zero', 'skillsUnknown' => false],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::RAVEN_BLUEPRINT, 'manufacturing'),
        );
    }

    #[Group('issue-282')]
    public function testSkillsOfAManufacturingStepAreNotUnknownForAUserWithoutCharacter(): void
    {
        self::assertSame(
            ['skills' => null, 'name' => null, 'skillsUnknown' => false],
            $this->bestCharacterOfTheLoadedSkillLevels($this->userWithCachedSkills([]), self::CAPITAL_CARGO_BAY_BLUEPRINT, 'manufacturing'),
        );
    }

    #[Group('issue-282')]
    #[Group('issue-275')]
    public function testSkillsOfACopyStepAreUnknownWhenTheOnlyCharacterHasNoCachedSkill(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => []]);

        self::assertSame(
            ['skills' => null, 'name' => null, 'skillsUnknown' => true],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::RAVEN_BLUEPRINT, 'copy'),
        );
    }

    #[Group('issue-275')]
    public function testSkillsOfACopyStepAreUnknownWhenTheOnlyCharacterHasKnownSkillsWithoutScience(): void
    {
        $user = $this->userWithCachedSkills(['Old' => self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE]);

        self::assertSame(
            ['skills' => null, 'name' => null, 'skillsUnknown' => true],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::RAVEN_BLUEPRINT, 'copy'),
        );
    }

    #[Group('issue-275')]
    public function testSkillsOfACopyStepAreThoseOfTheCharacterWithAKnownScienceLevel(): void
    {
        $user = $this->userWithCachedSkills(['Main' => [
            CachedCharacterSkill::SKILL_SCIENCE => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]]);

        self::assertSame(
            [
                'skills' => [CachedCharacterSkill::SKILL_SCIENCE => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5],
                'name' => 'Main',
                'skillsUnknown' => false,
            ],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::RAVEN_BLUEPRINT, 'copy'),
        );
    }

    #[Group('issue-275')]
    public function testTimePerRunOfACopyStepIsUnknownForGivenSkillsWithoutScience(): void
    {
        $copyStep = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'copy', 0);

        self::assertNull($this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($copyStep, self::ADVANCED_INDUSTRY_FIVE));
    }

    #[Group('issue-275')]
    public function testTimePerRunOfACopyStepTakesScienceButNotIndustry(): void
    {
        $copyStep = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'copy', 0);

        self::assertSame(10800, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($copyStep, [
            CachedCharacterSkill::SKILL_SCIENCE => 5,
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
        ]));
    }

    #[Group('issue-282')]
    public function testSkillsOfAReactionStepAreUnknownWhenNoCharacterHasCachedSkills(): void
    {
        $user = $this->userWithCachedSkills(['Fresh1' => [], 'Fresh2' => []]);

        self::assertSame(
            ['skills' => null, 'name' => null, 'skillsUnknown' => true],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction'),
        );
    }

    #[Group('issue-282')]
    public function testTimePerRunOfAManufacturingStepWithIndustryAndAdvancedIndustryAtLevelZeroIsItsBaseTime(): void
    {
        $step = $this->stepOfAProject(self::RAVEN, self::RAVEN_BLUEPRINT, 'manufacturing', 0);

        self::assertSame(18000, $this->timePerRunCalculatorOnSdeActivities()->calculateTimePerRun($step, [
            CachedCharacterSkill::SKILL_INDUSTRY => 0,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 0,
        ]));
    }

    #[Group('issue-282')]
    public function testGivenSkillLevelsOfTheCharactersAreRetainedWithoutReloadingTheCache(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => [], 'Zero' => []]);

        $bestCharacter = $this->timePerRunCalculatorOnSdeActivities()->bestCharacterForStepTime($user, self::RAVEN_BLUEPRINT, 'manufacturing', [
            ['name' => 'Fresh', 'skills' => null],
            ['name' => 'Zero', 'skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 0]],
        ]);

        self::assertSame(['skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 0], 'name' => 'Zero', 'skillsUnknown' => false], $bestCharacter);
    }

    #[Group('issue-282')]
    public function testFirstOfTwoCharactersWithTheSameSkillTimeMultiplierIsRetained(): void
    {
        $user = $this->userWithCachedSkills([
            'Builder' => [CachedCharacterSkill::SKILL_INDUSTRY => 4],
            'Twin' => [CachedCharacterSkill::SKILL_INDUSTRY => 4, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 0],
        ]);

        self::assertSame(
            ['skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 4], 'name' => 'Builder', 'skillsUnknown' => false],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, 'manufacturing'),
        );
    }

    #[Group('issue-282')]
    public function testFastestCharacterAfterACharacterWithUnknownSkillsIsRetained(): void
    {
        $user = $this->userWithCachedSkills([
            'Zero' => [CachedCharacterSkill::SKILL_INDUSTRY => 0],
            'Fresh' => [],
            'Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4],
        ]);

        self::assertSame(
            ['skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4], 'name' => 'Main', 'skillsUnknown' => false],
            $this->bestCharacterOfTheLoadedSkillLevels($user, self::CAPITAL_CARGO_BAY_BLUEPRINT, 'manufacturing'),
        );
    }

    #[Group('issue-282')]
    public function testRetainedCharacterFollowsTheActivityOfTheStep(): void
    {
        $user = $this->userWithCachedSkills([
            'Manufacturer' => [CachedCharacterSkill::SKILL_INDUSTRY => 5],
            'Reactor' => [CachedCharacterSkill::SKILL_REACTIONS => 5],
        ]);

        self::assertSame(
            ['manufacturing' => 'Manufacturer', 'reaction' => 'Reactor'],
            [
                'manufacturing' => $this->bestCharacterOfTheLoadedSkillLevels($user, self::RAVEN_BLUEPRINT, 'manufacturing')['name'],
                'reaction' => $this->bestCharacterOfTheLoadedSkillLevels($user, self::REINFORCED_CARBON_FIBER_FORMULA, 'reaction')['name'],
            ],
        );
    }

    #[Group('issue-283')]
    public function testTimePerRunAndRecommendedCharacterAreUnknownWhenTheOnlyCharacterHasNoCachedSkill(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => []]);

        self::assertSame(
            ['timePerRun' => null, 'recommendedCharacterName' => null],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'manufacturing', 0, null),
        );
    }

    #[Group('issue-283')]
    public function testTimePerRunIsThatOfTheRecommendedCharacterWithKnownSkills(): void
    {
        $user = $this->userWithCachedSkills([
            'Fresh' => [],
            'Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4],
        ]);

        self::assertSame(
            ['timePerRun' => 12672, 'recommendedCharacterName' => 'Main'],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'manufacturing', 0, null),
        );
    }

    #[Group('issue-283')]
    #[Group('issue-275')]
    public function testCopyStepOfACharacterWithoutCachedSkillHasAnUnknownTimePerRunAndNoRecommendedCharacter(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => []]);

        self::assertSame(
            ['timePerRun' => null, 'recommendedCharacterName' => null],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'copy', 0, null),
        );
    }

    #[Group('issue-275')]
    #[DataProvider('copyTimesPerRunAndRecommendedCharactersByCachedSkillsOfTheCharacters')]
    public function testCopyStepTakesTheTimePerRunOfTheCharacterWithTheLowestSkillTimeMultiplierAmongThoseWithAKnownScienceLevel(array $skillLevelsByCharacterName, ?int $expectedTimePerRun, ?string $expectedRecommendedCharacterName): void
    {
        $user = $this->userWithCachedSkills($skillLevelsByCharacterName);

        self::assertSame(
            ['timePerRun' => $expectedTimePerRun, 'recommendedCharacterName' => $expectedRecommendedCharacterName],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'copy', 0, null),
        );
    }

    public static function copyTimesPerRunAndRecommendedCharactersByCachedSkillsOfTheCharacters(): iterable
    {
        yield 'Science at level 0: copying base time' => [
            ['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 0]],
            14400,
            'Main',
        ];
        yield 'Science V: 5 % per level' => [
            ['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 5]],
            10800,
            'Main',
        ];
        yield 'Science III' => [
            ['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 3]],
            12240,
            'Main',
        ];
        yield 'Science V and Advanced Industry V' => [
            ['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5]],
            9180,
            'Main',
        ];
        yield 'Science at level 0 and Advanced Industry V: Advanced Industry applies' => [
            ['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 0, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5]],
            12240,
            'Main',
        ];
        yield 'Science V, Industry V and Advanced Industry V: Industry does not apply' => [
            ['Main' => [
                CachedCharacterSkill::SKILL_SCIENCE => 5,
                CachedCharacterSkill::SKILL_INDUSTRY => 5,
                CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
            ]],
            9180,
            'Main',
        ];
        yield 'Science IV and Advanced Industry III: true fraction rounded up' => [
            ['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 4, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 3]],
            10484,
            'Main',
        ];
        yield 'Advanced Industry decides between Science V alone and Science IV with Advanced Industry V' => [
            [
                'Alt' => [CachedCharacterSkill::SKILL_SCIENCE => 5],
                'Main' => [CachedCharacterSkill::SKILL_SCIENCE => 4, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5],
            ],
            9792,
            'Main',
        ];
        yield 'first of two characters with the same skill time multiplier' => [
            [
                'Twin1' => [CachedCharacterSkill::SKILL_SCIENCE => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5],
                'Twin2' => [CachedCharacterSkill::SKILL_SCIENCE => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5],
            ],
            9180,
            'Twin1',
        ];
        yield 'character with known skills without Science is ignored' => [
            [
                'Old' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5],
                'New' => [CachedCharacterSkill::SKILL_SCIENCE => 3],
            ],
            12240,
            'New',
        ];
        yield 'character without cached skill is ignored' => [
            [
                'Fresh' => [],
                'Main' => [CachedCharacterSkill::SKILL_SCIENCE => 5],
            ],
            10800,
            'Main',
        ];
        yield 'only character with known skills without Science: unknown' => [
            ['Old' => [CachedCharacterSkill::SKILL_INDUSTRY => 5, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5]],
            null,
            null,
        ];
        yield 'user without character: copying base time without skill reduction' => [
            [],
            14400,
            null,
        ];
    }

    #[Group('issue-275')]
    public function testCopyStepWithoutCopyingTimeInTheSdeHasAnUnknownTimePerRunButARecommendedCharacter(): void
    {
        $user = $this->userWithCachedSkills(['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 5]]);

        self::assertSame(
            ['timePerRun' => null, 'recommendedCharacterName' => 'Main'],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::CAPITAL_JUMP_DRIVE_BLUEPRINT, self::CAPITAL_JUMP_DRIVE, 'copy', 0, null),
        );
    }

    #[Group('issue-275')]
    public function testScienceDoesNotShortenTheTimePerRunOfAManufacturingStep(): void
    {
        $user = $this->userWithCachedSkills(['Main' => [CachedCharacterSkill::SKILL_SCIENCE => 5]]);

        self::assertSame(
            ['timePerRun' => 18000, 'recommendedCharacterName' => 'Main'],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'manufacturing', 0, null),
        );
    }

    #[Group('issue-283')]
    public function testStepOfAUserWithoutCharacterHasTheTimePerRunWithoutSkillReductionAndNoRecommendedCharacter(): void
    {
        $user = $this->userWithCachedSkills([]);

        self::assertSame(
            ['timePerRun' => 18000, 'recommendedCharacterName' => null],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'manufacturing', 0, null),
        );
    }

    #[Group('issue-283')]
    public function testStepWithoutBaseTimeInTheSdeHasAnUnknownTimePerRunButARecommendedCharacter(): void
    {
        $user = $this->userWithCachedSkills(['Main' => [CachedCharacterSkill::SKILL_INDUSTRY => 5]]);

        self::assertSame(
            ['timePerRun' => null, 'recommendedCharacterName' => 'Main'],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::BLUEPRINT_WITHOUT_BASE_TIME, self::RAVEN, 'manufacturing', 0, null),
        );
    }

    #[Group('issue-283')]
    public function testGivenSkillLevelsOfTheCharactersDecideTheTimePerRunWithoutReloadingTheCache(): void
    {
        $user = $this->userWithCachedSkills(['Fresh' => []]);

        self::assertSame(
            ['timePerRun' => 14400, 'recommendedCharacterName' => 'Main'],
            $this->timePerRunCalculatorOnSdeActivities()->timePerRunAndRecommendedCharacterOf($user, self::RAVEN_BLUEPRINT, self::RAVEN, 'manufacturing', 0, null, [
                ['name' => 'Main', 'skills' => [CachedCharacterSkill::SKILL_INDUSTRY => 5]],
            ]),
        );
    }

    private function bestCharacterOfTheLoadedSkillLevels(User $user, int $blueprintTypeId, string $activityType): array
    {
        $timePerRunCalculator = $this->timePerRunCalculatorOnSdeActivities();

        return $timePerRunCalculator->bestCharacterForStepTime($user, $blueprintTypeId, $activityType, $timePerRunCalculator->loadCharacterSkillLevels($user));
    }

    private function userWithCachedSkills(array $skillLevelsByCharacterName, bool $isManual = false): User
    {
        $this->cachedSkillsByCharacterName = [];
        $user = new User();
        foreach ($skillLevelsByCharacterName as $characterName => $skillLevels) {
            $character = (new Character())->setName($characterName);
            $user->addCharacter($character);
            foreach ($skillLevels as $skillId => $level) {
                $this->cachedSkillsByCharacterName[$characterName][] = (new CachedCharacterSkill())
                    ->setCharacter($character)
                    ->setSkillId($skillId)
                    ->setLevel($level)
                    ->setIsManual($isManual);
            }
        }

        return $user;
    }

    private function timePerRunCalculatorOnSdeActivities(array $scienceSkillIdsByBlueprintAndActivity = []): TimePerRunCalculator
    {
        $baseTimesByBlueprintAndActivity = [
            self::CAPITAL_JUMP_DRIVE_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => self::CAPITAL_JUMP_DRIVE_BASE_TIME_PER_RUN,
            self::CAPITAL_CARGO_BAY_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => self::CAPITAL_CARGO_BAY_BASE_TIME_PER_RUN,
            self::REINFORCED_CARBON_FIBER_FORMULA.'-'.IndustryActivityType::Reaction->value => self::REINFORCED_CARBON_FIBER_BASE_TIME_PER_RUN,
            self::RAVEN_BLUEPRINT.'-'.IndustryActivityType::Manufacturing->value => self::RAVEN_MANUFACTURING_BASE_TIME_PER_RUN,
            self::RAVEN_BLUEPRINT.'-'.IndustryActivityType::Copying->value => self::RAVEN_COPYING_BASE_TIME_PER_RUN,
            self::BLUEPRINT_OF_19000_SECONDS.'-'.IndustryActivityType::Manufacturing->value => 19000,
            self::BLUEPRINT_OF_10000_SECONDS.'-'.IndustryActivityType::Manufacturing->value => 10000,
            self::BLUEPRINT_OF_16003_SECONDS.'-'.IndustryActivityType::Manufacturing->value => 16003,
            self::BLUEPRINT_OF_155_SECONDS.'-'.IndustryActivityType::Manufacturing->value => 155,
            self::BLUEPRINT_OF_3117_SECONDS.'-'.IndustryActivityType::Manufacturing->value => 3117,
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

        return new TimePerRunCalculator(
            new IndustryCalculationService(
                $this->createStub(TypeNameResolver::class),
                new IndustryBonusService(
                    $this->createStub(IndustryRigCategoryRepository::class),
                    $this->createStub(IndustryStructureConfigRepository::class),
                    $this->createStub(InvTypeRepository::class),
                    $this->createStub(IndustryActivityProductRepository::class),
                ),
                $this->createStub(IndustryStructureConfigRepository::class),
                $settingsRepository,
                $this->createStub(StaStationRepository::class),
                $this->createStub(CachedStructureRepository::class),
            ),
            $activityRepository,
            $entityManager,
            $this->skillRepositoryOfTheCachedSkills(),
        );
    }

    private function skillRepositoryOfTheCachedSkills(): CachedCharacterSkillRepository
    {
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            fn (Character $character): array => $this->cachedSkillsByCharacterName[$character->getName()] ?? [],
        );

        return $skillRepository;
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
}
