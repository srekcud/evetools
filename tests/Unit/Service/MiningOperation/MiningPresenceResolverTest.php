<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningPresenceState;
use App\Service\MiningOperation\MiningPresenceResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MiningPresenceResolver::class)]
#[Group('issue-307')]
#[AllowMockObjectsWithoutExpectations]
final class MiningPresenceResolverTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const string NOW = '2026-11-05T20:00:00+00:00';

    private MiningOperation $operation;
    private MiningPresenceResolver $resolver;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        $this->resolver = new MiningPresenceResolver(new MockClock(self::NOW));
    }

    #[DataProvider('presenceCases')]
    public function testBrannPresenceFollowsHisLastHeartbeatAndHisDeparture(?string $lastSeenAt, ?string $departedAt, string $expectedState, ?int $expectedIdleMinutes): void
    {
        $brann = $this->membershipOf($this->operation, $this->brann);
        if ($lastSeenAt !== null) {
            $this->lastSeenAt($brann, $lastSeenAt);
        }
        if ($departedAt !== null) {
            $brann->depart(new \DateTimeImmutable($departedAt));
        }

        $presence = $this->resolver->resolve($brann);

        self::assertSame([$expectedState, $expectedIdleMinutes], [$presence?->state->value, $presence?->idleMinutes]);
    }

    public static function presenceCases(): iterable
    {
        yield 'P1 seen 30 seconds ago' => ['2026-11-05T19:59:30+00:00', null, 'online', 0];
        yield 'P2 seen exactly 120 seconds ago' => ['2026-11-05T19:58:00+00:00', null, 'online', 0];
        yield 'P3 seen 121 seconds ago' => ['2026-11-05T19:57:59+00:00', null, 'afk', 2];
        yield 'P4 seen 12 minutes ago' => ['2026-11-05T19:48:00+00:00', null, 'afk', 12];
        yield 'P5 seen 95 minutes ago' => ['2026-11-05T18:25:00+00:00', null, 'afk', 95];
        yield 'P6 never seen' => [null, null, 'unknown', null];
        yield 'P7 left after a recent heartbeat' => ['2026-11-05T19:59:50+00:00', '2026-11-05T19:30:00+00:00', 'left', null];
    }

    public function testBrannHasNoPresenceOnceTheOperationIsClosingEvenAfterARecentHeartbeat(): void
    {
        $brann = $this->membershipOf($this->operation, $this->brann);
        $this->lastSeenAt($brann, '2026-11-05T19:59:50+00:00');
        $whileInProgress = $this->resolver->resolve($brann)?->state;
        $this->operation->end(new \DateTimeImmutable('2026-11-05T19:59:55+00:00'));

        self::assertSame([MiningPresenceState::Online, null], [$whileInProgress, $this->resolver->resolve($brann)]);
    }

    public function testBrannHasNoPresenceWhileHisMembershipIsPending(): void
    {
        $brann = $this->membershipOf($this->operation, $this->brann);
        $this->lastSeenAt($brann, '2026-11-05T19:59:50+00:00');
        $whileAccepted = $this->resolver->resolve($brann)?->state;
        $brann->setStatus(MiningOperationMemberStatus::Pending);

        self::assertSame([MiningPresenceState::Online, null], [$whileAccepted, $this->resolver->resolve($brann)]);
    }
}
