<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Input\Industry\AssignUnassignedJobInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Service\Industry\Project\ProjectJobActivityRecorder;
use App\State\Processor\Industry\AssignUnassignedJobProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssignUnassignedJobProcessor::class)]
#[Group('issue-1248')]
#[AllowMockObjectsWithoutExpectations]
final class AssignUnassignedJobProcessorTest extends TestCase
{
    use GivesUnassignedJobsOnIshtarProjects;
    use BuildsManualJobLinker;

    private array $recordedProjects = [];

    protected function setUp(): void
    {
        $this->setUpUnassignedJobsWorld();
    }

    public function testItLinksTheJobToTheChosenLineAsAManualLinkRefreshesTheLineAndRecordsTheActivityOfItsProject(): void
    {
        $row = $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerAltCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 4, ['intent'], false),
                $this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 1, [], false),
            ],
        );

        $resource = $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->ishtarLine);

        self::assertInstanceOf(ProjectJobMatchResource::class, $resource);
        self::assertSame(
            [self::ISHTAR_JOB_ID, 3, true, $this->ishtarLine->getId()->toRfc4122(), 'Aria Alt', null],
            [$resource->esiJobId, $resource->runs, $resource->manual, $resource->bomLineId, $resource->characterName, $resource->matchSignals],
        );
        self::assertSame([self::ISHTAR_JOB_ID], array_map(static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(), $this->ishtarLine->getJobMatches()->toArray()));
        self::assertSame([[$this->ishtarLine->getId()->toRfc4122(), 0, 3]], $this->refreshedLines);
        self::assertSame([$this->ishtarProject], $this->recordedProjects);
        self::assertSame([$row], $this->removedEntities);
    }

    public function testAssigningToTheLineOfAProjectOfSomeoneElseRecordsTheActivityOfThatProject(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            4,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 4, [], false),
                $this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 1, [], false),
            ],
        );

        $resource = $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->rivalIshtarLine);

        self::assertSame([self::ISHTAR_JOB_ID, 4, $this->rivalIshtarLine->getId()->toRfc4122()], [$resource->esiJobId, $resource->runs, $resource->bomLineId]);
        self::assertCount(0, $this->ishtarLine->getJobMatches());
        self::assertCount(1, $this->rivalIshtarLine->getJobMatches());
        self::assertSame([$this->rivalProject], $this->recordedProjects);
    }

    public function testTheJobOfAnotherUserAnAlreadyDismissedJobAndAnUnknownJobAreNotFound(): void
    {
        $candidates = [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 4)];
        $this->givenUnassignedJob(self::ISHTAR_JOB_OF_RIVAL_ID, $this->rivalCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 3, '2026-11-02T08:00:00+00:00', $candidates);
        $this->givenUnassignedJob(self::ISHTAR_JOB_ID, $this->ownerMainCharacter, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID, 3, '2026-11-02T09:00:00+00:00', $candidates)
            ->setDismissedAt(new \DateTimeImmutable('2026-11-05T10:00:00+00:00'));

        self::assertSame(
            [404, 404, 404],
            [
                $this->statusOf(fn () => $this->assign($this->owner, self::ISHTAR_JOB_OF_RIVAL_ID, $this->ishtarLine)),
                $this->statusOf(fn () => $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->ishtarLine)),
                $this->statusOf(fn () => $this->assign($this->owner, 7_248_999, $this->ishtarLine)),
            ],
        );
        $this->assertNothingHappened();
    }

    public function testALineThatIsNotACandidateOfTheJobIsRefused(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 4)],
        );

        self::assertSame(400, $this->statusOf(fn () => $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->rivalIshtarLine)));
        $this->assertNothingHappened();
    }

    public function testAStoredCandidateThatIsNoLongerValidIsRefused(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-02T08:00:00+00:00',
            [
                $this->candidateOf($this->completedProject, $this->completedIshtarLine, 5),
                $this->candidateOf($this->strangersProject, $this->strangersIshtarLine, 4),
                $this->candidateOf($this->ishtarProject, $this->ishtarLine, 1),
            ],
        );

        self::assertSame(
            [400, 400],
            [
                $this->statusOf(fn () => $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->completedIshtarLine)),
                $this->statusOf(fn () => $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->strangersIshtarLine)),
            ],
        );
        $this->assertNothingHappened();
    }

    public function testTheRulesOfTheManualLinkStillApplyAndLeaveTheRowAndTheActivityUntouched(): void
    {
        $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerMainCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 4)],
        );
        $this->storedJobMatches[] = (new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(3);

        self::assertSame(
            '400 ESI job 7248201 is already linked to a BOM line',
            $this->outcomeOf(fn () => $this->assign($this->owner, self::ISHTAR_JOB_ID, $this->ishtarLine)),
        );
        $this->assertNothingHappened();
    }

    private function assertNothingHappened(): void
    {
        foreach ($this->allProjects() as $project) {
            foreach ($project->getBomItems() as $line) {
                self::assertCount(0, $line->getJobMatches());
            }
        }
        self::assertSame([[], [], []], [$this->refreshedLines, $this->recordedProjects, $this->removedEntities]);
    }

    private function assign(User $user, int $esiJobId, IndustryProjectBomLine $line): mixed
    {
        $input = new AssignUnassignedJobInput();
        $input->lineId = $line->getId()->toRfc4122();

        return $this->processor($user)->process($input, new Post(), ['esiJobId' => $esiJobId]);
    }

    private function processor(User $user): AssignUnassignedJobProcessor
    {
        $repository = $this->unassignedJobRepository();
        $recorder = $this->createStub(ProjectJobActivityRecorder::class);
        $recorder->method('recordJobsOf')->willReturnCallback(function (IndustryProject $project): void {
            $this->recordedProjects[] = $project;
        });

        return new AssignUnassignedJobProcessor(
            $this->securityOf($user),
            $repository,
            $this->unassignedJobCandidates(),
            $this->manualJobLinker($repository),
            $recorder,
        );
    }
}
