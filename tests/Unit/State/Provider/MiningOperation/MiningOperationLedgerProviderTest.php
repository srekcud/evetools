<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use App\ApiResource\MiningOperation\MiningOperationLedgerResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\TypeNameResolver;
use App\State\Provider\MiningOperation\MiningOperationLedgerProvider;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(MiningOperationLedgerProvider::class)]
#[Group('issue-263')]
final class MiningOperationLedgerProviderTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private Security&Stub $security;
    private MockClock $clock;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperationWithBrann();
        $this->addMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->identifyEnrolments();
        $this->security = $this->createStub(Security::class);
        $this->clock = new MockClock('2026-11-05T21:31:00+00:00');
    }

    public function testClosingOperationWaitingForItsEndSnapshotsShowsTheLatestReadTime(): void
    {
        $this->closedWithEveryStartTaken();
        $this->waitingEndSnapshot('Aria Solberg', '2026-11-05T21:37:17+00:00');
        $this->waitingEndSnapshot('Aria II', '2026-11-05T21:38:02+00:00');
        $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:39:50+00:00');
        $this->waitingEndSnapshot('Brann II', '2026-11-05T21:38:45+00:00');
        $this->actAs($this->aria);

        $ledger = $this->ledger();

        self::assertSame(
            ['closing', '2026-11-05T21:39:50+00:00', false, 0, 4, ['measuring', 'measuring']],
            [
                $ledger->status,
                $ledger->endSnapshotsReadyAt?->format(\DateTimeInterface::ATOM),
                $ledger->endSnapshotsPending,
                $ledger->endSnapshotsSettled,
                $ledger->endSnapshotsTotal,
                array_column($ledger->participants, 'state'),
            ],
        );
    }

    public function testEndSnapshotStillWorkingOutItsReadTimeIsPending(): void
    {
        $this->closedWithEveryStartTaken();
        $this->waitingEndSnapshot('Aria Solberg', '2026-11-05T21:37:17+00:00');
        $this->waitingEndSnapshot('Aria II', '2026-11-05T21:38:02+00:00');
        $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:39:50+00:00');
        $this->identify($this->enrolmentOf('Brann II')->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT)));
        $this->actAs($this->aria);

        $ledger = $this->ledger();

        self::assertSame(
            [true, '2026-11-05T21:39:50+00:00', 0, 4],
            [$ledger->endSnapshotsPending, $ledger->endSnapshotsReadyAt?->format(\DateTimeInterface::ATOM), $ledger->endSnapshotsSettled, $ledger->endSnapshotsTotal],
        );
    }

    public function testEveryEndSnapshotTakenLeavesTheOperationClosing(): void
    {
        $this->closedWithEveryStartTaken();
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'] as $characterName) {
            $this->takenEndSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 15_000)]);
        }
        $this->actAs($this->aria);

        $ledger = $this->ledger();

        self::assertSame(
            ['closing', null, false, 4, 4, ['known', 'known'], [6_000, 6_000]],
            [
                $ledger->status,
                $ledger->endSnapshotsReadyAt,
                $ledger->endSnapshotsPending,
                $ledger->endSnapshotsSettled,
                $ledger->endSnapshotsTotal,
                array_column($ledger->participants, 'state'),
                array_column($ledger->participants, 'totalQuantity'),
            ],
        );
    }

    public function testPendingMemberCannotReadTheLedgerAndIsNotListedAsParticipant(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->actAs($this->aria);

        self::assertSame(['Aria Solberg', 'Brann Holt'], array_column($this->ledger()->participants, 'characterName'));

        $this->actAs($this->cyra);
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->ledger();
    }

    public function testPlannedOperationListsItsParticipantsWithoutAnySnapshot(): void
    {
        $this->actAs($this->brann);

        $ledger = $this->ledger();

        self::assertSame(
            [
                'operationId' => $this->operation->getId()->toRfc4122(),
                'status' => 'planned',
                'participants' => [
                    ['Aria Solberg', 'measuring', null, [], [['Aria II', 'measuring', null, null], ['Aria Solberg', 'measuring', null, null]]],
                    ['Brann Holt', 'measuring', null, [], [['Brann Holt', 'measuring', null, null], ['Brann II', 'measuring', null, null]]],
                ],
            ],
            [
                'operationId' => $ledger->operationId,
                'status' => $ledger->status,
                'participants' => array_map(static fn (array $participant): array => [
                    $participant['characterName'],
                    $participant['state'],
                    $participant['totalQuantity'],
                    $participant['quantities'],
                    array_map(static fn (array $character): array => [$character['name'], $character['state'], $character['startSnapshot'], $character['endSnapshot']], $participant['characters']),
                ], $ledger->participants),
            ],
        );
    }

    public function testUnknownQuantityCorrectedByTheOwnerIsShownWithItsCorrectionAndNeverAsZero(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500)]);
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $this->enrolmentOf('Brann II')->correct(self::SPODUMAIN, null, 20_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $this->actAs($this->aria);

        $brann = $this->participantNamed($this->ledger(), 'Brann Holt');

        self::assertSame(
            [
                'memberId' => $this->membershipOf($this->operation, $this->brann)->getId()->toRfc4122(),
                'characterName' => 'Brann Holt',
                'departedAt' => null,
                'state' => 'known',
                'totalQuantity' => 55_500,
                'quantities' => [['typeId' => self::SPODUMAIN, 'typeName' => 'Spodumain', 'quantity' => 55_500]],
            ],
            array_diff_key($brann, ['characters' => true]),
        );
        self::assertSame(
            [
                'id' => $this->enrolmentOf('Brann II')->getId()->toRfc4122(),
                'eveCharacterId' => $this->character('Brann II')->getEveCharacterId(),
                'name' => 'Brann II',
                'shipTypeId' => null,
                'shipName' => null,
                'state' => 'known',
                'unknownReason' => null,
                'totalQuantity' => 20_000,
                'startSnapshot' => ['status' => 'reconnect_required', 'referenceAt' => self::STARTED_AT, 'readyAt' => self::STARTED_AT, 'takenAt' => null],
                'endSnapshot' => null,
                'canRetry' => false,
                'quantities' => [[
                    'typeId' => self::SPODUMAIN,
                    'typeName' => 'Spodumain',
                    'quantity' => 20_000,
                    'measuredQuantity' => null,
                    'correction' => ['originalQuantity' => null, 'correctedQuantity' => 20_000, 'correctedByName' => 'Aria Solberg', 'correctedAt' => '2026-11-05T21:50:00+00:00'],
                ]],
            ],
            $brann['characters'][1] ?? null,
        );
    }

    public function testCharacterWhoseStartCouldNotBeReadIsUnknownWithItsReasonAndRetryableByItsOwnerOnly(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->failedStartSnapshot('Aria II', MiningLedgerSnapshotStatus::Failed);
        $this->clock->modify('2026-11-05T20:40:00+00:00');
        $this->actAs($this->brann);

        $ledger = $this->ledger();

        self::assertSame(
            [
                'Aria II' => ['unknown', 'read_failed', null, false],
                'Aria Solberg' => ['measuring', null, null, false],
                'Brann Holt' => ['measuring', null, null, false],
                'Brann II' => ['unknown', 'reconnect_required', null, true],
            ],
            $this->characterRows($ledger, static fn (array $character): array => [$character['state'], $character['unknownReason'], $character['totalQuantity'], $character['canRetry']]),
        );
        self::assertSame(['unknown', 'unknown'], array_column($ledger->participants, 'state'));
    }

    public function testQuantityCorrectedTwiceShowsTheLatestCorrectionNextToTheMeasuredQuantity(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->takenEndSnapshot('Brann Holt', [
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500),
            $this->entry('2026-11-05', self::BISTOT, self::S1, 800),
        ]);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $brannHolt = $this->enrolmentOf('Brann Holt');
        $brannHolt->correct(self::SPODUMAIN, 35_500, 30_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $brannHolt->correct(self::SPODUMAIN, 35_500, 33_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:52:00+00:00'));
        $this->actAs($this->aria);

        self::assertSame(
            [
                ['typeId' => self::BISTOT, 'typeName' => 'Bistot', 'quantity' => 800, 'measuredQuantity' => 800, 'correction' => null],
                [
                    'typeId' => self::SPODUMAIN,
                    'typeName' => 'Spodumain',
                    'quantity' => 33_000,
                    'measuredQuantity' => 35_500,
                    'correction' => ['originalQuantity' => 35_500, 'correctedQuantity' => 33_000, 'correctedByName' => 'Aria Solberg', 'correctedAt' => '2026-11-05T21:52:00+00:00'],
                ],
            ],
            $this->participantNamed($this->ledger(), 'Brann Holt')['characters'][0]['quantities'] ?? null,
        );
    }

    #[Group('issue-265')]
    public function testFailedEndSnapshotIsRetryableByTheOwnerButNotByTheCharacterOwnerWhoIsAPlainMember(): void
    {
        $this->closedWithEveryStartTaken();
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt'] as $characterName) {
            $this->takenEndSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 15_000)]);
        }
        $this->failedEndSnapshot('Brann II', MiningLedgerSnapshotStatus::Failed);
        $canRetry = static fn (array $character): bool => $character['canRetry'];

        $this->actAs($this->brann);
        $seenByBrann = $this->characterRows($this->ledger(), $canRetry)['Brann II'] ?? null;
        $this->actAs($this->aria);
        $seenByAria = $this->characterRows($this->ledger(), $canRetry)['Brann II'] ?? null;

        self::assertSame(['brann' => false, 'aria' => true], ['brann' => $seenByBrann, 'aria' => $seenByAria]);
    }

    #[Group('issue-265')]
    public function testCharacterWhoseStartWasReadAfterTheEndIsUnknownWithoutEndSnapshot(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt'] as $characterName) {
            $this->takenStartSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        }
        $brannII = $this->identify($this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable('2026-11-05T21:29:00+00:00')));
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt'] as $characterName) {
            $this->takenEndSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 15_000)]);
        }
        $brannII->take(new \DateTimeImmutable('2026-11-05T21:30:05+00:00'), $this->reading([$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]));
        $this->actAs($this->aria);

        self::assertSame(
            ['unknown', 'no_end_snapshot', null],
            $this->characterRows($this->ledger(), static fn (array $character): array => [$character['state'], $character['unknownReason'], $character['totalQuantity']])['Brann II'] ?? null,
        );
    }

    private function closedWithEveryStartTaken(): void
    {
        $this->operation->start(new \DateTimeImmutable(self::STARTED_AT), $this->s1());
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'] as $characterName) {
            $this->takenStartSnapshot($characterName, [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        }
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
    }

    private function actAs(User $user): void
    {
        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturn($user);
    }

    private function ledger(): MiningOperationLedgerResource
    {
        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findName')->willReturnCallback(static fn (int $typeId): ?string => [self::SPODUMAIN => 'Spodumain', self::BISTOT => 'Bistot'][$typeId] ?? null);

        $provider = new MiningOperationLedgerProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            new MinedQuantityCalculator(),
            $this->lifecycleWith($this->createStub(EntityManagerInterface::class), new \ArrayObject(), $this->clock),
            $typeNameResolver,
        );

        return $provider->provide(new Get(), ['operationId' => $this->operation->getId()->toRfc4122()]);
    }

    private function participantNamed(MiningOperationLedgerResource $ledger, string $characterName): array
    {
        foreach ($ledger->participants as $participant) {
            if ($participant['characterName'] === $characterName) {
                return $participant;
            }
        }

        return [];
    }

    private function characterRows(MiningOperationLedgerResource $ledger, callable $row): array
    {
        $rows = [];
        foreach ($ledger->participants as $participant) {
            foreach ($participant['characters'] as $character) {
                $rows[$character['name']] = $row($character);
            }
        }

        return $rows;
    }
}
