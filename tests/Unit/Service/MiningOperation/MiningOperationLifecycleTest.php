<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MiningLedgerSnapshotDispatcher;
use App\Service\MiningOperation\MiningOperationLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(MiningOperationLifecycle::class)]
#[CoversClass(MiningLedgerSnapshotDispatcher::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class MiningOperationLifecycleTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const array ALL_ENROLLED_CHARACTERS = ['Aria II', 'Aria Solberg', 'Brann Holt', 'Brann II'];

    private EntityManagerInterface&MockObject $entityManager;
    private \ArrayObject $journal;
    private \ArrayObject $dispatched;
    private MockClock $clock;
    private MiningOperation $operation;
    private MiningOperationMember $cyraMembership;
    private MiningOperationLifecycle $lifecycle;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperationWithBrann();
        $this->cyraMembership = $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->identifyEnrolments();

        $this->journal = new \ArrayObject();
        $this->dispatched = new \ArrayObject();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->identifyingOnFlush($this->entityManager, $this->journal);
        $this->clock = new MockClock(self::STARTED_AT);
        $this->lifecycle = new MiningOperationLifecycle(
            $this->solarSystemRepositoryKnowingS1AndS2(),
            $this->entityManager,
            new MiningLedgerSnapshotDispatcher($this->busCapturing($this->dispatched, $this->journal), $this->clock),
            $this->clock,
        );
    }

    public function testStartingInS1TakesTheStartSnapshotOfEveryAcceptedCharacterOnceSaved(): void
    {
        $this->lifecycle->start($this->operation, self::S1);

        self::assertSame(
            ['in_progress', self::STARTED_AT, self::S1, self::S1_NAME],
            [
                $this->operation->getStatus()->value,
                $this->operation->getStartedAt()?->format(\DateTimeInterface::ATOM),
                $this->operation->getSolarSystemId(),
                $this->operation->getSolarSystemName(),
            ],
        );
        self::assertSame(
            array_fill_keys(self::ALL_ENROLLED_CHARACTERS, ['kind' => 'start', 'status' => 'waiting', 'referenceAt' => self::STARTED_AT, 'readyAt' => self::STARTED_AT]),
            $this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::Start),
        );
        self::assertSame($this->messagesWithoutDelayFor(MiningLedgerSnapshotKind::Start, self::ALL_ENROLLED_CHARACTERS), $this->sortedDispatches());
        $this->assertSnapshotsSentOnlyOnceSaved();
    }

    public function testStartingWithoutSolarSystemCountsEverySystem(): void
    {
        $this->lifecycle->start($this->operation, null);

        self::assertSame(
            ['in_progress', null, null, 4],
            [
                $this->operation->getStatus()->value,
                $this->operation->getSolarSystemId(),
                $this->operation->getSolarSystemName(),
                count($this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::Start)),
            ],
        );
    }

    public function testStartingInAnUnknownSolarSystemIsRefusedAndTheOperationStaysPlanned(): void
    {
        $this->assertRefused(
            BadRequestHttpException::class,
            'Unknown solar system',
            fn () => $this->lifecycle->start($this->operation, self::UNKNOWN_SOLAR_SYSTEM),
        );

        self::assertSame(['planned', [], []], [$this->operation->getStatus()->value, $this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::Start), $this->journal->getArrayCopy()]);
    }

    public function testOperationAlreadyInProgressCannotBeStartedAgain(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->clock->modify('2026-11-05T20:20:00+00:00');

        $this->assertRefused(
            BadRequestHttpException::class,
            'Only a planned operation can be started',
            fn () => $this->lifecycle->start($this->operation, self::S2),
        );

        self::assertSame([self::S1_NAME, self::STARTED_AT, []], [$this->operation->getSolarSystemName(), $this->operation->getStartedAt()?->format(\DateTimeInterface::ATOM), $this->journal->getArrayCopy()]);
    }

    public function testParticipantAcceptedAfterTheStartGetsItsStartSnapshotAtItsAcceptance(): void
    {
        $this->lifecycle->start($this->operation, self::S1);
        $this->clock->modify('2026-11-05T20:13:00+00:00');
        $this->cyraMembership->setStatus(MiningOperationMemberStatus::Accepted);

        $this->lifecycle->snapshotLateParticipant($this->cyraMembership);

        self::assertSame(
            ['kind' => 'start', 'status' => 'waiting', 'referenceAt' => '2026-11-05T20:13:00+00:00', 'readyAt' => '2026-11-05T20:13:00+00:00'],
            $this->snapshotStateOf($this->enrolmentOf('Cyra Lune')->snapshotOf(MiningLedgerSnapshotKind::Start)),
        );
        self::assertSame(
            ['snapshotId' => $this->snapshotIdOf('Cyra Lune', MiningLedgerSnapshotKind::Start), 'delay' => null],
            $this->dispatchedSnapshots($this->dispatched)[4] ?? null,
        );
    }

    public function testEndingTakesTheEndSnapshotOfEveryCharacterWhoseStartWasTaken(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();
        $this->clock->modify(self::ENDED_AT);

        $this->lifecycle->end($this->operation);

        self::assertSame(
            ['closing', self::ENDED_AT],
            [$this->operation->getStatus()->value, $this->operation->getEndedAt()?->format(\DateTimeInterface::ATOM)],
        );
        self::assertSame(
            array_fill_keys(self::ALL_ENROLLED_CHARACTERS, ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => self::ENDED_AT, 'readyAt' => null]),
            $this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::End),
        );
        self::assertSame($this->messagesWithoutDelayFor(MiningLedgerSnapshotKind::End, self::ALL_ENROLLED_CHARACTERS), $this->sortedDispatches());
        $this->assertSnapshotsSentOnlyOnceSaved();
    }

    public function testCharacterWhoseStartWasNotTakenGetsNoEndSnapshot(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt'] as $characterName) {
            $this->takenStartSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        }
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->clock->modify(self::ENDED_AT);

        $this->lifecycle->end($this->operation);

        self::assertSame(['Aria II', 'Aria Solberg', 'Brann Holt'], array_keys($this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::End)));
        self::assertSame($this->messagesWithoutDelayFor(MiningLedgerSnapshotKind::End, ['Aria II', 'Aria Solberg', 'Brann Holt']), $this->sortedDispatches());
    }

    public function testPlannedOperationCannotBeEnded(): void
    {
        $this->assertRefused(
            BadRequestHttpException::class,
            'Only an operation in progress can be ended',
            fn () => $this->lifecycle->end($this->operation),
        );

        self::assertSame(['planned', null, []], [$this->operation->getStatus()->value, $this->operation->getEndedAt(), $this->journal->getArrayCopy()]);
    }

    public function testParticipantLeavingEarlyGetsTheEndSnapshotOfItsCharactersAndStaysParticipant(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();
        $this->clock->modify('2026-11-05T21:05:00+00:00');

        $this->lifecycle->depart($this->operation, $this->brann);

        $brannMembership = $this->membershipOf($this->operation, $this->brann);
        self::assertSame(
            ['2026-11-05T21:05:00+00:00', 'accepted', 2, 'in_progress'],
            [
                $brannMembership->getDepartedAt()?->format(\DateTimeInterface::ATOM),
                $brannMembership->getStatus()->value,
                count($this->operation->acceptedMembers()),
                $this->operation->getStatus()->value,
            ],
        );
        self::assertSame(
            array_fill_keys(['Brann Holt', 'Brann II'], ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => '2026-11-05T21:05:00+00:00', 'readyAt' => null]),
            $this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::End),
        );
        self::assertSame($this->messagesWithoutDelayFor(MiningLedgerSnapshotKind::End, ['Brann Holt', 'Brann II']), $this->sortedDispatches());
    }

    public function testParticipantWhoAlreadyLeftCannotLeaveAgain(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();
        $this->membershipOf($this->operation, $this->brann)->depart(new \DateTimeImmutable('2026-11-05T21:05:00+00:00'));
        $this->clock->modify('2026-11-05T21:10:00+00:00');

        $this->assertRefused(
            BadRequestHttpException::class,
            'You already left this operation',
            fn () => $this->lifecycle->depart($this->operation, $this->brann),
        );

        self::assertSame(['2026-11-05T21:05:00+00:00', []], [$this->membershipOf($this->operation, $this->brann)->getDepartedAt()?->format(\DateTimeInterface::ATOM), $this->journal->getArrayCopy()]);
    }

    public function testClosingOperationCannotBeLeftEarly(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        $this->assertRefused(
            BadRequestHttpException::class,
            'Only an operation in progress can be left early',
            fn () => $this->lifecycle->depart($this->operation, $this->brann),
        );
    }

    public function testUserWithoutAcceptedMembershipCannotLeaveEarly(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();

        $this->assertRefused(NotFoundHttpException::class, 'Membership not found', fn () => $this->lifecycle->depart($this->operation, $this->dorn));
        $this->assertRefused(NotFoundHttpException::class, 'Membership not found', fn () => $this->lifecycle->depart($this->operation, $this->cyra));
    }

    public function testEndKeepsTheEndSnapshotsTakenAtAnEarlyDeparture(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();
        $this->clock->modify('2026-11-05T21:05:00+00:00');
        $this->lifecycle->depart($this->operation, $this->brann);
        $sentAtDeparture = count($this->dispatched);
        $this->clock->modify(self::ENDED_AT);

        $this->lifecycle->end($this->operation);

        self::assertSame(
            [
                'Aria II' => ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => self::ENDED_AT, 'readyAt' => null],
                'Aria Solberg' => ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => self::ENDED_AT, 'readyAt' => null],
                'Brann Holt' => ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => '2026-11-05T21:05:00+00:00', 'readyAt' => null],
                'Brann II' => ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => '2026-11-05T21:05:00+00:00', 'readyAt' => null],
            ],
            $this->snapshotStatesOf($this->operation, MiningLedgerSnapshotKind::End),
        );
        self::assertSame([2, 4], [$sentAtDeparture, count($this->dispatched)]);
    }

    public function testOwnerWhoLeftEarlyCanStillEndTheOperation(): void
    {
        $this->operationStartedWithEveryStartSnapshotTaken();
        $this->clock->modify('2026-11-05T21:05:00+00:00');
        $this->lifecycle->depart($this->operation, $this->aria);
        $this->clock->modify(self::ENDED_AT);

        $this->lifecycle->end($this->operation);

        self::assertSame(
            ['closing', '2026-11-05T21:05:00+00:00', self::ENDED_AT],
            [
                $this->operation->getStatus()->value,
                $this->enrolmentOf('Aria II')->snapshotOf(MiningLedgerSnapshotKind::End)?->getReferenceAt()->format(\DateTimeInterface::ATOM),
                $this->enrolmentOf('Brann II')->snapshotOf(MiningLedgerSnapshotKind::End)?->getReferenceAt()->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function testStartSnapshotNeedingReconnectionIsRetriedFromNowWhileTheOperationIsInProgress(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $start = $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->clock->modify('2026-11-05T20:40:00+00:00');

        $this->lifecycle->retrySnapshot($this->enrolmentOf('Brann II'));

        $retried = $this->enrolmentOf('Brann II')->snapshotOf(MiningLedgerSnapshotKind::Start);
        self::assertSame(
            ['kind' => 'start', 'status' => 'waiting', 'referenceAt' => '2026-11-05T20:40:00+00:00', 'readyAt' => '2026-11-05T20:40:00+00:00'],
            $this->snapshotStateOf($retried),
        );
        self::assertSame([['snapshotId' => $retried?->getId()?->toRfc4122() ?? $start->getId()->toRfc4122(), 'delay' => null]], $this->dispatchedSnapshots($this->dispatched));
    }

    public function testTakenStartSnapshotCannotBeRetried(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);

        $this->assertRefused(BadRequestHttpException::class, 'This snapshot cannot be retried', fn () => $this->lifecycle->retrySnapshot($this->enrolmentOf('Aria Solberg')));

        self::assertSame([], $this->journal->getArrayCopy());
    }

    public function testFailedEndSnapshotIsReadAgainRightAwayWhileClosing(): void
    {
        $this->closingOperationWhereBrannHoltStarted();
        $end = $this->failedEndSnapshot('Brann Holt', MiningLedgerSnapshotStatus::Failed);
        $this->clock->modify('2026-11-05T21:50:00+00:00');

        $this->lifecycle->retrySnapshot($this->enrolmentOf('Brann Holt'));

        self::assertSame(
            ['kind' => 'end', 'status' => 'waiting', 'referenceAt' => self::ENDED_AT, 'readyAt' => '2026-11-05T21:50:00+00:00'],
            $this->snapshotStateOf($this->enrolmentOf('Brann Holt')->snapshotOf(MiningLedgerSnapshotKind::End)),
        );
        self::assertSame([['snapshotId' => $end->getId()->toRfc4122(), 'delay' => null]], $this->dispatchedSnapshots($this->dispatched));
    }

    public function testEndSnapshotStalledSixMinutesAfterItsReadTimeIsReadAgain(): void
    {
        $this->closingOperationWhereBrannHoltStarted();
        $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:37:17+00:00');
        $this->clock->modify('2026-11-05T21:43:17+00:00');

        $this->lifecycle->retrySnapshot($this->enrolmentOf('Brann Holt'));

        self::assertSame(
            ['kind' => 'end', 'status' => 'waiting', 'referenceAt' => self::ENDED_AT, 'readyAt' => '2026-11-05T21:43:17+00:00'],
            $this->snapshotStateOf($this->enrolmentOf('Brann Holt')->snapshotOf(MiningLedgerSnapshotKind::End)),
        );
        self::assertCount(1, $this->dispatched);
    }

    public function testEndSnapshotStillAwaitingItsReadTimeCannotBeRetried(): void
    {
        $this->closingOperationWhereBrannHoltStarted();
        $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:40:00+00:00');
        $this->clock->modify('2026-11-05T21:37:00+00:00');

        $this->assertRefused(BadRequestHttpException::class, 'This snapshot cannot be retried', fn () => $this->lifecycle->retrySnapshot($this->enrolmentOf('Brann Holt')));

        self::assertSame([], $this->journal->getArrayCopy());
    }

    public function testStartSnapshotCannotBeRetriedOnceTheOperationIsClosing(): void
    {
        $this->closingOperationWhereBrannHoltStarted();
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::Failed);

        $this->assertRefused(BadRequestHttpException::class, 'This snapshot cannot be retried', fn () => $this->lifecycle->retrySnapshot($this->enrolmentOf('Brann II')));
    }

    public function testRetryIsOfferedOnlyForASnapshotThatCanBeRetriedNow(): void
    {
        $this->closingOperationWhereBrannHoltStarted();
        $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:37:17+00:00');
        $this->takenStartSnapshot('Aria II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 6_000)]);
        $this->waitingEndSnapshot('Aria II', '2026-11-05T21:46:00+00:00');
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->clock->modify('2026-11-05T21:43:17+00:00');

        self::assertSame(
            ['Brann Holt' => true, 'Aria II' => false, 'Brann II' => false],
            [
                'Brann Holt' => $this->lifecycle->canRetrySnapshot($this->enrolmentOf('Brann Holt')),
                'Aria II' => $this->lifecycle->canRetrySnapshot($this->enrolmentOf('Aria II')),
                'Brann II' => $this->lifecycle->canRetrySnapshot($this->enrolmentOf('Brann II')),
            ],
        );
    }

    private function operationStartedWithEveryStartSnapshotTaken(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        foreach (self::ALL_ENROLLED_CHARACTERS as $characterName) {
            $this->takenStartSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        }
    }

    private function closingOperationWhereBrannHoltStarted(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
    }

    private function snapshotIdOf(string $characterName, MiningLedgerSnapshotKind $kind): ?string
    {
        $snapshot = $this->enrolmentOf($characterName)->snapshotOf($kind);

        return $snapshot instanceof MiningLedgerSnapshot ? $snapshot->getId()?->toRfc4122() : null;
    }

    private function messagesWithoutDelayFor(MiningLedgerSnapshotKind $kind, array $characterNames): array
    {
        $messages = array_map(fn (string $characterName): array => ['snapshotId' => $this->snapshotIdOf($characterName, $kind), 'delay' => null], $characterNames);
        usort($messages, static fn (array $left, array $right): int => strcmp((string) $left['snapshotId'], (string) $right['snapshotId']));

        return $messages;
    }

    private function sortedDispatches(): array
    {
        $messages = $this->dispatchedSnapshots($this->dispatched);
        usort($messages, static fn (array $left, array $right): int => strcmp((string) $left['snapshotId'], (string) $right['snapshotId']));

        return $messages;
    }

    private function assertSnapshotsSentOnlyOnceSaved(): void
    {
        $steps = $this->journal->getArrayCopy();
        $lastFlush = array_key_last(array_filter($steps, static fn (string $step): bool => $step === 'flush'));
        $firstDispatch = array_key_first(array_filter($steps, static fn (string $step): bool => $step === 'dispatch'));

        self::assertNotNull($lastFlush);
        self::assertGreaterThan($lastFlush, $firstDispatch);
    }

    private function assertRefused(string $exceptionClass, string $message, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $refusal) {
            self::assertSame([$exceptionClass, $message], [$refusal::class, $refusal->getMessage()]);

            return;
        }

        self::fail(sprintf('Expected the refusal "%s"', $message));
    }
}
