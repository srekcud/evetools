<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\Entity\User;
use App\State\Processor\Industry\DismissUnassignedJobProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DismissUnassignedJobProcessor::class)]
#[Group('issue-1248')]
#[AllowMockObjectsWithoutExpectations]
final class DismissUnassignedJobProcessorTest extends TestCase
{
    use GivesUnassignedJobsOnIshtarProjects;

    private const string NOW = '2026-11-10T12:00:00+00:00';

    protected function setUp(): void
    {
        $this->setUpUnassignedJobsWorld();
    }

    public function testItMarksTheJobOfTheUserAsDismissedAtTheCurrentTimeAndKeepsTheRow(): void
    {
        $row = $this->givenUnassignedJob(
            self::ISHTAR_JOB_ID,
            $this->ownerAltCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ishtarLine, 4)],
        );
        $other = $this->givenUnassignedJob(
            self::RAM_JOB_ID,
            $this->ownerMainCharacter,
            self::RAM_BLUEPRINT_TYPE_ID,
            self::RAM_TYPE_ID,
            4,
            '2026-11-03T08:00:00+00:00',
            [$this->candidateOf($this->ishtarProject, $this->ramLine, 0, [], true)],
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::never())->method('remove');

        $result = $this->dismiss($this->owner, self::ISHTAR_JOB_ID, $entityManager);

        self::assertNull($result);
        self::assertSame([self::NOW, null], [$row->getDismissedAt()?->format('c'), $other->getDismissedAt()?->format('c')]);
    }

    public function testTheJobOfAnotherUserAndAnUnknownJobAreNotFoundAndStayUntouched(): void
    {
        $row = $this->givenUnassignedJob(
            self::ISHTAR_JOB_OF_RIVAL_ID,
            $this->rivalCharacter,
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            self::ISHTAR_TYPE_ID,
            3,
            '2026-11-02T08:00:00+00:00',
            [$this->candidateOf($this->rivalProject, $this->rivalIshtarLine, 4)],
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        self::assertSame(
            [404, 404],
            [
                $this->statusOf(fn () => $this->dismiss($this->owner, self::ISHTAR_JOB_OF_RIVAL_ID, $entityManager)),
                $this->statusOf(fn () => $this->dismiss($this->owner, 7_248_999, $entityManager)),
            ],
        );
        self::assertNull($row->getDismissedAt());
    }

    private function dismiss(User $user, int $esiJobId, EntityManagerInterface $entityManager): mixed
    {
        $processor = new DismissUnassignedJobProcessor(
            $this->securityOf($user),
            $this->unassignedJobRepository(),
            $entityManager,
            new MockClock(self::NOW),
        );

        return $processor->process(new EmptyInput(), new Post(), ['esiJobId' => $esiJobId]);
    }
}
