<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Industry\Domain\LineRentalCategory;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\Project\LineRentalCategoryResolver;
use App\Service\Industry\Project\LineRentalSuggester;
use App\Service\Industry\Project\LineRentalUnknownReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineRentalSuggester::class)]
#[Group('issue-300')]
final class LineRentalSuggesterTest extends TestCase
{
    private const int PULSE_LASER_II_TYPE_ID = 3057;
    private const int RIFTER_TYPE_ID = 587;
    private const int HOBGOBLIN_II_TYPE_ID = 2454;
    private const int CONTRIBUTOR_CHARACTER_ID = 2120000001;
    private const int CONTRIBUTOR_ALT_CHARACTER_ID = 2120000002;
    private const int OTHER_MEMBER_CHARACTER_ID = 2120000003;
    private const int MOCKUP_JOB_SECONDS = 16_620;
    private const int SECONDS_PER_DAY = 86_400;
    private const string JOB_START = '2026-11-02 08:00:00';

    private LineRentalCategoryResolver&Stub $categoryResolver;
    private CachedIndustryJobRepository&Stub $jobRepository;
    private LineRentalSuggester $suggester;

    private User $contributor;
    private User $otherMember;
    private Character $contributorMain;
    private Character $contributorAlt;
    private Character $otherMemberMain;
    private IndustryProject $project;
    private IndustryProjectBomLine $line;

    private array $cachedJobs = [];

    protected function setUp(): void
    {
        $this->categoryResolver = $this->createStub(LineRentalCategoryResolver::class);
        $this->categoryResolver->method('categoryOf')->willReturnMap([
            [self::PULSE_LASER_II_TYPE_ID, LineRentalCategory::ModuleT2],
            [self::RIFTER_TYPE_ID, LineRentalCategory::ShipT1],
            [self::HOBGOBLIN_II_TYPE_ID, null],
        ]);

        $this->jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $this->jobRepository->method('findByJobIds')->willReturnCallback(
            fn (array $jobIds): array => array_intersect_key($this->cachedJobs, array_flip($jobIds)),
        );
        $this->jobRepository->method('findByJobId')->willReturnCallback(
            fn (int $jobId): ?CachedIndustryJob => $this->cachedJobs[$jobId] ?? null,
        );

        $this->suggester = new LineRentalSuggester($this->categoryResolver, $this->jobRepository);

        $this->contributor = new User();
        $this->contributorMain = $this->character(self::CONTRIBUTOR_CHARACTER_ID, 'C-Main');
        $this->contributorAlt = $this->character(self::CONTRIBUTOR_ALT_CHARACTER_ID, 'C-Alt');
        $this->contributor->addCharacter($this->contributorMain)->addCharacter($this->contributorAlt);

        $this->otherMember = new User();
        $this->otherMemberMain = $this->character(self::OTHER_MEMBER_CHARACTER_ID, 'M-Main');
        $this->otherMember->addCharacter($this->otherMemberMain);

        $this->project = new IndustryProject();
        $this->line = $this->lineOf(self::PULSE_LASER_II_TYPE_ID, 'Pulse Laser II');
    }

    public function testMockupLineOfFiveJobsOfFourHoursThirtySevenMinutesOverFiftyRunsIsSuggestedAtTheMockupValue(): void
    {
        foreach ([7001, 7002, 7003, 7004, 7005] as $esiJobId) {
            $this->linkJob($esiJobId, $this->contributorMain, self::MOCKUP_JOB_SECONDS, 10);
        }

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(5_961_805.56, $suggestion->suggestedValue);
        self::assertNull($suggestion->unknownReason);
        self::assertSame(LineRentalCategory::ModuleT2, $suggestion->category);
        self::assertEqualsWithDelta(83_100 / 86_400, $suggestion->days, 1e-9);
        self::assertSame(50, $suggestion->runs);
        self::assertSame(1_000_000.0, $suggestion->dayFactor);
        self::assertSame(100_000.0, $suggestion->ratePerRun);
    }

    public function testJobsOfTheContributorAltsAreSummedWithThoseOfHisMain(): void
    {
        $this->linkJob(7101, $this->contributorMain, 43_200, 4);
        $this->linkJob(7102, $this->contributorAlt, 43_200, 6);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(2_000_000.0, $suggestion->suggestedValue);
        self::assertSame(1.0, $suggestion->days);
        self::assertSame(10, $suggestion->runs);
    }

    public function testRunsAreThoseOfTheLinksNotThoseOfTheCachedJobs(): void
    {
        $this->linkJob(7201, $this->contributorMain, self::SECONDS_PER_DAY, 10, cachedJobRuns: 12);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(10, $suggestion->runs);
        self::assertSame(2_000_000.0, $suggestion->suggestedValue);
    }

