<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Message\TakeMiningLedgerSnapshot;
use App\Service\MiningOperation\MiningLedgerSnapshotDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MiningLedgerSnapshotDispatcher::class)]
#[Group('issue-263')]
final class MiningLedgerSnapshotDispatcherTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private \ArrayObject $dispatched;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->startedSpodumainOperation();
        $this->dispatched = new \ArrayObject();
    }

    public function testSnapshotsReadableNowAreSentWithoutDelay(): void
    {
        $start = $this->identify($this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));
        $end = $this->identify($this->enrolmentOf('Aria II')->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT)));

        $this->dispatcherAt(self::ENDED_AT)->dispatch($start, $end);

        self::assertSame(
            [
                ['snapshotId' => $start->getId()->toRfc4122(), 'delay' => null],
                ['snapshotId' => $end->getId()->toRfc4122(), 'delay' => null],
            ],
            $this->dispatchedSnapshots($this->dispatched),
        );
        self::assertContainsOnlyInstancesOf(TakeMiningLedgerSnapshot::class, array_map(static fn ($envelope): object => $envelope->getMessage(), $this->dispatched->getArrayCopy()));
    }

    public function testEndSnapshotReadableLaterIsSentWithTheRemainingDelayInMilliseconds(): void
    {
        $end = $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:37:17+00:00');

        $this->dispatcherAt('2026-11-05T21:30:01+00:00')->dispatch($end);

        self::assertSame([['snapshotId' => $end->getId()->toRfc4122(), 'delay' => 436_000]], $this->dispatchedSnapshots($this->dispatched));
    }

    private function dispatcherAt(string $now): MiningLedgerSnapshotDispatcher
    {
        return new MiningLedgerSnapshotDispatcher($this->busCapturing($this->dispatched), new MockClock($now));
    }
}
