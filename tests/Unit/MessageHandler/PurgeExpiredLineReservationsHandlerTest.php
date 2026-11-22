<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Message\PurgeExpiredLineReservations;
use App\MessageHandler\PurgeExpiredLineReservationsHandler;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(PurgeExpiredLineReservationsHandler::class)]
#[Group('issue-1301')]
final class PurgeExpiredLineReservationsHandlerTest extends TestCase
{
    private const string SYNC_KEY = 'line-reservations-purge';
    private const int FEROX_TYPE_ID = 16_227;
    private const int NOCXIUM_TYPE_ID = 38;

    private IndustryProjectLineReservationRepository $reservationRepository;
    private EntityManagerInterface $entityManager;

    /** @var list<Update> */
    private array $publishedUpdates = [];

    /** @var list<object> */
    private array $removed = [];

    private int $flushCount = 0;

    /** @var list<array<int, string|null>> */
    private array $trackerCalls = [];

    protected function setUp(): void
    {
        $this->reservationRepository = $this->createStub(IndustryProjectLineReservationRepository::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });
    }

    public function testExpiredReservationsOfSeveralProjectsAreDeletedAndEachReleaseIsPublished(): void
    {
        $projectOfSana = $this->projectNamed('Sana');
        $projectOfMaya = $this->projectNamed('Maya');
        $feroxLine = $this->lineOf($projectOfSana, self::FEROX_TYPE_ID, 'Ferox');
        $nocxiumLine = $this->lineOf($projectOfMaya, self::NOCXIUM_TYPE_ID, 'Nocxium');
        $expiredOfSana = new IndustryProjectLineReservation($feroxLine, $this->participantOf($projectOfSana, 'Sana Vale'), 7, new \DateTimeImmutable('-49 hours'));
        $expiredOfMaya = new IndustryProjectLineReservation($nocxiumLine, $this->participantOf($projectOfMaya, 'Maya Orsk'), 120, new \DateTimeImmutable('-72 hours'));
        $this->reservationRepository->method('findExpired')->willReturn([$expiredOfSana, $expiredOfMaya]);

        $this->invokeHandler();

        self::assertSame([$expiredOfSana, $expiredOfMaya], $this->removed);
        self::assertGreaterThanOrEqual(1, $this->flushCount);
        self::assertSame(
            [
                [
                    'topic' => sprintf('/group-project/%s/events', $projectOfSana->getId()->toRfc4122()),
                    'action' => 'line_reserved',
                    'data' => [
                        'bomItemId' => $feroxLine->getId()->toRfc4122(),
                        'bomItemName' => 'Ferox',
                        'characterName' => 'Sana Vale',
                        'quantity' => 0,
                        'released' => true,
                    ],
                ],
                [
                    'topic' => sprintf('/group-project/%s/events', $projectOfMaya->getId()->toRfc4122()),
                    'action' => 'line_reserved',
                    'data' => [
                        'bomItemId' => $nocxiumLine->getId()->toRfc4122(),
                        'bomItemName' => 'Nocxium',
                        'characterName' => 'Maya Orsk',
                        'quantity' => 0,
                        'released' => true,
                    ],
                ],
            ],
            $this->publishedEvents(),
        );
        self::assertSame(
            [['start', self::SYNC_KEY], ['complete', self::SYNC_KEY, '2 reservation(s) deleted']],
            $this->trackerCalls,
        );
    }

    public function testNothingIsDeletedNorPublishedWhenNoReservationHasExpired(): void
    {
        $this->reservationRepository->method('findExpired')->willReturn([]);

        $this->invokeHandler();

        self::assertSame([], $this->removed);
        self::assertSame([], $this->publishedUpdates);
        self::assertSame(
            [['start', self::SYNC_KEY], ['complete', self::SYNC_KEY, '0 reservation(s) deleted']],
            $this->trackerCalls,
        );
    }

    public function testExpiredReservationOfAnAnonymizedParticipantIsDeletedWithoutInventingACharacterName(): void
    {
        $project = $this->projectNamed('Sana');
        $line = $this->lineOf($project, self::FEROX_TYPE_ID, 'Ferox');
        $anonymizedParticipant = $this->participantOf($project, 'Kael Dray');
        $anonymizedParticipant->anonymize();
        $expired = new IndustryProjectLineReservation($line, $anonymizedParticipant, 4, new \DateTimeImmutable('-60 hours'));
        $this->reservationRepository->method('findExpired')->willReturn([$expired]);

        $this->invokeHandler();

        self::assertSame([$expired], $this->removed);
        $published = $this->publishedEvents();
        self::assertCount(1, $published);
        self::assertArrayHasKey('characterName', $published[0]['data']);
        self::assertNull($published[0]['data']['characterName']);
    }

    public function testFailureWhileReadingExpiredReservationsIsTrackedAndRethrown(): void
    {
        $this->reservationRepository->method('findExpired')->willThrowException(new \RuntimeException('database down'));

        $rethrown = null;
        try {
            $this->invokeHandler();
        } catch (\RuntimeException $failure) {
            $rethrown = $failure;
        }

        self::assertNotNull($rethrown);
        self::assertSame('database down', $rethrown->getMessage());
        self::assertSame([], $this->removed);
        self::assertSame([], $this->publishedUpdates);
        self::assertSame(
            [['start', self::SYNC_KEY], ['fail', self::SYNC_KEY, 'database down']],
            $this->trackerCalls,
        );
    }

    private function invokeHandler(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });

        $handler = new PurgeExpiredLineReservationsHandler(
            $this->reservationRepository,
            $this->entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            new NullLogger(),
            $this->trackerRecordingCalls(),
        );

        $handler(new PurgeExpiredLineReservations());
    }

    /** @return list<array{topic: string, action: string, data: array<string, mixed>}> */
    private function publishedEvents(): array
    {
        return array_map(
            static function (Update $update): array {
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

                return ['topic' => $update->getTopics()[0], 'action' => $payload['action'], 'data' => $payload['data']];
            },
            $this->publishedUpdates,
        );
    }

    private function trackerRecordingCalls(): SyncTracker
    {
        $tracker = $this->createStub(SyncTracker::class);
        $tracker->method('start')->willReturnCallback(function (string $type): void {
            $this->trackerCalls[] = ['start', $type];
        });
        $tracker->method('complete')->willReturnCallback(function (string $type, ?string $message = null): void {
            $this->trackerCalls[] = ['complete', $type, $message];
        });
        $tracker->method('fail')->willReturnCallback(function (string $type, string $message): void {
            $this->trackerCalls[] = ['fail', $type, $message];
        });

        return $tracker;
    }

    private function projectNamed(string $ownerFirstName): IndustryProject
    {
        $owner = $this->userWithMainCharacter($ownerFirstName.' Owner');

        return (new IndustryProject())->setOwner($owner);
    }

    private function lineOf(IndustryProject $project, int $typeId, string $typeName): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity(10);
        $project->addBomItem($line);

        return $line;
    }

    private function participantOf(IndustryProject $project, string $characterName): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())
            ->setUser($this->userWithMainCharacter($characterName))
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($participant);

        return $participant;
    }

    private function userWithMainCharacter(string $characterName): User
    {
        static $nextEveCharacterId = 2_130_100_000;
        $character = (new Character())->setEveCharacterId($nextEveCharacterId++)->setName($characterName);

        return (new User())->addCharacter($character)->setMainCharacter($character);
    }
}
