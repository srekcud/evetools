<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\Project\MarkBomLineInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\BomLineMarkState;
use App\Enum\ProjectStatus;
use App\State\Processor\Industry\Project\MarkBomLineProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkBomLineProcessor::class)]
#[Group('issue-807')]
final class MarkBomLineProcessorTest extends TestCase
{
    use MarksJobLinesOnIshtarBom;

    protected function setUp(): void
    {
        $this->setUpMarkableIshtarProject();
    }

    public function testOwnerMarksTheRamLineDoneForThreeRunsAndGetsTheStepMarkBack(): void
    {
        $before = new \DateTimeImmutable('-1 second');

        $resource = $this->mark($this->owner, $this->ramLine, 'done', 3);

        $stepMark = $this->ramLine->getMark();
        self::assertSame(
            [BomLineMarkState::Done, 3, 3, true],
            [$stepMark?->getState(), $stepMark?->getRuns(), $this->ramLine->getCountedRuns(), $stepMark?->getMarkedAt() >= $before],
        );
        self::assertSame(
            ['id' => $this->ramLine->getId()->toRfc4122(), 'lineId' => $this->ramLine->getId()->toRfc4122(), 'state' => 'done', 'runs' => 3],
            array_diff_key(get_object_vars($resource), ['markedAt' => true]),
        );
        self::assertSame(1, $this->flushCount);
    }

    public function testOwnerMarksTheRamLineLaunchedForItsFourRunsAtTheUpperBound(): void
    {
        $this->mark($this->owner, $this->ramLine, 'launched', self::RAM_LINE_RUNS);

        self::assertSame([BomLineMarkState::Launched, self::RAM_LINE_RUNS], [$this->ramLine->getMark()?->getState(), $this->ramLine->getMark()?->getRuns()]);
    }

    public function testOwnerMarksTheIshtarLineDoneForOneRunAtTheLowerBound(): void
    {
        $this->mark($this->owner, $this->ishtarLine, 'done', 1);

        self::assertSame([BomLineMarkState::Done, 1], [$this->ishtarLine->getMark()?->getState(), $this->ishtarLine->getMark()?->getRuns()]);
    }

    public function testMarkingAgainReplacesTheStepMarkInPlaceInsteadOfAddingAnother(): void
    {
        $firstMark = $this->markOn($this->ramLine, BomLineMarkState::Launched, 2);

        $resource = $this->mark($this->owner, $this->ramLine, 'done', 4);

        self::assertSame($firstMark, $this->ramLine->getMark());
        self::assertSame([BomLineMarkState::Done, 4], [$firstMark->getState(), $firstMark->getRuns()]);
        self::assertGreaterThan(new \DateTimeImmutable(self::INITIAL_MARK_DATE), $firstMark->getMarkedAt());
        self::assertSame(['state' => 'done', 'runs' => 4], array_intersect_key(get_object_vars($resource), ['state' => true, 'runs' => true]));
        self::assertSame(1, $this->flushCount);
    }

    public function testRunsBelowOneAreRefusedAndTheStepMarkStaysAsItWas(): void
    {
        $firstMark = $this->markOn($this->ramLine, BomLineMarkState::Launched, 2);

        self::assertSame(400, $this->statusOf(fn () => $this->mark($this->owner, $this->ramLine, 'done', 0)));

        $this->assertStepMarkUntouched($firstMark, BomLineMarkState::Launched, 2);
    }

    public function testRunsAboveTheRunsOfTheLineAreRefusedAndTheStepMarkStaysAsItWas(): void
    {
        $firstMark = $this->markOn($this->ramLine, BomLineMarkState::Launched, 2);

        self::assertSame(400, $this->statusOf(fn () => $this->mark($this->owner, $this->ramLine, 'done', self::RAM_LINE_RUNS + 1)));

        $this->assertStepMarkUntouched($firstMark, BomLineMarkState::Launched, 2);
    }

    public function testUnknownStateIsRefusedWithoutMarkingTheLine(): void
    {
        self::assertSame(400, $this->statusOf(fn () => $this->mark($this->owner, $this->ramLine, 'paused', 2)));

        self::assertNull($this->ramLine->getMark());
        self::assertSame([0, []], [$this->flushCount, $this->publishedIndustryProjectEvents()]);
    }

    public function testMaterialLineIsRefusedWithoutMarkingIt(): void
    {
        self::assertSame(400, $this->statusOf(fn () => $this->mark($this->owner, $this->tritaniumLine, 'done', 1)));

        self::assertNull($this->tritaniumLine->getMark());
        self::assertSame([0, []], [$this->flushCount, $this->publishedIndustryProjectEvents()]);
    }

    public function testCompletedProjectRefusesTheMark(): void
    {
        $this->ishtarProject->setStatus(ProjectStatus::Completed);

        self::assertSame(400, $this->statusOf(fn () => $this->mark($this->owner, $this->ramLine, 'done', 2)));

        self::assertNull($this->ramLine->getMark());
        self::assertSame([0, []], [$this->flushCount, $this->publishedIndustryProjectEvents()]);
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDenied(): void
    {
        self::assertSame(
            self::OWNER_ONLY,
            $this->outcomeOf(fn () => $this->mark($this->admin, $this->ramLine, 'done', 2)),
        );

        self::assertNull($this->ramLine->getMark());
        self::assertSame([0, []], [$this->flushCount, $this->publishedIndustryProjectEvents()]);
    }

    public function testLineOfAnotherProjectIsNotFound(): void
    {
        self::assertSame(
            '404 BOM line not found',
            $this->outcomeOf(fn () => $this->mark($this->owner, $this->hecateTritaniumLine, 'done', 1, $this->ishtarProject)),
        );

        self::assertNull($this->hecateTritaniumLine->getMark());
    }

    public function testEveryAcceptedParticipantIncludingTheOwnerIsToldTheLineChangedAndAPendingOneIsNot(): void
    {
        $this->mark($this->owner, $this->ramLine, 'done', 3);

        self::assertSame($this->expectedIndustryProjectEvents($this->ramLine), $this->publishedIndustryProjectEvents());
    }

    private function mark(User $user, IndustryProjectBomLine $line, string $state, int $runs, ?IndustryProject $project = null): object
    {
        $input = new MarkBomLineInput();
        $input->state = $state;
        $input->runs = $runs;

        return $this->processor($user)->process($input, new Post(), $this->lineUriVariables($project ?? $this->ishtarProject, $line));
    }

    private function assertStepMarkUntouched(object $stepMark, BomLineMarkState $state, int $runs): void
    {
        self::assertSame($stepMark, $this->ramLine->getMark());
        self::assertSame([$state, $runs, self::INITIAL_MARK_DATE, 0, []], [
            $stepMark->getState(),
            $stepMark->getRuns(),
            $stepMark->getMarkedAt()->format(\DATE_ATOM),
            $this->flushCount,
            $this->publishedIndustryProjectEvents(),
        ]);
    }

    private function processor(User $user): MarkBomLineProcessor
    {
        return new MarkBomLineProcessor(
            $this->securityOf($user),
            $this->projectRepository(),
            $this->bomLineRepository(),
            $this->accessChecker(),
            $this->entityManager(),
            $this->mercurePublisher(),
        );
    }
}
