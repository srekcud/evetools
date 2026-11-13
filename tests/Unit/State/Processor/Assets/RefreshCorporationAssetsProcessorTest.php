<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Assets;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncCorporationAssets;
use App\Repository\CachedAssetRepository;
use App\Service\Sync\AssetsSyncService;
use App\Service\Sync\CorporationAssetsRefreshGate;
use App\State\Processor\Assets\RefreshCorporationAssetsProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RefreshCorporationAssetsProcessor::class)]
#[Group('issue-668')]
final class RefreshCorporationAssetsProcessorTest extends TestCase
{
    private const string REQUESTER_ID = '0199a1f0-0000-7000-8000-000000668001';
    private const string DIRECTOR_CHARACTER_ID = '0199a1f0-0000-7000-8000-000000668002';
    private const int CORPORATION_ID = 98_000_001;
    private const string NOW = '2026-11-13 12:10:00';

    public function testAsyncRefreshDispatchesTheSyncWithTheRequestingUserAndTheSyncCharacter(): void
    {
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $this->processor($messageBus)->process(null, new Post());

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(SyncCorporationAssets::class, $dispatched[0]);
        self::assertSame(
            [
                'corporationId' => self::CORPORATION_ID,
                'triggerCharacterId' => self::DIRECTOR_CHARACTER_ID,
                'requestedByUserId' => self::REQUESTER_ID,
            ],
            get_object_vars($dispatched[0]),
        );
    }

    #[Group('issue-729')]
    public function testRefreshWithoutAConnectedDirectorDispatchesNothingAndSaysSo(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $result = $this->processor($messageBus, false)->process(null, new Post());

        self::assertSame(['error', false], [$result->status, $result->hasAccess]);
        self::assertStringContainsString('No Director connected', (string) $result->error);
        self::assertStringNotContainsString('Access forbidden', (string) $result->error);
    }

    #[Group('issue-865')]
    public function testRefreshWithinTheEsiCacheDurationDispatchesNothingAndGivesTheNextRefreshTime(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $result = $this->processor($messageBus, true, new \DateTimeImmutable('2026-11-13 12:00:00'))->process(null, new Post());

        self::assertSame('up_to_date', $result->status);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 12:00:00'), new \DateTimeImmutable((string) $result->syncedAt));
        self::assertEquals(new \DateTimeImmutable('2026-11-13 13:00:00'), new \DateTimeImmutable((string) $result->nextRefreshAt));
        self::assertNotSame('', $result->message);
    }

    #[Group('issue-865')]
    public function testSynchronousRefreshWithinTheEsiCacheDurationSyncsNothing(): void
    {
        $assetsSyncService = $this->createMock(AssetsSyncService::class);
        $assetsSyncService->method('getCorpAssetsCharacter')->willReturn($this->directorCharacter());
        $assetsSyncService->expects(self::never())->method('syncCorporationAssetsForCorp');

        $result = $this->processor(
            $this->createStub(MessageBusInterface::class),
            true,
            new \DateTimeImmutable('2026-11-13 12:00:00'),
            $assetsSyncService,
            $this->requestStackWithAsyncFlag('false'),
        )->process(null, new Post());

        self::assertSame('up_to_date', $result->status);
    }

    #[Group('issue-865')]
    public function testRefreshExactlyOneCacheDurationAfterTheLastSyncDispatchesTheSync(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        $result = $this->processor($messageBus, true, new \DateTimeImmutable('2026-11-13 11:10:00'))->process(null, new Post());

        self::assertSame('pending', $result->status);
        self::assertNull($result->nextRefreshAt);
    }

    #[Group('issue-865')]
    public function testSynchronousRefreshAfterTheCacheDurationStillRunsTheSync(): void
    {
        $assetsSyncService = $this->createMock(AssetsSyncService::class);
        $assetsSyncService->method('getCorpAssetsCharacter')->willReturn($this->directorCharacter());
        $assetsSyncService->expects(self::once())->method('syncCorporationAssetsForCorp')->with(self::CORPORATION_ID)->willReturn(true);

        $result = $this->processor(
            $this->createStub(MessageBusInterface::class),
            true,
            new \DateTimeImmutable('2026-11-13 09:00:00'),
            $assetsSyncService,
            $this->requestStackWithAsyncFlag('false'),
        )->process(null, new Post());

        self::assertSame('completed', $result->status);
    }

