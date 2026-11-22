<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\OpenProjectsJobMatcher;
use App\Service\Industry\Project\ProjectJobActivityRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(OpenProjectsJobMatcher::class)]
#[Group('issue-318')]
final class OpenProjectsJobMatcherTest extends TestCase
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int TRITANIUM_TYPE_ID = 34;

    private array $steps = [];
    private array $projectsOfUser = [];
    private array $recordedProjects = [];
    private array $matchedProjectNames = [];
    private ?IndustryProject $failingProject = null;
    private bool $passFails = false;
    private BufferingLogger $logger;
    private OpenProjectsJobMatcher $matcher;

    protected function setUp(): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findOpenOfAcceptedParticipant')->willReturnCallback(
            fn (User $user): array => $this->projectsOfUser,
        );

        $jobMatcher = $this->createStub(IndustryJobMatcher::class);
        $jobMatcher->method('matchOpenProjects')->willReturnCallback(function (array $projects): array {
            if ($this->passFails) {
                throw new \RuntimeException('matching failed');
            }
            $names = array_map(static fn (IndustryProject $project): ?string => $project->getName(), $projects);
            $this->steps[] = ['match', $names];
            array_push($this->matchedProjectNames, ...$names);

            $failures = [];
            foreach ($projects as $project) {
                $failures[$project->getId()->toRfc4122()] = [];
            }

            return $failures;
        });
        $jobMatcher->method('releaseAutomaticMatches')->willReturnCallback(function (IndustryProject $project): void {
            if ($project === $this->failingProject) {
                throw new \RuntimeException('release failed');
            }
            $this->steps[] = ['release', $project->getName()];
        });

        $derivedContributionService = $this->createStub(DerivedContributionService::class);
        $derivedContributionService->method('refreshLine')->willReturnCallback(function (IndustryProjectBomLine $line): void {
            $this->steps[] = ['refresh', $line->getTypeName()];
        });

        $jobActivityRecorder = $this->createStub(ProjectJobActivityRecorder::class);
        $jobActivityRecorder->method('recordJobsOf')->willReturnCallback(function (IndustryProject $project): void {
            if ($project === $this->failingProject) {
                throw new \RuntimeException('recording failed');
            }
            $this->recordedProjects[] = [$project->getName(), \in_array($project->getName(), $this->matchedProjectNames, true)];
        });

        $this->logger = new BufferingLogger();
        $this->matcher = new OpenProjectsJobMatcher($projectRepository, $jobMatcher, $derivedContributionService, $this->logger, $jobActivityRecorder);
    }

    #[Group('issue-1246')]
    public function testEveryLaunchedProjectOfTheUserIsMatchedInOnePassThenItsJobLinesAreRefreshedAndNothingElse(): void
    {
        $this->projectsOfUser = [$this->project('Ishtar batch'), $this->project('Ishtar again')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame(
            [['match', ['Ishtar batch', 'Ishtar again']], ['refresh', 'Ishtar'], ['refresh', 'Ishtar']],
            $this->steps,
        );
    }

    #[Group('issue-1246')]
    public function testAPassThatFailsIsLoggedOnceWithTheProjectsAndTheJobLinesAreStillRefreshed(): void
    {
        $first = $this->project('Broken batch');
        $second = $this->project('Ishtar batch');
        $this->passFails = true;
        $this->projectsOfUser = [$first, $second];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['refresh', 'Ishtar'], ['refresh', 'Ishtar']], $this->steps);
        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]));
        self::assertCount(1, $warnings);
        self::assertSame('Failed to match jobs of projects', $warnings[0][1]);
        self::assertSame([$first->getId()->toRfc4122(), $second->getId()->toRfc4122()], $warnings[0][2]['projectIds']);
    }

    #[Group('issue-321')]
    public function testTheJobsOfEveryOpenProjectAreRecordedAfterTheyAreMatched(): void
    {
        $this->projectsOfUser = [$this->project('Ishtar batch'), $this->project('Ishtar again')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['Ishtar batch', true], ['Ishtar again', true]], $this->recordedProjects);
    }

    #[Group('issue-1246')]
    public function testTheJobsAreStillRecordedForEveryProjectWhenThePassFails(): void
    {
        $this->passFails = true;
        $this->projectsOfUser = [$this->project('Broken batch'), $this->project('Ishtar batch')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['Broken batch', false], ['Ishtar batch', false]], $this->recordedProjects);
        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]));
        self::assertSame(['Failed to match jobs of projects'], array_map(static fn (array $record): string => $record[1], $warnings));
    }

    #[Group('issue-1246')]
    public function testAProjectWhoseRecordingFailsIsLoggedAndTheOthersAreStillRecordedAndRefreshed(): void
    {
        $failing = $this->project('Broken batch');
        $this->failingProject = $failing;
        $this->projectsOfUser = [$failing, $this->project('Ishtar batch')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['Ishtar batch', true]], $this->recordedProjects);
        self::assertSame([['match', ['Broken batch', 'Ishtar batch']], ['refresh', 'Ishtar']], $this->steps);
        $warnings = array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]);
        self::assertCount(1, $warnings);
        self::assertSame('Failed to match jobs of project', array_values($warnings)[0][1]);
    }

    public function testAUserWithoutOpenProjectMatchesNothing(): void
    {
        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([], $this->steps);
    }

    #[Group('issue-1123')]
    public function testADraftProjectIsReleasedAndItsJobLinesRefreshedButNeverMatchedNorRecorded(): void
    {
        $this->projectsOfUser = [$this->project('Nomad 18', ProjectStatus::Draft)];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['release', 'Nomad 18'], ['refresh', 'Ishtar']], $this->steps);
        self::assertSame([], $this->recordedProjects);
    }

    #[Group('issue-1123')]
    #[Group('issue-1246')]
    public function testADraftIsReleasedBeforeTheOtherProjectsAreMatchedEvenWhenItIsListedAfterThem(): void
    {
        $this->projectsOfUser = [$this->project('Launch', ProjectStatus::InProgress), $this->project('Nomad 18', ProjectStatus::Draft)];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame(
            [['release', 'Nomad 18'], ['refresh', 'Ishtar'], ['match', ['Launch']], ['refresh', 'Ishtar']],
            $this->steps,
        );
    }

    #[Group('issue-1123')]
    #[Group('issue-1246')]
    #[DataProvider('statusesMatchedAutomatically')]
    public function testEveryOpenStatusOtherThanDraftIsMatched(ProjectStatus $status): void
    {
        $this->projectsOfUser = [$this->project('Ishtar batch', $status)];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['match', ['Ishtar batch']], ['refresh', 'Ishtar']], $this->steps);
    }

    /**
     * @return iterable<string, array{ProjectStatus}>
     */
    public static function statusesMatchedAutomatically(): iterable
    {
        yield 'published' => [ProjectStatus::Published];
        yield 'in progress' => [ProjectStatus::InProgress];
        yield 'selling' => [ProjectStatus::Selling];
    }

    #[Group('issue-1123')]
    #[Group('issue-1246')]
    public function testADraftThatFailsToBeReleasedIsLoggedAndTheOtherProjectsAreStillMatched(): void
    {
        $failing = $this->project('Nomad 18', ProjectStatus::Draft);
        $this->failingProject = $failing;
        $this->projectsOfUser = [$failing, $this->project('Launch', ProjectStatus::InProgress)];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['match', ['Launch']], ['refresh', 'Ishtar']], $this->steps);
        $warnings = array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]);
        self::assertCount(1, $warnings);
    }

    #[Group('issue-1246')]
    public function testTheLaunchedProjectsAreMatchedInOnePassWhileDraftsAreFullyReleasedAndLeftOutOfIt(): void
    {
        $this->projectsOfUser = [
            $this->project('Launch', ProjectStatus::InProgress),
            $this->project('Nomad 18', ProjectStatus::Draft),
            $this->project('Selling batch', ProjectStatus::Selling),
        ];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame(
            [
                ['release', 'Nomad 18'],
                ['refresh', 'Ishtar'],
                ['match', ['Launch', 'Selling batch']],
                ['refresh', 'Ishtar'],
                ['refresh', 'Ishtar'],
            ],
            $this->steps,
        );
        self::assertSame([['Launch', true], ['Selling batch', true]], $this->recordedProjects);
    }

    private function project(string $name, ProjectStatus $status = ProjectStatus::InProgress): IndustryProject
    {
        $project = (new IndustryProject())->setOwner(new User())->setName($name)->setStatus($status);
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId(self::TRITANIUM_TYPE_ID)
                ->setTypeName('Tritanium')
                ->setRequiredQuantity(1_000)
                ->setIsJob(false),
        );
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId(self::ISHTAR_TYPE_ID)
                ->setTypeName('Ishtar')
                ->setRequiredQuantity(5)
                ->setIsJob(true)
                ->setJobGroup('final')
                ->setActivityType('manufacturing')
                ->setRuns(5),
        );

        return $project;
    }
}
