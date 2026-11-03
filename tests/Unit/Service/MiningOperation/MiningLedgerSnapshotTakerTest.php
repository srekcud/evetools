<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningLedgerReader;
use App\Service\MiningOperation\MiningLedgerSnapshotDispatcher;
use App\Service\MiningOperation\MiningLedgerSnapshotTaker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(MiningLedgerSnapshotTaker::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class MiningLedgerSnapshotTakerTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const string READY_AT = '2026-11-05T21:37:17+00:00';

    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private \ArrayObject $publishedUpdates;
    private \ArrayObject $dispatched;
    private BufferingLogger $logger;
    private MockClock $clock;
    private MiningOperation $operation;
    private int $esiCalls = 0;
    private array $esiRequests = [];

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->dispatched = new \ArrayObject();
        $this->logger = new BufferingLogger();
        $this->clock = new MockClock('2026-11-05T20:00:02+00:00');
    }

    public function testStartSnapshotDueIsTakenWithEveryLedgerEntryAndItsProgressIsPublishedToTheAudience(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $snapshots = [];
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'] as $characterName) {
            $snapshots[$characterName] = $this->identify($this->enrolmentOf($characterName)->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));
        }
        $brannHolt = $snapshots['Brann Holt'];

        $this->takerReading(new PaginatedEsiResponse([
            $this->ledgerRow('2026-11-05', self::SPODUMAIN, self::S1, 12_000),
            $this->ledgerRow('2026-10-28', self::BISTOT, self::S2, 7_300),
        ], new \DateTimeImmutable('2026-11-05T20:09:40+00:00')))->take($brannHolt);

        self::assertSame(
            ['taken', '2026-11-05T20:00:02+00:00', '2026-11-05T20:09:40+00:00', 2, 1],
            [
                $brannHolt->getStatus()->value,
                $brannHolt->getTakenAt()?->format(\DateTimeInterface::ATOM),
                $brannHolt->getEsiExpiresAt()?->format(\DateTimeInterface::ATOM),
                $brannHolt->getLines()->count(),
                $this->esiCalls,
            ],
        );
        self::assertSame([sprintf('/characters/%d/mining/', $this->character('Brann Holt')->getEveCharacterId())], $this->esiRequests);
        self::assertSame(
            $this->byTopic([
                $this->snapshotTopicOf($this->aria) => ['started', 'completed'],
                $this->snapshotTopicOf($this->brann) => ['started', 'completed'],
            ]),
            $this->publishedStatusesByTopic(),
        );
        self::assertSame(
            [
                'operationId' => $this->operation->getId()->toRfc4122(),
                'operationCharacterId' => $this->enrolmentOf('Brann Holt')->getId()->toRfc4122(),
                'characterName' => 'Brann Holt',
                'kind' => 'start',
                'status' => 'taken',
                'readyAt' => self::STARTED_AT,
                'settled' => 1,
                'total' => 4,
            ],
            $this->lastPublishedDataOn($this->snapshotTopicOf($this->aria)),
        );
    }

    public function testEndSnapshotFirstReadOnlyLearnsItsReadTimeFromTheEsiCacheExpiry(): void
    {
        $this->operation = $this->endedOperationWhereBrannHoltStartedAt12000();
        $end = $this->identify($this->enrolmentOf('Brann Holt')->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT)));
        $this->clock->modify('2026-11-05T21:30:01+00:00');

        $this->takerReading(new PaginatedEsiResponse(
            [$this->ledgerRow('2026-11-05', self::SPODUMAIN, self::S1, 41_000)],
            new \DateTimeImmutable('Thu, 05 Nov 2026 21:37:12 GMT'),
        ))->take($end);

        self::assertSame(
            ['kind' => 'end', 'status' => 'waiting', 'referenceAt' => self::ENDED_AT, 'readyAt' => self::READY_AT],
            $this->snapshotStateOf($end),
        );
        self::assertSame([0, 1], [$end->getLines()->count(), $this->esiCalls]);
        self::assertSame([['snapshotId' => $end->getId()->toRfc4122(), 'delay' => 436_000]], $this->dispatchedSnapshots($this->dispatched));
        self::assertSame(
            $this->byTopic([
                $this->snapshotTopicOf($this->aria) => ['started', 'in_progress'],
                $this->snapshotTopicOf($this->brann) => ['started', 'in_progress'],
            ]),
            $this->publishedStatusesByTopic(),
        );
        $progress = $this->lastPublishedPayloadOn($this->snapshotTopicOf($this->brann));
        self::assertSame(
            [0, 'end', 'waiting', self::READY_AT, 0, 1],
            [$progress['progress'], $progress['data']['kind'], $progress['data']['status'], $progress['data']['readyAt'], $progress['data']['settled'], $progress['data']['total']],
        );
    }

    public function testEndSnapshotReceivedBeforeItsReadTimeIsSentAgainWithTheRemainingDelayWithoutEsiCall(): void
    {
        $this->operation = $this->endedOperationWhereBrannHoltStartedAt12000();
        $end = $this->waitingEndSnapshot('Brann Holt', self::READY_AT);
        $this->clock->modify('2026-11-05T21:35:00+00:00');

        $this->takerReading(new PaginatedEsiResponse([], null))->take($end);

        self::assertSame([['snapshotId' => $end->getId()->toRfc4122(), 'delay' => 137_000]], $this->dispatchedSnapshots($this->dispatched));
        self::assertSame(['waiting', 0, []], [$end->getStatus()->value, $this->esiCalls, $this->publishedStatusesByTopic()]);
    }

    public function testEndSnapshotReceivedAtItsReadTimeIsTakenAndGivesTheMinedQuantity(): void
    {
        $this->operation = $this->endedOperationWhereBrannHoltStartedAt12000();
        $end = $this->waitingEndSnapshot('Brann Holt', self::READY_AT);
        $this->clock->modify(self::READY_AT);

        $this->takerReading(new PaginatedEsiResponse([$this->ledgerRow('2026-11-05', self::SPODUMAIN, self::S1, 47_500)], new \DateTimeImmutable('2026-11-05T21:47:17+00:00')))->take($end);

        self::assertSame(['taken', self::READY_AT, 1], [$end->getStatus()->value, $end->getTakenAt()?->format(\DateTimeInterface::ATOM), $this->esiCalls]);
        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
            $this->quantityOf((new MinedQuantityCalculator())->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
        self::assertSame(
            $this->byTopic([
                $this->snapshotTopicOf($this->aria) => ['started', 'completed'],
                $this->snapshotTopicOf($this->brann) => ['started', 'completed'],
            ]),
            $this->publishedStatusesByTopic(),
        );
    }

    public function testEndSnapshotWithoutEsiCacheExpiryIsReadTenMinutesAfterItsReferenceTime(): void
    {
        $this->operation = $this->endedOperationWhereBrannHoltStartedAt12000();
        $end = $this->identify($this->enrolmentOf('Brann Holt')->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT)));
        $this->clock->modify('2026-11-05T21:30:01+00:00');

        $this->takerReading(new PaginatedEsiResponse([$this->ledgerRow('2026-11-05', self::SPODUMAIN, self::S1, 41_000)], null))->take($end);

        self::assertSame(
            ['kind' => 'end', 'status' => 'waiting', 'referenceAt' => self::ENDED_AT, 'readyAt' => '2026-11-05T21:40:00+00:00'],
            $this->snapshotStateOf($end),
        );
        self::assertSame([['snapshotId' => $end->getId()->toRfc4122(), 'delay' => 599_000]], $this->dispatchedSnapshots($this->dispatched));
    }

    public function testRevokedCharacterNeedsReconnectingAndTheFailureIsPublishedAndLogged(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerFailingWith(new EveAuthRequiredException((string) $this->character('Brann II')->getEveCharacterId()), throughTokenManager: true)->take($start);

        self::assertSame(['reconnect_required', 0], [$start->getStatus()->value, $start->getLines()->count()]);
        self::assertSame(
            $this->byTopic([
                $this->snapshotTopicOf($this->aria) => ['started', 'error'],
                $this->snapshotTopicOf($this->brann) => ['started', 'error'],
            ]),
            $this->publishedStatusesByTopic(),
        );
        self::assertSame('reconnect_required', $this->lastPublishedDataOn($this->snapshotTopicOf($this->brann))['status']);
        $this->assertOneWarningAbout('Brann II');
    }

    #[DataProvider('unauthorizedEsiStatuses')]
    public function testLedgerRefusedByEsiNeedsReconnecting(int $statusCode): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerFailingWith(EsiApiException::fromResponse($statusCode, 'ESI request failed'))->take($start);

        self::assertSame(['reconnect_required', null], [$start->getStatus()->value, $start->getTakenAt()]);
        $this->assertOneWarningAbout('Brann II');
    }

    public static function unauthorizedEsiStatuses(): iterable
    {
        yield '403 forbidden' => [403];
        yield '401 unauthorized' => [401];
    }

    public function testEsiServerErrorFailsTheSnapshotWithItsReason(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerFailingWith(EsiApiException::fromResponse(502, 'ESI server error'))->take($start);

        self::assertSame(['failed', 'ESI server error'], [$start->getStatus()->value, $start->getFailureReason()]);
        self::assertSame(
            $this->byTopic([
                $this->snapshotTopicOf($this->aria) => ['started', 'error'],
                $this->snapshotTopicOf($this->brann) => ['started', 'error'],
            ]),
            $this->publishedStatusesByTopic(),
        );
        $this->assertOneWarningAbout('Brann II');
    }

    public function testDatabaseFailureWhileSavingTheSnapshotIsLeftToMessengerToRetry(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));
        $databaseFailure = new \RuntimeException('SQLSTATE[08006]: connection to server lost');
        $this->entityManager->method('flush')->willThrowException($databaseFailure);
        $taker = $this->takerReading(new PaginatedEsiResponse([$this->ledgerRow('2026-11-05', self::SPODUMAIN, self::S1, 12_000)], null));

        $raised = null;
        try {
            $taker->take($start);
        } catch (\Throwable $failure) {
            $raised = $failure;
        }

        self::assertSame($databaseFailure, $raised);

        self::assertSame(
            $this->byTopic([
                $this->snapshotTopicOf($this->aria) => ['started'],
                $this->snapshotTopicOf($this->brann) => ['started'],
            ]),
            $this->publishedStatusesByTopic(),
        );
    }

    public function testReplayedMessageOfATakenSnapshotIsIgnoredWithoutEsiCall(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->clock->modify('2026-11-05T20:05:00+00:00');

        $this->takerReading(new PaginatedEsiResponse([$this->ledgerRow('2026-11-05', self::SPODUMAIN, self::S1, 15_000)], null))->take($start);

        self::assertSame(
            ['taken', self::STARTED_AT, 1, 0, [], []],
            [
                $start->getStatus()->value,
                $start->getTakenAt()?->format(\DateTimeInterface::ATOM),
                $start->getLines()->count(),
                $this->esiCalls,
                $this->publishedStatusesByTopic(),
                $this->dispatchedSnapshots($this->dispatched),
            ],
        );
    }

    public function testEndSnapshotReadThirtyDaysAfterTheStartIsBeyondTheLedgerWithoutEsiCall(): void
    {
        $this->operation = $this->startedSpodumainOperation('2026-10-05T20:00:00+00:00');
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-10-05', self::SPODUMAIN, self::S1, 12_000)], '2026-10-05T20:00:00+00:00');
        $this->operation->end(new \DateTimeImmutable('2026-11-04T09:00:00+00:00'));
        $end = $this->waitingEndSnapshot('Brann Holt', '2026-11-04T09:10:00+00:00', '2026-11-04T09:00:00+00:00');
        $this->clock->modify('2026-11-04T09:10:05+00:00');

        $this->takerReading(new PaginatedEsiResponse([$this->ledgerRow('2026-11-04', self::SPODUMAIN, self::S1, 4_000)], null))->take($end);

        self::assertSame(['out_of_window', 0], [$end->getStatus()->value, $this->esiCalls]);
        self::assertSame(
            ['state' => 'unknown', 'unknownReason' => 'out_of_window', 'quantities' => [], 'totalQuantity' => null],
            $this->quantityOf((new MinedQuantityCalculator())->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
        self::assertSame('error', $this->lastPublishedPayloadOn($this->snapshotTopicOf($this->brann))['status']);
    }

    public function testEndSnapshotReadTwentyNineDaysAfterTheStartIsReadNormally(): void
    {
        $this->operation = $this->startedSpodumainOperation('2026-10-06T20:00:00+00:00');
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-10-06', self::SPODUMAIN, self::S1, 12_000)], '2026-10-06T20:00:00+00:00');
        $this->operation->end(new \DateTimeImmutable('2026-11-04T09:00:00+00:00'));
        $end = $this->waitingEndSnapshot('Brann Holt', '2026-11-04T09:10:00+00:00', '2026-11-04T09:00:00+00:00');
        $this->clock->modify('2026-11-04T09:10:05+00:00');

        $this->takerReading(new PaginatedEsiResponse([
            $this->ledgerRow('2026-10-06', self::SPODUMAIN, self::S1, 15_000),
            $this->ledgerRow('2026-11-04', self::SPODUMAIN, self::S1, 4_000),
        ], null))->take($end);

        self::assertSame(['taken', 1], [$end->getStatus()->value, $this->esiCalls]);
        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 7_000], 'totalQuantity' => 7_000],
            $this->quantityOf((new MinedQuantityCalculator())->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
    }

    private function endedOperationWhereBrannHoltStartedAt12000(): MiningOperation
    {
        $operation = $this->startedSpodumainOperation();
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $operation->end(new \DateTimeImmutable(self::ENDED_AT));

        return $operation;
    }

    private function ledgerRow(string $date, int $typeId, int $solarSystemId, int $quantity): array
    {
        return ['date' => $date, 'quantity' => $quantity, 'solar_system_id' => $solarSystemId, 'type_id' => $typeId];
    }

    private function takerReading(PaginatedEsiResponse $response): MiningLedgerSnapshotTaker
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginatedWithExpiry')->willReturnCallback(function (string $endpoint) use ($response): PaginatedEsiResponse {
            ++$this->esiCalls;
            $this->esiRequests[] = $endpoint;

            return $response;
        });

        return $this->takerWith($esiClient, $this->tokenManagerGranting());
    }

    private function takerFailingWith(\Throwable $failure, bool $throughTokenManager = false): MiningLedgerSnapshotTaker
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginatedWithExpiry')->willReturnCallback(function () use ($failure, $throughTokenManager): never {
            ++$this->esiCalls;

            throw $throughTokenManager ? new \LogicException('ESI must not be called for a revoked character') : $failure;
        });

        if (!$throughTokenManager) {
            return $this->takerWith($esiClient, $this->tokenManagerGranting());
        }

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willThrowException($failure);

        return $this->takerWith($esiClient, $tokenManager);
    }

    private function tokenManagerGranting(): TokenManager
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');

        return $tokenManager;
    }

    private function takerWith(EsiClient $esiClient, TokenManager $tokenManager): MiningLedgerSnapshotTaker
    {
        return new MiningLedgerSnapshotTaker(
            new MiningLedgerReader($esiClient, $tokenManager),
            $this->entityManager,
            new MiningLedgerSnapshotDispatcher($this->busCapturing($this->dispatched), $this->clock),
            new MercurePublisherService($this->hub, new BufferingLogger()),
            $this->logger,
            $this->clock,
        );
    }

    private function snapshotTopicOf(User $user): string
    {
        return sprintf('/user/%s/sync/%s', $user->getId()->toRfc4122(), self::SNAPSHOT_SYNC_TYPE);
    }

    private function publishedStatusesByTopic(): array
    {
        $statuses = [];
        foreach ($this->publishedUpdates as $update) {
            \assert($update instanceof Update);
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            foreach ($update->getTopics() as $topic) {
                $statuses[$topic][] = $payload['status'];
            }
        }
        ksort($statuses);

        return $statuses;
    }

    private function byTopic(array $statusesByTopic): array
    {
        ksort($statusesByTopic);

        return $statusesByTopic;
    }

    private function lastPublishedPayloadOn(string $topic): array
    {
        $last = null;
        foreach ($this->publishedUpdates as $update) {
            if ($update->getTopics() === [$topic]) {
                $last = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $last ?? [];
    }

    private function lastPublishedDataOn(string $topic): array
    {
        return $this->lastPublishedPayloadOn($topic)['data'] ?? [];
    }

    private function assertOneWarningAbout(string $characterName): void
    {
        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $log): bool => $log[0] === 'warning'));
        self::assertCount(1, $warnings);
        $contextValues = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $warnings[0][2]);
        self::assertContains($this->operation->getId()->toRfc4122(), $contextValues);
        self::assertNotSame([], array_intersect([$characterName, (string) $this->character($characterName)->getEveCharacterId()], $contextValues));
    }
}
