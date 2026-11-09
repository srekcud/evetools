<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\OpenProjectsJobMatcher;
use App\Service\Industry\Project\ProjectJobActivityRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
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
    private ?IndustryProject $failingProject = null;
    private BufferingLogger $logger;
    private OpenProjectsJobMatcher $matcher;

    protected function setUp(): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findOpenOfAcceptedParticipant')->willReturnCallback(
            fn (User $user): array => $this->projectsOfUser,
        );

        $jobMatcher = $this->createStub(IndustryJobMatcher::class);
        $jobMatcher->method('matchEsiJobs')->willReturnCallback(function (IndustryProject $project): void {
            if ($project === $this->failingProject) {
                throw new \RuntimeException('matching failed');
            }
            $this->steps[] = ['match', $project->getName()];
        });

        $derivedContributionService = $this->createStub(DerivedContributionService::class);
        $derivedContributionService->method('refreshLine')->willReturnCallback(function (IndustryProjectBomLine $line): void {
            $this->steps[] = ['refresh', $line->getTypeName()];
        });

        $jobActivityRecorder = $this->createStub(ProjectJobActivityRecorder::class);
        $jobActivityRecorder->method('recordJobsOf')->willReturnCallback(function (IndustryProject $project): void {
            $this->recordedProjects[] = [$project->getName(), \in_array(['match', $project->getName()], $this->steps, true)];
        });

        $this->logger = new BufferingLogger();
        $this->matcher = new OpenProjectsJobMatcher($projectRepository, $jobMatcher, $derivedContributionService, $this->logger, $jobActivityRecorder);
    }

    public function testEachOpenProjectOfTheUserIsMatchedThenItsJobLinesAreRefreshedAndNothingElse(): void
    {
        $this->projectsOfUser = [$this->project('Ishtar batch'), $this->project('Ishtar again')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame(
            [['match', 'Ishtar batch'], ['refresh', 'Ishtar'], ['match', 'Ishtar again'], ['refresh', 'Ishtar']],
            $this->steps,
        );
    }

    public function testAProjectThatFailsToMatchIsLoggedAndTheOthersAreStillMatched(): void
    {
        $failing = $this->project('Broken batch');
        $this->failingProject = $failing;
        $this->projectsOfUser = [$failing, $this->project('Ishtar batch')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['match', 'Ishtar batch'], ['refresh', 'Ishtar']], $this->steps);
        $warnings = array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]);
        self::assertCount(1, $warnings);
        self::assertSame('Failed to match jobs of project', array_values($warnings)[0][1]);
    }

    #[Group('issue-321')]
    public function testTheJobsOfEveryOpenProjectAreRecordedAfterTheyAreMatched(): void
    {
        $this->projectsOfUser = [$this->project('Ishtar batch'), $this->project('Ishtar again')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['Ishtar batch', true], ['Ishtar again', true]], $this->recordedProjects);
    }

    #[Group('issue-321')]
    public function testAProjectWhoseMatchingFailsIsNotRecordedAndTheOthersAre(): void
    {
        $failing = $this->project('Broken batch');
        $this->failingProject = $failing;
        $this->projectsOfUser = [$failing, $this->project('Ishtar batch')];

        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([['Ishtar batch', true]], $this->recordedProjects);
        $this->logger->cleanLogs();
    }

    public function testAUserWithoutOpenProjectMatchesNothing(): void
    {
        $this->matcher->matchOpenProjectsOf(new User());

        self::assertSame([], $this->steps);
    }

    private function project(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner(new User())->setName($name);
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
