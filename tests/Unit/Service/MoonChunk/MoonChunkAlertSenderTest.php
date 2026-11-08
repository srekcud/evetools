<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonChunkAlert;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\MoonChunkAlertKind;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonChunkAlertRepository;
use App\Service\MoonChunk\MoonChunkAlertSender;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MoonChunkAlertSender::class)]
#[Group('issue-274')]
final class MoonChunkAlertSenderTest extends TestCase
{
    use StoresMoonRefineryFollows;

    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    private const string ROCK_HOLLOW_NAME = 'NOL-M9 - Rock Hollow';
    private const int ROCK_HOLLOW_MOON_ID = 40_301_001;
    private const string ROCK_HOLLOW_MOON_NAME = 'NOL-M9 VII - Moon 3';
    private const int DEEP_CORE_ID = 1_035_000_000_102;
    private const int DEEP_CORE_MOON_ID = 40_302_002;
    private const string ROCK_HOLLOW_CHUNK_ID = '0195f3a0-2740-7000-8000-000000000501';
    private const string DEEP_CORE_CHUNK_ID = '0195f3a0-2740-7000-8000-000000000502';
    private const string ARRIVAL = '2026-11-05T20:00:00+00:00';
    private const string DECAY = '2026-11-05T23:00:00+00:00';
    private const string FOLLOWED_THE_DAY_BEFORE = '2026-11-04T12:00:00+00:00';
    private const string ALERTS_ROUTE = '/ledger?tab=mining&view=moonChunks';

