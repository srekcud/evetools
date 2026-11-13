<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\Project\ProjectBomLineMarkResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\BomLineMarkState;
use App\Enum\ProjectStatus;
use App\State\Processor\Industry\Project\RemoveBomLineMarkProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoveBomLineMarkProcessor::class)]
#[Group('issue-807')]
final class RemoveBomLineMarkProcessorTest extends TestCase
{
    use MarksJobLinesOnIshtarBom;

    protected function setUp(): void
    {
        $this->setUpMarkableIshtarProject();
    }

    public function testOwnerRemovesTheStepMarkOfTheRamLineAndItsCountedRunsFallBackToTheMatchedRuns(): void
    {
        $this->markOn($this->ramLine, BomLineMarkState::Done, 4);

        $this->remove($this->owner, $this->ramLine);

        self::assertSame([false, 0, 0, 1], [null !== $this->ramLine->getMark(), $this->ramLine->getMatchedRuns(), $this->ramLine->getCountedRuns(), $this->flushCount]);
    }

    public function testRemovingTheStepMarkOfOneLineLeavesTheStepMarkOfAnotherLine(): void
    {
        $ishtarMark = $this->markOn($this->ishtarLine, BomLineMarkState::Launched, 5);
        $this->markOn($this->ramLine, BomLineMarkState::Done, 4);

        $this->remove($this->owner, $this->ramLine);

        self::assertSame([false, true], [null !== $this->ramLine->getMark(), $ishtarMark === $this->ishtarLine->getMark()]);
    }

    public function testEveryAcceptedParticipantIncludingTheOwnerIsToldTheLineChangedAndAPendingOneIsNot(): void
    {
        $this->markOn($this->ramLine, BomLineMarkState::Done, 4);

        $this->remove($this->owner, $this->ramLine);

        self::assertSame($this->expectedIndustryProjectEvents($this->ramLine), $this->publishedIndustryProjectEvents());
    }

    public function testCompletedProjectRefusesTheRemovalAndKeepsTheStepMark(): void
    {
        $stepMark = $this->markOn($this->ramLine, BomLineMarkState::Done, 4);
        $this->ishtarProject->setStatus(ProjectStatus::Completed);

        self::assertSame(400, $this->statusOf(fn () => $this->remove($this->owner, $this->ramLine)));

        self::assertSame($stepMark, $this->ramLine->getMark());
        self::assertSame([0, []], [$this->flushCount, $this->publishedIndustryProjectEvents()]);
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDeniedAndTheStepMarkStays(): void
    {
        $stepMark = $this->markOn($this->ramLine, BomLineMarkState::Done, 4);

        self::assertSame(self::OWNER_ONLY, $this->outcomeOf(fn () => $this->remove($this->admin, $this->ramLine)));

        self::assertSame($stepMark, $this->ramLine->getMark());
        self::assertSame([0, []], [$this->flushCount, $this->publishedIndustryProjectEvents()]);
    }

    private function remove(User $user, IndustryProjectBomLine $line): void
    {
        $processor = new RemoveBomLineMarkProcessor(
            $this->securityOf($user),
            $this->projectRepository(),
            $this->bomLineRepository(),
            $this->accessChecker(),
            $this->entityManager(),
            $this->mercurePublisher(),
        );

        $processor->process(ProjectBomLineMarkResource::fromLine($line), new Delete(), $this->lineUriVariables($this->ishtarProject, $line));
    }
}
