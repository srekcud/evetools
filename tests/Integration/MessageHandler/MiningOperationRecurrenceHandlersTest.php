<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationRecurrence;
use App\Entity\User;
use App\Enum\MiningOperationRecurrenceKind;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Message\CreateRecurringMiningOperations;
use App\Message\TriggerMiningOperationRecurrences;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('issue-310')]
final class MiningOperationRecurrenceHandlersTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    private const int ATHANOR_STRUCTURE_ID = 1_001;

    private User $aria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
    }

    public function testScheduledTriggerQueuesOneCreationPerActiveRecurrenceAndNoneForAStoppedOne(): void
    {
        $activeWeekly = $this->recurrence(MiningOperationRecurrenceKind::Weekly, null);
        $activeChunk = $this->recurrence(MiningOperationRecurrenceKind::Chunk, self::ATHANOR_STRUCTURE_ID);
        $stopped = $this->recurrence(MiningOperationRecurrenceKind::Weekly, null);
        $stopped->stop(new \DateTimeImmutable('2026-11-06T08:00:00+00:00'));
        $this->em->flush();

        $this->handle(new TriggerMiningOperationRecurrences(), 'scheduler_default');

        $queuedRecurrenceIds = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage() instanceof CreateRecurringMiningOperations ? $envelope->getMessage()->recurrenceId : $envelope->getMessage()::class,
            $this->asyncTransport()->getSent(),
        );
        sort($queuedRecurrenceIds);
        $expectedRecurrenceIds = [(string) $activeWeekly->getId(), (string) $activeChunk->getId()];
        sort($expectedRecurrenceIds);
        self::assertSame($expectedRecurrenceIds, $queuedRecurrenceIds);
    }

    public function testEachCreatedOccurrenceIsAnnouncedToTheOwnerAsARecurrenceCreatedEvent(): void
    {
        $published = [];
        $hub = new class($published) implements HubInterface {
            public function __construct(private array &$published)
            {
            }

            public function getPublicUrl(): string
            {
                return 'https://hub.invalid/.well-known/mercure';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                $this->published[] = $update;

                return 'urn:uuid:published';
            }

            public function getProtocolVersion(): ProtocolVersion
            {
                return ProtocolVersion::V1;
            }

            public function getCookieName(): string
            {
                return '__Secure-mercure_access_token';
            }
        };
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
        $recurrence = $this->recurrence(MiningOperationRecurrenceKind::Chunk, self::ATHANOR_STRUCTURE_ID);
        $this->extraction(81, '2099-01-05T20:00:00+00:00');
        $this->extraction(82, '2099-02-05T20:00:00+00:00');
        $this->em->flush();

        $this->handle(new CreateRecurringMiningOperations((string) $recurrence->getId()), 'async');

        $this->em->clear();
        $createdOperationIds = array_map(
            static fn (MiningOperation $operation): string => (string) $operation->getId(),
            $this->em->getRepository(MiningOperation::class)->findBy(['recurrence' => $recurrence->getId()]),
        );
        $announcements = array_map(
            static fn (Update $update): array => [$update->getTopics(), json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)],
            $published,
        );
        $announcedOperationIds = array_map(static fn (array $announcement): string => $announcement[1]['operationId'], $announcements);
        sort($createdOperationIds);
        sort($announcedOperationIds);
        self::assertCount(2, $createdOperationIds);
        self::assertSame($createdOperationIds, $announcedOperationIds);
        self::assertSame(
            [[sprintf('/user/%s/mining-operations', $this->aria->getId())], 'recurrence_created'],
            [$announcements[0][0], $announcements[0][1]['action']],
        );
    }

    private function handle(object $message, string $receivedFromTransport): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($message, [new ReceivedStamp($receivedFromTransport)]));
    }

    private function asyncTransport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function recurrence(MiningOperationRecurrenceKind $kind, ?int $structureId): MiningOperationRecurrence
    {
        $recurrence = (new MiningOperationRecurrence())
            ->setOwner($this->aria)
            ->setKind($kind)
            ->setLabel('Jaspet de jeudi')
            ->setVisibility(MiningOperationVisibility::Corporation)
            ->setSplitMode(MiningSplitMode::ValueProrata)
            ->setCorporationId(self::C1)
            ->setStructureId($structureId)
            ->setCreatedAt(new \DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->em->persist($recurrence);
        $this->em->flush();

        return $recurrence;
    }

    private function extraction(int $moonId, string $chunkArrivalTime): CorporationMiningExtraction
    {
        $extraction = (new CorporationMiningExtraction())
            ->setCorporationId(self::C1)
            ->setStructureId(self::ATHANOR_STRUCTURE_ID)
            ->setMoonId($moonId)
            ->setExtractionStartTime(new \DateTimeImmutable('2026-11-03T18:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable($chunkArrivalTime))
            ->setNaturalDecayTime(new \DateTimeImmutable($chunkArrivalTime));
        $this->em->persist($extraction);

        return $extraction;
    }
}