    private MockClock $clock;
    private array $storedChunks = [];
    private array $recordedAlerts = [];
    private array $dispatched = [];
    private MoonChunkAlertSender $sender;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-05 18:59:00', 'UTC');
        $extractionRepository = $this->createStub(CorporationMiningExtractionRepository::class);
        $extractionRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->storedChunks,
                static fn (CorporationMiningExtraction $chunk): bool => $chunk->getCorporationId() === $criteria['corporationId'] && $chunk->getStructureId() === $criteria['structureId'],
            )),
        );
        $alertRepository = $this->createStub(MoonChunkAlertRepository::class);
        $alertRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?MoonChunkAlert => array_values(array_filter(
                $this->recordedAlerts,
                static fn (MoonChunkAlert $alert): bool => $alert->getUser() === $criteria['user'] && $alert->getExtraction() === $criteria['extraction'] && $alert->getKind() === $criteria['kind'],
            ))[0] ?? null,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MoonChunkAlert && !in_array($entity, $this->recordedAlerts, true)) {
                $this->recordedAlerts[] = $entity;
            }
        });
        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null): void {
                $this->dispatched[] = ['user' => $user, 'category' => $category, 'level' => $level, 'text' => $title . ' ' . $message, 'data' => $data, 'route' => $route];
            },
        );
        $this->sender = new MoonChunkAlertSender($this->followRepository(), $extractionRepository, $alertRepository, $dispatcher, $entityManager, $this->clock);
    }

    public function testNothingIsSentOneMinuteBeforeTheHourThatPrecedesTheArrival(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $this->storeRockHollowChunk();

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->recordedAlerts);
    }

    public function testSoonAlertIsSentOnceAtOneHourBeforeTheArrivalAndRecorded(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $chunk = $this->storeRockHollowChunk();
        $this->clock->modify('2026-11-05 19:00:00');

        $this->sender->sendDueAlerts();
        $this->clock->modify('2026-11-05 19:05:00');
        $this->sender->sendDueAlerts();

        self::assertCount(1, $this->dispatched);
        $notification = $this->dispatched[0];
        self::assertSame(
            [$ilyra, Notification::CATEGORY_MOON_CHUNK, 'info', self::ALERTS_ROUTE, ['extractionId' => self::ROCK_HOLLOW_CHUNK_ID, 'structureId' => self::ROCK_HOLLOW_ID, 'kind' => 'soon']],
            [$notification['user'], $notification['category'], $notification['level'], $notification['route'], $notification['data']],
        );
        foreach ([self::ROCK_HOLLOW_MOON_NAME, self::ROCK_HOLLOW_NAME, '20:00'] as $expectedText) {
            self::assertStringContainsString($expectedText, $notification['text']);
        }
        self::assertCount(1, $this->recordedAlerts);
        self::assertSame(
            [$ilyra, $chunk, MoonChunkAlertKind::Soon, '2026-11-05T19:00:00+00:00'],
            [$this->recordedAlerts[0]->getUser(), $this->recordedAlerts[0]->getExtraction(), $this->recordedAlerts[0]->getKind(), $this->recordedAlerts[0]->getSentAt()->format(\DateTimeInterface::ATOM)],
        );
    }

    public function testArrivedAlertAtTheArrivalNamesTheMoonAndTheRefineryByIdWhenTheyHaveNoNameAndTheSoonAlertIsNotReplayed(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::DEEP_CORE_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $this->storeChunk(self::CORPORATION_ID, self::DEEP_CORE_ID, self::DEEP_CORE_MOON_ID, null, null, self::DEEP_CORE_CHUNK_ID);
        $this->clock->modify('2026-11-05 20:00:00');

        $this->sender->sendDueAlerts();

        self::assertCount(1, $this->dispatched);
        $notification = $this->dispatched[0];
        self::assertSame(
            ['success', ['extractionId' => self::DEEP_CORE_CHUNK_ID, 'structureId' => self::DEEP_CORE_ID, 'kind' => 'arrived']],
            [$notification['level'], $notification['data']],
        );
        self::assertStringContainsString((string) self::DEEP_CORE_MOON_ID, $notification['text']);
        self::assertStringContainsString((string) self::DEEP_CORE_ID, $notification['text']);
        self::assertSame([MoonChunkAlertKind::Arrived], array_map(static fn (MoonChunkAlert $alert): MoonChunkAlertKind => $alert->getKind(), $this->recordedAlerts));
    }

    public function testRefineryFollowedAfterTheSoonInstantOnlyGetsTheArrivedAlert(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, '2026-11-05T19:30:00+00:00');
        $this->storeRockHollowChunk();
        $this->clock->modify('2026-11-05 19:45:00');
        $this->sender->sendDueAlerts();
        $sentBeforeTheArrival = $this->dispatched;
        $this->clock->modify('2026-11-05 20:00:00');

        $this->sender->sendDueAlerts();

        self::assertSame([], $sentBeforeTheArrival);
        self::assertSame(['arrived'], array_map(static fn (array $notification): string => $notification['data']['kind'], $this->dispatched));
    }

    public function testNothingIsSentOnceTheChunkHasExploded(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $this->storeRockHollowChunk();
        $this->clock->modify('2026-11-05 23:00:00');

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->recordedAlerts);
    }

    public function testRefineryFollowedAfterTheChunkArrivedGetsNothingForThatChunk(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, '2026-11-05T21:00:00+00:00');
        $this->storeRockHollowChunk();
        $this->clock->modify('2026-11-05 21:30:00');

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->recordedAlerts);
    }

    public function testFollowerWhoLeftTheCorporationReceivesNothingWhileTheOneWhoStayedIsAlerted(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $sora = $this->followerInCorporation(self::OTHER_CORPORATION_ID, 'Sora Vantel');
        $this->storeFollow($sora, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $this->storeRockHollowChunk();
        $this->clock->modify('2026-11-05 20:00:00');

        $this->sender->sendDueAlerts();

        self::assertSame([$ilyra], array_column($this->dispatched, 'user'));
        self::assertSame([$ilyra], array_map(static fn (MoonChunkAlert $alert): User => $alert->getUser(), $this->recordedAlerts));
    }

    public function testChunkOfARefineryTheUserDoesNotFollowSendsNothing(): void
    {
        $ilyra = $this->followerInCorporation(self::CORPORATION_ID, 'Ilyra Castell');
        $this->storeFollow($ilyra, self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::FOLLOWED_THE_DAY_BEFORE);
        $this->storeChunk(self::CORPORATION_ID, self::DEEP_CORE_ID, self::DEEP_CORE_MOON_ID, 'Deep Core Tatara', 'Ihakana II - Moon 1', self::DEEP_CORE_CHUNK_ID);
        $this->clock->modify('2026-11-05 20:00:00');

        $this->sender->sendDueAlerts();

        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->recordedAlerts);
    }

    private function storeRockHollowChunk(): CorporationMiningExtraction
    {
        return $this->storeChunk(self::CORPORATION_ID, self::ROCK_HOLLOW_ID, self::ROCK_HOLLOW_MOON_ID, self::ROCK_HOLLOW_NAME, self::ROCK_HOLLOW_MOON_NAME, self::ROCK_HOLLOW_CHUNK_ID);
    }

    private function storeChunk(int $corporationId, int $structureId, int $moonId, ?string $structureName, ?string $moonName, string $id): CorporationMiningExtraction
    {
        $chunk = (new CorporationMiningExtraction())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setMoonId($moonId)
            ->setExtractionStartTime(new \DateTimeImmutable('2026-11-02T18:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable(self::ARRIVAL))
            ->setNaturalDecayTime(new \DateTimeImmutable(self::DECAY))
            ->setStructureName($structureName)
            ->setMoonName($moonName);
        (new \ReflectionProperty(CorporationMiningExtraction::class, 'id'))->setValue($chunk, Uuid::fromString($id));
        $this->storedChunks[] = $chunk;

        return $chunk;
    }
}