    #[Group('issue-865')]
    public function testRefreshWhileASyncOfTheCorporationIsInFlightDispatchesNothing(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');
        $processor = $this->processor($messageBus);
        $this->refreshGate->claim(self::CORPORATION_ID);

        $result = $processor->process(null, new Post());

        self::assertSame('in_progress', $result->status);
        self::assertNotSame('', $result->message);
        self::assertNull($result->error);
    }

    #[Group('issue-865')]
    public function testTwoRefreshesInARowDispatchASingleSync(): void
    {
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );
        $processor = $this->processor($messageBus);

        $first = $processor->process(null, new Post());
        $second = $processor->process(null, new Post());

        self::assertSame(['pending', 'in_progress'], [$first->status, $second->status]);
        self::assertCount(1, $dispatched);
    }

    #[Group('issue-865')]
    public function testDispatchedRefreshHoldsTheInFlightMarkerUntilTheHandlerReleasesIt(): void
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $processor = $this->processor($messageBus);

        $processor->process(null, new Post());

        self::assertFalse($this->refreshGate->claim(self::CORPORATION_ID));
    }

    #[Group('issue-865')]
    public function testFailedDispatchReleasesTheInFlightMarkerAndRethrows(): void
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new \RuntimeException('broker down'));
        $processor = $this->processor($messageBus);

        try {
            $processor->process(null, new Post());
            self::fail('the dispatch failure must reach the caller');
        } catch (\RuntimeException $exception) {
            self::assertSame('broker down', $exception->getMessage());
        }

        self::assertTrue($this->refreshGate->claim(self::CORPORATION_ID));
    }

    #[Group('issue-865')]
    public function testMissingDirectorStillWinsOverTheCooldown(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $result = $this->processor($messageBus, false, new \DateTimeImmutable('2026-11-13 12:00:00'))->process(null, new Post());

        self::assertSame(['error', false], [$result->status, $result->hasAccess]);
    }

    private CorporationAssetsRefreshGate $refreshGate;

    private function directorCharacter(): Character
    {
        $directorCharacter = new Character();
        (new \ReflectionProperty($directorCharacter, 'id'))->setValue($directorCharacter, Uuid::fromString(self::DIRECTOR_CHARACTER_ID));

        return $directorCharacter;
    }

    private function requestStackWithAsyncFlag(string $async): RequestStack
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['async' => $async]));

        return $requestStack;
    }

    private function processor(
        MessageBusInterface $messageBus,
        bool $withSyncCharacter = true,
        ?\DateTimeImmutable $lastSyncedAt = null,
        ?AssetsSyncService $assetsSyncService = null,
        ?RequestStack $requestStack = null,
    ): RefreshCorporationAssetsProcessor {
        $requester = new User();
        (new \ReflectionProperty($requester, 'id'))->setValue($requester, Uuid::fromString(self::REQUESTER_ID));
        $mainCharacter = (new Character())->setCorporationId(self::CORPORATION_ID);
        $requester->setMainCharacter($mainCharacter);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($requester);

        if ($assetsSyncService === null) {
            $assetsSyncService = $this->createStub(AssetsSyncService::class);
            $assetsSyncService->method('getCorpAssetsCharacter')->willReturn($withSyncCharacter ? $this->directorCharacter() : null);
        }
        if (!$withSyncCharacter) {
            $assetsSyncService = $this->createStub(AssetsSyncService::class);
            $assetsSyncService->method('getCorpAssetsCharacter')->willReturn(null);
        }

        $clock = new MockClock(self::NOW);
        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('lastCachedAtOfCorporationAssets')->willReturn($lastSyncedAt);
        $this->refreshGate = new CorporationAssetsRefreshGate($cachedAssetRepository, new ArrayAdapter(clock: $clock), $clock);

        return new RefreshCorporationAssetsProcessor(
            $security,
            $assetsSyncService,
            $this->refreshGate,
            $messageBus,
            $requestStack ?? new RequestStack(),
            new NullLogger(),
        );
    }
}
