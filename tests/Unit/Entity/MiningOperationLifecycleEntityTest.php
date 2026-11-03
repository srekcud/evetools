<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningLedgerSnapshotLine;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\MiningQuantityCorrection;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperation::class)]
#[CoversClass(MiningOperationMember::class)]
#[CoversClass(MiningOperationCharacter::class)]
#[CoversClass(MiningLedgerSnapshot::class)]
#[CoversClass(MiningQuantityCorrection::class)]
#[Group('issue-263')]
final class MiningOperationLifecycleEntityTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
    }

    public function testStartingInS1PutsTheOperationInProgressAtTheGivenTime(): void
    {
        $operation = $this->spodumainOperationWithBrann();

        $operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());

        self::assertSame(
            ['in_progress', self::STARTED_AT, self::S1, self::S1_NAME, null],
            [
                $operation->getStatus()->value,
                $operation->getStartedAt()?->format(\DateTimeInterface::ATOM),
                $operation->getSolarSystemId(),
                $operation->getSolarSystemName(),
                $operation->getEndedAt(),
            ],
        );
    }

    public function testStartingWithoutSolarSystemKeepsNoSystemFilter(): void
    {
        $operation = $this->spodumainOperationWithBrann();

        $operation->start(new \DateTimeImmutable(self::STARTED_AT), null);

        self::assertSame(
            ['in_progress', self::STARTED_AT, null, null],
            [
                $operation->getStatus()->value,
                $operation->getStartedAt()?->format(\DateTimeInterface::ATOM),
                $operation->getSolarSystemId(),
                $operation->getSolarSystemName(),
            ],
        );
    }

    public function testEndingPutsTheOperationInClosingAndKeepsItsStart(): void
    {
        $operation = $this->startedSpodumainOperation();

        $operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            ['closing', self::STARTED_AT, self::ENDED_AT],
            [
                $operation->getStatus()->value,
                $operation->getStartedAt()?->format(\DateTimeInterface::ATOM),
                $operation->getEndedAt()?->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function testDepartingKeepsTheParticipantAcceptedWithItsRole(): void
    {
        $operation = $this->startedSpodumainOperation();
        $brannMembership = $this->membershipOf($operation, $this->brann);
        $brannMembership->setRole(MiningOperationMemberRole::Admin);

        $brannMembership->depart(new \DateTimeImmutable('2026-11-05T21:05:00+00:00'));

        self::assertSame(
            ['2026-11-05T21:05:00+00:00', MiningOperationMemberStatus::Accepted, MiningOperationMemberRole::Admin, ['Brann Holt', 'Brann II'], 2],
            [
                $brannMembership->getDepartedAt()?->format(\DateTimeInterface::ATOM),
                $brannMembership->getStatus(),
                $brannMembership->getRole(),
                $this->enrolledNamesOf($brannMembership),
                count($operation->acceptedMembers()),
            ],
        );
    }

    public function testStartSnapshotIsWaitingToBeReadAtItsReferenceTime(): void
    {
        $this->startedSpodumainOperation();
        $enrolment = $this->enrolmentOf('Brann Holt');

        $snapshot = $enrolment->scheduleStartSnapshot(new \DateTimeImmutable('2026-11-05T20:13:00+00:00'));

        self::assertSame($snapshot, $enrolment->snapshotOf(MiningLedgerSnapshotKind::Start));
        self::assertNull($enrolment->snapshotOf(MiningLedgerSnapshotKind::End));
        self::assertSame(
            ['kind' => 'start', 'status' => 'waiting', 'referenceAt' => '2026-11-05T20:13:00+00:00', 'readyAt' => '2026-11-05T20:13:00+00:00'],
            $this->snapshotStateOf($snapshot),
        );
        self::assertCount(1, $enrolment->getSnapshots());
    }

    public function testEndSnapshotWaitsForItsReadTimeToBeKnown(): void
    {
        $this->startedSpodumainOperation();
        $enrolment = $this->enrolmentOf('Brann Holt');
        $enrolment->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT));

        $snapshot = $enrolment->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame($snapshot, $enrolment->snapshotOf(MiningLedgerSnapshotKind::End));
        self::assertSame(
            ['kind' => 'end', 'status' => 'scheduling', 'referenceAt' => self::ENDED_AT, 'readyAt' => null],
            $this->snapshotStateOf($snapshot),
        );
        self::assertCount(2, $enrolment->getSnapshots());
    }

    public function testEndSnapshotScheduledAtItsReadTimeIsWaiting(): void
    {
        $this->startedSpodumainOperation();
        $snapshot = $this->enrolmentOf('Brann Holt')->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT));

        $snapshot->schedule(new \DateTimeImmutable('2026-11-05T21:37:17+00:00'));

        self::assertSame(
            ['kind' => 'end', 'status' => 'waiting', 'referenceAt' => self::ENDED_AT, 'readyAt' => '2026-11-05T21:37:17+00:00'],
            $this->snapshotStateOf($snapshot),
        );
    }

    public function testTakenSnapshotKeepsEveryLedgerEntryReadWithTheEsiExpiry(): void
    {
        $this->startedSpodumainOperation();
        $snapshot = $this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT));

        $snapshot->take(new \DateTimeImmutable('2026-11-05T20:00:02+00:00'), $this->reading([
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000),
            $this->entry('2026-11-04', self::BISTOT, self::S2, 2_400),
        ], '2026-11-05T20:09:40+00:00'));

        self::assertSame(
            ['taken', '2026-11-05T20:00:02+00:00', '2026-11-05T20:09:40+00:00', null],
            [
                $snapshot->getStatus()->value,
                $snapshot->getTakenAt()?->format(\DateTimeInterface::ATOM),
                $snapshot->getEsiExpiresAt()?->format(\DateTimeInterface::ATOM),
                $snapshot->getFailureReason(),
            ],
        );
        self::assertSame(
            [['2026-11-05', self::SPODUMAIN, self::S1, 12_000], ['2026-11-04', self::BISTOT, self::S2, 2_400]],
            array_map(static fn (MiningLedgerSnapshotLine $line): array => [
                $line->getDate()->format('Y-m-d'),
                $line->getTypeId(),
                $line->getSolarSystemId(),
                $line->getQuantity(),
            ], array_values($snapshot->getLines()->toArray())),
        );
    }

    public function testFailedSnapshotKeepsItsFailureReasonAndNoLine(): void
    {
        $this->startedSpodumainOperation();
        $snapshot = $this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT));

        $snapshot->fail(MiningLedgerSnapshotStatus::Failed, 'ESI server error');

        self::assertSame(
            ['failed', 'ESI server error', null, 0],
            [$snapshot->getStatus()->value, $snapshot->getFailureReason(), $snapshot->getTakenAt(), $snapshot->getLines()->count()],
        );
    }

    public function testCorrectionsOfACharacterAreAllKeptWithTheMainCharacterOfTheirAuthor(): void
    {
        $this->startedSpodumainOperation();
        $enrolment = $this->enrolmentOf('Brann Holt');

        $enrolment->correct(self::SPODUMAIN, 35_500, 30_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $enrolment->correct(self::SPODUMAIN, 35_500, 33_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:52:00+00:00'));

        self::assertSame(
            [
                [self::SPODUMAIN, 35_500, 30_000, 'Aria Solberg', '2026-11-05T21:50:00+00:00'],
                [self::SPODUMAIN, 35_500, 33_000, 'Aria Solberg', '2026-11-05T21:52:00+00:00'],
            ],
            array_map(static fn (MiningQuantityCorrection $correction): array => [
                $correction->getTypeId(),
                $correction->getOriginalQuantity(),
                $correction->getCorrectedQuantity(),
                $correction->getCorrectedByName(),
                $correction->getCorrectedAt()->format(\DateTimeInterface::ATOM),
            ], array_values($enrolment->getCorrections()->toArray())),
        );
    }
}
