<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningCharacterShipReader;
use App\Service\MiningOperation\MiningLedgerReader;
use App\Service\MiningOperation\MiningLedgerSnapshotDispatcher;
use App\Service\MiningOperation\MiningLedgerSnapshotTaker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(MiningLedgerSnapshotTaker::class)]
#[Group('issue-364')]
#[AllowMockObjectsWithoutExpectations]
final class MiningLedgerSnapshotTakerShipTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use RecordsMiningOperationNotifications;

    private const int HULK = 22544;
    private const int MACKINAW = 22548;

    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private \ArrayObject $publishedUpdates;
    private BufferingLogger $logger;
    private MockClock $clock;
    private MiningOperation $operation;
    private array $shipRequests = [];

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->logger = new BufferingLogger();
        $this->clock = new MockClock('2026-11-05T20:00:02+00:00');
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testStartSnapshotTakenRecordsTheShipOfTheCharacterReadOnce(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerWhoseShipIs(['ship_type_id' => self::HULK])->take($start);

        self::assertSame(
            ['taken', self::HULK, [sprintf('/characters/%d/ship/', $this->character('Brann Holt')->getEveCharacterId())]],
            [$start->getStatus()->value, $this->enrolmentOf('Brann Holt')->getShipTypeId(), $this->shipRequests],
        );
    }

    public function testStartSnapshotTakenPublishesTheShipTypeIdWithItsData(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerWhoseShipIs(['ship_type_id' => self::HULK])->take($start);

        self::assertSame(
            ['completed', 'taken', self::HULK],
            [$this->lastPublished()['status'], $this->lastPublished()['data']['status'], $this->lastPublished()['data']['shipTypeId'] ?? 'missing'],
        );
    }

    public function testEndSnapshotTakenNeitherReadsNorChangesTheShip(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->enrolmentOf('Brann Holt')->recordShip(self::HULK);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $end = $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:37:17+00:00');
        $this->clock->modify('2026-11-05T21:37:17+00:00');

        $this->takerWhoseShipIs(['ship_type_id' => self::MACKINAW])->take($end);

        self::assertSame(['taken', [], self::HULK], [$end->getStatus()->value, $this->shipRequests, $this->enrolmentOf('Brann Holt')->getShipTypeId()]);
    }

    public function testEndSnapshotStillSchedulingDoesNotReadTheShip(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $end = $this->identify($this->enrolmentOf('Brann Holt')->scheduleEndSnapshot(new \DateTimeImmutable(self::ENDED_AT)));
        $this->clock->modify('2026-11-05T21:30:01+00:00');

        $this->takerWhoseShipIs(['ship_type_id' => self::MACKINAW])->take($end);

        self::assertSame(['waiting', [], null], [$end->getStatus()->value, $this->shipRequests, $this->enrolmentOf('Brann Holt')->getShipTypeId()]);
    }

    public function testStartSnapshotWithUnreadableShipIsStillTakenWithTheShipUnknownAndAWarning(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerWhoseShipFailsWith(EsiApiException::fromResponse(403, 'Access forbidden'))->take($start);

        self::assertSame(
            ['taken', 1, null, 'completed', null],
            [
                $start->getStatus()->value,
                $start->getLines()->count(),
                $this->enrolmentOf('Brann II')->getShipTypeId(),
                $this->lastPublished()['status'],
                array_key_exists('shipTypeId', $this->lastPublished()['data']) ? $this->lastPublished()['data']['shipTypeId'] : 'missing',
            ],
        );
        $this->assertOneWarningAbout('Brann II');
    }

    public function testStartSnapshotWithAnEmptyShipAnswerLeavesTheShipUnknown(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $start = $this->identify($this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT)));

        $this->takerWhoseShipIs([])->take($start);

        self::assertSame(['taken', null], [$start->getStatus()->value, $this->enrolmentOf('Brann Holt')->getShipTypeId()]);
    }

    public function testRetriedStartSnapshotReadsTheShipAgainAndReplacesTheFormerOne(): void
    {
        $this->operation = $this->startedSpodumainOperation();
        $this->enrolmentOf('Brann Holt')->recordShip(self::HULK);
        $retried = $this->failedStartSnapshot('Brann Holt', MiningLedgerSnapshotStatus::Failed);
        $retried->restartFrom(new \DateTimeImmutable(self::STARTED_AT));

        $this->takerWhoseShipIs(['ship_type_id' => self::MACKINAW])->take($retried);

        self::assertSame(['taken', self::MACKINAW], [$retried->getStatus()->value, $this->enrolmentOf('Brann Holt')->getShipTypeId()]);
    }

    private function takerWhoseShipIs(array $shipAnswer): MiningLedgerSnapshotTaker
    {
        return $this->takerWith(function (string $endpoint) use ($shipAnswer): array {
            $this->shipRequests[] = $endpoint;

            return $shipAnswer;
        });
    }

    private function takerWhoseShipFailsWith(\Throwable $failure): MiningLedgerSnapshotTaker
    {
        return $this->takerWith(function (string $endpoint) use ($failure): never {
            $this->shipRequests[] = $endpoint;

            throw $failure;
        });
    }

    private function takerWith(callable $shipEndpoint): MiningLedgerSnapshotTaker
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginatedWithExpiry')->willReturn(new PaginatedEsiResponse([
            ['date' => '2026-11-05', 'quantity' => 12_000, 'solar_system_id' => self::S1, 'type_id' => self::SPODUMAIN],
        ], null));
        $esiClient->method('get')->willReturnCallback($shipEndpoint);
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');

        return new MiningLedgerSnapshotTaker(
            new MiningLedgerReader($esiClient, $tokenManager),
            $this->entityManager,
            new MiningLedgerSnapshotDispatcher($this->busCapturing(new \ArrayObject()), $this->clock),
            new MercurePublisherService($this->hub, new NullLogger()),
            $this->logger,
            $this->clock,
            $this->notifierRecording(),
            new MiningCharacterShipReader($esiClient),
        );
    }

    private function lastPublished(): array
    {
        $last = [];
        foreach ($this->publishedUpdates as $update) {
            \assert($update instanceof Update);
            $last = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $last;
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