    public function testProjectOverrideRatesApplyBeforeTheRatesOfTheContributor(): void
    {
        $this->project->setLineRentalRatesOverride(['module_t2' => 200_000]);
        $this->contributor->setLineRentalRates(['module_t2' => 150_000]);
        $this->linkJob(7301, $this->contributorMain, self::SECONDS_PER_DAY, 10);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(3_000_000.0, $suggestion->suggestedValue);
        self::assertSame(200_000.0, $suggestion->ratePerRun);
    }

    public function testRatesOfTheContributorApplyWhenTheProjectHasNoOverride(): void
    {
        $this->line = $this->lineOf(self::RIFTER_TYPE_ID, 'Rifter');
        $this->contributor->setLineRentalRates(['ship_t1' => 120_000]);
        $this->linkJob(7401, $this->contributorMain, 43_200, 3);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(860_000.0, $suggestion->suggestedValue);
        self::assertSame(LineRentalCategory::ShipT1, $suggestion->category);
        self::assertSame(0.5, $suggestion->days);
    }

    public function testEmptyRatesOfTheContributorFallBackToTheDefaultSchedule(): void
    {
        $this->line = $this->lineOf(self::RIFTER_TYPE_ID, 'Rifter');
        $this->contributor->setLineRentalRates([]);
        $this->linkJob(7501, $this->contributorMain, self::SECONDS_PER_DAY, 2);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(1_200_000.0, $suggestion->suggestedValue);
        self::assertSame(100_000.0, $suggestion->ratePerRun);
    }

    public function testJobsOfAnotherMemberOnTheSameLineAreIgnored(): void
    {
        $this->linkJob(7601, $this->contributorMain, self::MOCKUP_JOB_SECONDS, 10);
        $this->linkJob(7602, $this->otherMemberMain, self::SECONDS_PER_DAY, 30);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertSame(1_192_361.11, $suggestion->suggestedValue);
        self::assertSame(10, $suggestion->runs);
        self::assertEqualsWithDelta(16_620 / 86_400, $suggestion->days, 1e-9);
    }

    public function testLineWithoutAnyLinkedJobIsUnknownWithNoLinkedJob(): void
    {
        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertNull($suggestion->suggestedValue);
        self::assertSame(LineRentalUnknownReason::NoLinkedJob, $suggestion->unknownReason);
    }

    public function testLineLinkedOnlyToJobsOfAnotherMemberIsUnknownWithNoLinkedJob(): void
    {
        $this->linkJob(7701, $this->otherMemberMain, self::SECONDS_PER_DAY, 30);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertNull($suggestion->suggestedValue);
        self::assertSame(LineRentalUnknownReason::NoLinkedJob, $suggestion->unknownReason);
    }

    public function testSingleLinkedJobAbsentFromTheCacheMakesTheWholeSuggestionUnknown(): void
    {
        $this->linkJob(7801, $this->contributorMain, self::MOCKUP_JOB_SECONDS, 10);
        $this->linkJob(7802, $this->contributorMain, self::MOCKUP_JOB_SECONDS, 10);
        $this->linkUncachedJob(7803, 10);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertNull($suggestion->suggestedValue);
        self::assertSame(LineRentalUnknownReason::JobDataMissing, $suggestion->unknownReason);
    }

    public function testLineWithAnUnknownCategoryHasNoValueNeverZero(): void
    {
        $this->line = $this->lineOf(self::HOBGOBLIN_II_TYPE_ID, 'Hobgoblin II');
        $this->linkJob(7901, $this->contributorMain, self::SECONDS_PER_DAY, 10);

        $suggestion = $this->suggester->suggest($this->contributor, $this->line);

        self::assertNull($suggestion->suggestedValue);
        self::assertSame(LineRentalUnknownReason::UnknownCategory, $suggestion->unknownReason);
        self::assertNull($suggestion->category);
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
    }

    private function lineOf(int $typeId, string $typeName): IndustryProjectBomLine
    {
        $line = new IndustryProjectBomLine();
        $line->setProject($this->project);
        $line->setTypeId($typeId);
        $line->setTypeName($typeName);

        return $line;
    }

    private function linkJob(int $esiJobId, Character $character, int $durationSeconds, int $linkedRuns, ?int $cachedJobRuns = null): void
    {
        $start = new \DateTimeImmutable(self::JOB_START);

        $this->cachedJobs[$esiJobId] = (new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId($esiJobId)
            ->setRuns($cachedJobRuns ?? $linkedRuns)
            ->setStartDate($start)
            ->setEndDate($start->modify(sprintf('+%d seconds', $durationSeconds)));

        $this->linkUncachedJob($esiJobId, $linkedRuns);
    }

    private function linkUncachedJob(int $esiJobId, int $linkedRuns): void
    {
        $this->line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId($esiJobId)->setRuns($linkedRuns));
    }
}
