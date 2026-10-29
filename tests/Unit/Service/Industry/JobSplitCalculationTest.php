<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryProjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectFactory::class)]
#[Group('issue-171')]
final class JobSplitCalculationTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const int CAPITAL_JUMP_DRIVE = 21025;
    private const int CAPITAL_JUMP_DRIVE_RUNS = 27;
    private const int CAPITAL_JUMP_DRIVE_ME = 10;
    private const int REINFORCED_CARBON_FIBER = 57457;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;
    private const float ONE_DAY = 1.0;
    private const float THREE_QUARTERS_OF_A_DAY = 0.75;
    private const float DURATION_OF_TWENTY_SEVEN_RUNS_AT_TWELVE_THOUSAND_EIGHT_HUNDRED_SECONDS_IN_DAYS = 4.0;
    private const float MAX_JOB_DURATION_SHORTER_THAN_ONE_RUN_DAYS = 0.1;
    private const array NO_SKILL = [];
    private const array INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE = [
        CachedCharacterSkill::SKILL_INDUSTRY => 5,
        CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
    ];
    private const array REACTIONS_FOUR = [CachedCharacterSkill::SKILL_REACTIONS => 4];

    private User $pilot;

    private array $skillLevelsOfThePilot = self::NO_SKILL;

    private bool $pilotOwnsTataraAndRaitaru = false;

    private IndustryProjectFactory $factory;

    protected function setUp(): void
    {
        $this->pilot = (new User())->addCharacter((new Character())->setName('Industrialist'));

        $bonusService = new TataraAndRaitaruBonusService(
            fn (): bool => $this->pilotOwnsTataraAndRaitaru,
            static fn (int $typeId): bool => (self::goldenRecipes()[$typeId]['activity'] ?? null) === 'reaction',
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
        $skillRepository = $this->skillRepositoryOfThePilot();
        $calculationService = $this->calculationServiceOnGoldenSde($bonusService, $this->createStub(IndustryStructureConfigRepository::class), $skillRepository);
        $this->factory = $this->projectFactoryOnGoldenSde($bonusService, $calculationService, skillRepository: $skillRepository);
    }

    public function testCapitalJumpDriveOfAPilotWithIndustryFiveAndAdvancedIndustryFiveIsSplitIntoFourteenAndThirteenRuns(): void
    {
        $this->skillLevelsOfThePilot = self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE;

        $project = $this->createCapitalJumpDriveProject(teLevel: 20, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([[14, 14, 0, 27], [13, 13, 1, 27]], $this->jobsOf($project, self::CAPITAL_JUMP_DRIVE));
        self::assertCount(1, array_unique(array_map(
            static fn (IndustryProjectStep $job): ?string => $job->getSplitGroupId(),
            $this->stepsOfProduct($project, self::CAPITAL_JUMP_DRIVE),
        )));
        self::assertNotNull($this->stepsOfProduct($project, self::CAPITAL_JUMP_DRIVE)[0]->getSplitGroupId());
    }

    public function testCapitalJumpDriveAtTeTwentyWithoutSkillsIsSplitIntoThreeJobsOfNineRuns(): void
    {
        $project = $this->createCapitalJumpDriveProject(teLevel: 20, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([[9, 9, 0, 27], [9, 9, 1, 27], [9, 9, 2, 27]], $this->jobsOf($project, self::CAPITAL_JUMP_DRIVE));
    }

    #[Group('issue-165')]
    #[DataProvider('capitalJumpDriveSplitsAtOneDayByTeAndSkills')]
    public function testTeAndSkillsOfThePilotSetTheTimePerRunThatSplitsACapitalJumpDriveInJobsOfOneDay(int $teLevel, array $skillLevels, array $expectedRunsOfEachJob): void
    {
        $this->skillLevelsOfThePilot = $skillLevels;

        $project = $this->createCapitalJumpDriveProject(teLevel: $teLevel, maxJobDurationDays: self::ONE_DAY);

        self::assertSame($expectedRunsOfEachJob, $this->runsOfEachJobOf($project, self::CAPITAL_JUMP_DRIVE));
    }

    public static function capitalJumpDriveSplitsAtOneDayByTeAndSkills(): iterable
    {
        yield 'TE 0 without skills: 16000 s per run, 5 runs per job' => [0, self::NO_SKILL, [5, 5, 5, 4, 4, 4]];
        yield 'TE 20 without skills: 12800 s per run, 6 runs per job' => [20, self::NO_SKILL, [6, 6, 5, 5, 5]];
        yield 'TE 0 with Industry V and Advanced Industry V: 10880 s per run, 7 runs per job' => [0, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE, [7, 7, 7, 6]];
        yield 'TE 20 with Industry V and Advanced Industry V: 8704 s per run, 9 runs per job' => [20, self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE, [9, 9, 9]];
        yield 'TE 20 with Reactions IV only: 12800 s per run, 6 runs per job' => [20, self::REACTIONS_FOUR, [6, 6, 5, 5, 5]];
    }

    public function testCapitalJumpDriveExactlyAsLongAsTheMaximumDurationIsNotSplit(): void
    {
        $project = $this->createCapitalJumpDriveProject(
            teLevel: 20,
            maxJobDurationDays: self::DURATION_OF_TWENTY_SEVEN_RUNS_AT_TWELVE_THOUSAND_EIGHT_HUNDRED_SECONDS_IN_DAYS,
        );

        self::assertSame([[27, 27, 0, null]], $this->jobsOf($project, self::CAPITAL_JUMP_DRIVE));
        self::assertNull($this->stepsOfProduct($project, self::CAPITAL_JUMP_DRIVE)[0]->getSplitGroupId());
    }

    public function testOneRunMoreThanTheMaximumDurationSplitsTheCapitalJumpDriveIntoTwoJobs(): void
    {
        $project = $this->createCapitalJumpDriveProject(
            teLevel: 20,
            maxJobDurationDays: self::DURATION_OF_TWENTY_SEVEN_RUNS_AT_TWELVE_THOUSAND_EIGHT_HUNDRED_SECONDS_IN_DAYS,
            runs: 28,
        );

        self::assertSame([[14, 14, 0, 28], [14, 14, 1, 28]], $this->jobsOf($project, self::CAPITAL_JUMP_DRIVE));
    }

    public function testMaximumDurationShorterThanOneRunGivesOneJobPerRun(): void
    {
        $project = $this->createCapitalJumpDriveProject(teLevel: 20, maxJobDurationDays: self::MAX_JOB_DURATION_SHORTER_THAN_ONE_RUN_DAYS, runs: 3);

        self::assertSame([[1, 1, 0, 3], [1, 1, 1, 3], [1, 1, 2, 3]], $this->jobsOf($project, self::CAPITAL_JUMP_DRIVE));
    }

    public function testOneRunLongerThanTheMaximumDurationStaysOneUnsplitJob(): void
    {
        $project = $this->createCapitalJumpDriveProject(teLevel: 20, maxJobDurationDays: self::MAX_JOB_DURATION_SHORTER_THAN_ONE_RUN_DAYS, runs: 1);

        self::assertSame([[1, 1, 0, null]], $this->jobsOf($project, self::CAPITAL_JUMP_DRIVE));
        self::assertNull($this->stepsOfProduct($project, self::CAPITAL_JUMP_DRIVE)[0]->getSplitGroupId());
    }

    #[Group('issue-165')]
    #[DataProvider('reinforcedCarbonFiberOfACapitalJumpDriveSplitsBySkills')]
    public function testReactionsSkillOfThePilotSetsTheTimePerRunThatSplitsTheReinforcedCarbonFiberOfACapitalJumpDrive(array $skillLevels, array $expectedJobs): void
    {
        $this->skillLevelsOfThePilot = $skillLevels;

        $project = $this->createCapitalJumpDriveProject(teLevel: 20, maxJobDurationDays: self::THREE_QUARTERS_OF_A_DAY);

        self::assertSame($expectedJobs, $this->jobsOf($project, self::REINFORCED_CARBON_FIBER));
    }

    public static function reinforcedCarbonFiberOfACapitalJumpDriveSplitsBySkills(): iterable
    {
        yield 'without skills: 10800 s per run, 6 runs per job' => [self::NO_SKILL, [[5, 1000, 0, 13], [4, 800, 1, 13], [4, 630, 2, 13]]];
        yield 'with Reactions IV: 9072 s per run, 7 runs per job' => [self::REACTIONS_FOUR, [[7, 1400, 0, 13], [6, 1030, 1, 13]]];
        yield 'with Industry V and Advanced Industry V only: 10800 s per run, 6 runs per job' => [self::INDUSTRY_FIVE_AND_ADVANCED_INDUSTRY_FIVE, [[5, 1000, 0, 13], [4, 800, 1, 13], [4, 630, 2, 13]]];
    }

    public function testTimeBonusOfATataraShortensTheReactionJobsOfTheSplit(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createCapitalJumpDriveProject(teLevel: 20, maxJobDurationDays: self::THREE_QUARTERS_OF_A_DAY);

        self::assertSame([7, 6], $this->runsOfEachJobOf($project, self::REINFORCED_CARBON_FIBER));
    }

    private function createCapitalJumpDriveProject(int $teLevel, float $maxJobDurationDays, int $runs = self::CAPITAL_JUMP_DRIVE_RUNS): IndustryProject
    {
        return $this->factory->createProject($this->pilot, self::CAPITAL_JUMP_DRIVE, $runs, self::CAPITAL_JUMP_DRIVE_ME, $maxJobDurationDays, $teLevel);
    }

    private function skillRepositoryOfThePilot(): CachedCharacterSkillRepository
    {
        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            fn (): array => array_map(
                static fn (int $skillId, int $level): CachedCharacterSkill => (new CachedCharacterSkill())->setSkillId($skillId)->setLevel($level),
                array_keys($this->skillLevelsOfThePilot),
                array_values($this->skillLevelsOfThePilot),
            ),
        );

        return $skillRepository;
    }

    private function stepsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        return array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId && $step->getActivityType() !== 'copy',
        ));
    }

    private function jobsOf(IndustryProject $project, int $productTypeId): array
    {
        return array_map(
            static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity(), $job->getSplitIndex(), $job->getTotalGroupRuns()],
            $this->stepsOfProduct($project, $productTypeId),
        );
    }

    private function runsOfEachJobOf(IndustryProject $project, int $productTypeId): array
    {
        return array_map(static fn (IndustryProjectStep $job): int => $job->getRuns(), $this->stepsOfProduct($project, $productTypeId));
    }
}
