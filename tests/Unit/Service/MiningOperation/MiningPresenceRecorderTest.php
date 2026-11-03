<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningPresenceRecorder;
use App\Service\MiningOperation\MiningPresenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(MiningPresenceRecorder::class)]
#[Group('issue-307')]
#[AllowMockObjectsWithoutExpectations]
final class MiningPresenceRecorderTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const string NOW = '2026-11-05T20:00:00+00:00';

    private EntityManagerInterface&MockObject $entityManager;
    private MiningOperation $operation;
    private MiningPresenceRecorder $recorder;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $clock = new MockClock(self::NOW);
        $this->recorder = new MiningPresenceRecorder(new MiningPresenceResolver($clock), $clock, $this->entityManager);
    }

    public function testFirstHeartbeatOfBrannIsRecordedAndReportedAsAChange(): void
    {
        $this->entityManager->expects($this->once())->method('flush');

        $changed = $this->recorder->record($this->operation, $this->brann);

        self::assertSame([true, self::NOW], [$changed, $this->lastSeenAtOf($this->brann)]);
    }

    public function testHeartbeatThirtySecondsLaterIsRecordedWithoutChange(): void
    {
        $this->lastSeenAt($this->membershipOf($this->operation, $this->brann), '2026-11-05T19:59:30+00:00');
        $this->entityManager->expects($this->once())->method('flush');

        $changed = $this->recorder->record($this->operation, $this->brann);

        self::assertSame([false, self::NOW], [$changed, $this->lastSeenAtOf($this->brann)]);
    }

    public function testHeartbeatAfterFiveMinutesOfSilenceIsReportedAsAChange(): void
    {
        $this->lastSeenAt($this->membershipOf($this->operation, $this->brann), '2026-11-05T19:55:00+00:00');
        $this->entityManager->expects($this->once())->method('flush');

        $changed = $this->recorder->record($this->operation, $this->brann);

        self::assertSame([true, self::NOW], [$changed, $this->lastSeenAtOf($this->brann)]);
    }

    public function testPendingMemberCannotBeat(): void
    {
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Membership not found');

        try {
            $this->recorder->record($this->operation, $this->cyra);
        } finally {
            self::assertNull($this->lastSeenAtOf($this->cyra));
        }
    }

    public function testUserWithoutMembershipCannotBeat(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Membership not found');

        $this->recorder->record($this->operation, $this->dorn);
    }

    public function testPlannedOperationRefusesTheHeartbeat(): void
    {
        $planned = $this->spodumainOperationWithBrann();
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Presence is only tracked while an operation is in progress');

        try {
            $this->recorder->record($planned, $this->brann);
        } finally {
            self::assertNull($this->lastSeenAtOf($this->brann, $planned));
        }
    }

    public function testClosingOperationRefusesTheHeartbeat(): void
    {
        $this->operation->end(new \DateTimeImmutable('2026-11-05T19:59:00+00:00'));
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Presence is only tracked while an operation is in progress');

        try {
            $this->recorder->record($this->operation, $this->brann);
        } finally {
            self::assertNull($this->lastSeenAtOf($this->brann));
        }
    }

    public function testBrannWhoLeftEarlyCannotBeat(): void
    {
        $this->membershipOf($this->operation, $this->brann)->depart(new \DateTimeImmutable('2026-11-05T19:30:00+00:00'));
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('You already left this operation');

        $this->recorder->record($this->operation, $this->brann);
    }

    public function testOwnerBeatsAsAnAcceptedMember(): void
    {
        $this->entityManager->expects($this->once())->method('flush');

        $changed = $this->recorder->record($this->operation, $this->aria);

        self::assertSame([true, self::NOW], [$changed, $this->lastSeenAtOf($this->aria)]);
    }

    private function lastSeenAtOf(User $user, ?MiningOperation $operation = null): ?string
    {
        return $this->membershipOf($operation ?? $this->operation, $user)->getLastSeenAt()?->format(\DateTimeInterface::ATOM);
    }
}
