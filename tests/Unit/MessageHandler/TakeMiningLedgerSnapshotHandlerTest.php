<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\MiningLedgerSnapshot;
use App\Message\TakeMiningLedgerSnapshot;
use App\MessageHandler\TakeMiningLedgerSnapshotHandler;
use App\Repository\MiningLedgerSnapshotRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningCharacterShipReader;
use App\Service\MiningOperation\MiningLedgerReader;
use App\Service\MiningOperation\MiningLedgerSnapshotDispatcher;
use App\Service\MiningOperation\MiningLedgerSnapshotTaker;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\RecordsMiningOperationNotifications;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TakeMiningLedgerSnapshotHandler::class)]
#[Group('issue-263')]
#[AllowMockObjectsWithoutExpectations]
final class TakeMiningLedgerSnapshotHandlerTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use RecordsMiningOperationNotifications;

    private array $snapshotsById = [];
    private int $esiCalls = 0;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->startedSpodumainOperation();
        $this->logger = new BufferingLogger();
    }

    public function testDeliveredMessageTakesTheSnapshotItNames(): void
    {
        $start = $this->known($this->identify($this->enrolmentOf('Brann Holt')->scheduleStartSnapshot(new \DateTimeImmutable(self::STARTED_AT))));

        $this->handler()(new TakeMiningLedgerSnapshot($start->getId()->toRfc4122()));

        self::assertSame(['taken', 1, 1], [$start->getStatus()->value, $start->getLines()->count(), $this->esiCalls]);
    }

    public function testMessageOfASnapshotDeletedMeanwhileIsLoggedAndIgnored(): void
    {
        $deletedSnapshotId = Uuid::v4()->toRfc4122();

        $this->handler()(new TakeMiningLedgerSnapshot($deletedSnapshotId));

        $logs = $this->logger->cleanLogs();
        self::assertSame([0, 1], [$this->esiCalls, count($logs)]);
        self::assertContains($deletedSnapshotId, array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $logs[0][2]));
    }

    private function known(MiningLedgerSnapshot $snapshot): MiningLedgerSnapshot
    {
        $this->snapshotsById[$snapshot->getId()->toRfc4122()] = $snapshot;

        return $snapshot;
    }

    private function handler(): TakeMiningLedgerSnapshotHandler
    {
        $repository = $this->createStub(MiningLedgerSnapshotRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?MiningLedgerSnapshot => $this->snapshotsById[(string) $id] ?? null);

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginatedWithExpiry')->willReturnCallback(function (): PaginatedEsiResponse {
            ++$this->esiCalls;

            return new PaginatedEsiResponse([['date' => '2026-11-05', 'quantity' => 12_000, 'solar_system_id' => self::S1, 'type_id' => self::SPODUMAIN]], null);
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');
        $clock = new MockClock('2026-11-05T20:00:02+00:00');

        return new TakeMiningLedgerSnapshotHandler(
            $repository,
            new MiningLedgerSnapshotTaker(
                new MiningLedgerReader($esiClient, $tokenManager),
                $this->createStub(EntityManagerInterface::class),
                new MiningLedgerSnapshotDispatcher($this->busCapturing(new \ArrayObject()), $clock),
                new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
                new NullLogger(),
                $clock,
                $this->notifierRecording(),
                new MiningCharacterShipReader($esiClient),
            ),
            $this->logger,
        );
    }
}
