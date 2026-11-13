<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Notification;
use App\Entity\SdeBuildState;
use App\Entity\User;
use App\Message\CheckSdeBuild;
use App\MessageHandler\CheckSdeBuildHandler;
use App\Repository\SdeBuildStateRepository;
use App\Repository\UserRepository;
use App\Security\AdminChecker;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sde\SdeBuildStatusResolver;
use App\Service\Sde\SdeLatestBuildFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(CheckSdeBuildHandler::class)]
#[Group('issue-848')]
final class CheckSdeBuildHandlerTest extends TestCase
{
    private const OLD_IMPORTED_BUILD = 3213160;
    private const ANNOUNCED_BUILD = 3693310;
    private const NEWER_BUILD = 3700000;
    private const ADMIN_CHARACTER_IDS = '2113848001, 2113848002';

    private SdeBuildState $state;

    private array $trackerCalls = [];

    private array $dispatched = [];

    private array $publishedUpdates = [];

    private array $adminLookups = [];

    private int $saveCount = 0;

    private User $firstAdmin;
    private User $secondAdmin;

    protected function setUp(): void
    {
        $this->state = new SdeBuildState();
        $this->trackerCalls = [];
        $this->dispatched = [];
        $this->publishedUpdates = [];
        $this->adminLookups = [];
        $this->saveCount = 0;
        $this->firstAdmin = new User();
        $this->secondAdmin = new User();
    }

    public function testNotifiesEveryAdminOnceWhenCcpPublishedANewerBuild(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin, $this->secondAdmin]);

        $handler(new CheckSdeBuild());

        self::assertSame([$this->firstAdmin, $this->secondAdmin], array_column($this->dispatched, 'user'));
        foreach ($this->dispatched as $notification) {
            self::assertSame('sde_build', $notification['category']);
            self::assertSame(Notification::LEVEL_WARNING, $notification['level']);
            self::assertSame('/admin', $notification['route']);
            self::assertStringContainsString((string) self::ANNOUNCED_BUILD, $notification['message']);
            self::assertSame([
                'importedBuildNumber' => self::OLD_IMPORTED_BUILD,
                'latestBuildNumber' => self::ANNOUNCED_BUILD,
                'latestReleaseDate' => '2026-11-12T11:07:00+00:00',
            ], $notification['data']);
        }
        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getNotifiedBuildNumber());
    }

    public function testStoresTheLatestBuildAndTheCheckTime(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin]);

        $handler(new CheckSdeBuild());

        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getLatestBuildNumber());
        self::assertSame('2026-11-12T11:07:00+00:00', $this->state->getLatestReleaseDate()?->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-11-13T15:30:00+00:00', $this->state->getLatestCheckedAt()?->format(\DateTimeInterface::ATOM));
        self::assertGreaterThanOrEqual(1, $this->saveCount);
    }

    public function testDoesNotNotifyAgainForABuildAlreadyNotified(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD)->setNotifiedBuildNumber(self::ANNOUNCED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin, $this->secondAdmin]);

        $handler(new CheckSdeBuild());

        self::assertSame([], $this->dispatched);
        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getNotifiedBuildNumber());
    }

    public function testTwoConsecutiveChecksOfTheSameBuildNotifyOnlyOnce(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin, $this->secondAdmin]);

        $handler(new CheckSdeBuild());
        $handler(new CheckSdeBuild());

        self::assertCount(2, $this->dispatched);
    }

    public function testNotifiesAgainWhenCcpPublishesAnotherBuildAfterTheNotifiedOne(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD)->setNotifiedBuildNumber(self::ANNOUNCED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::NEWER_BUILD), [$this->firstAdmin, $this->secondAdmin]);

        $handler(new CheckSdeBuild());

        self::assertCount(2, $this->dispatched);
        self::assertSame(self::NEWER_BUILD, $this->dispatched[0]['data']['latestBuildNumber']);
        self::assertSame(self::NEWER_BUILD, $this->state->getNotifiedBuildNumber());
    }

    public function testNotifiesWhenNoBuildWasEverRecordedAtImport(): void
    {
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin]);

        $handler(new CheckSdeBuild());

        self::assertCount(1, $this->dispatched);
        self::assertNull($this->dispatched[0]['data']['importedBuildNumber']);
        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getNotifiedBuildNumber());
    }

    public function testNeverNotifiesWhenTheImportedBuildIsTheLatestOne(): void
    {
        $this->state->setImportedBuildNumber(self::ANNOUNCED_BUILD)->setNotifiedBuildNumber(3000000);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin, $this->secondAdmin]);

        $handler(new CheckSdeBuild());

        self::assertSame([], $this->dispatched);
        self::assertSame(3000000, $this->state->getNotifiedBuildNumber());
        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getLatestBuildNumber());
    }

    public function testRecordsTheBuildAsNotifiedEvenWhenNoAdminIsConfigured(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), []);

        $handler(new CheckSdeBuild());

        self::assertSame([], $this->dispatched);
        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getNotifiedBuildNumber());
    }

    public function testLooksTheAdminsUpByTheListedMainCharacterIds(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin]);

        $handler(new CheckSdeBuild());

        self::assertSame([[2113848001, 2113848002]], $this->adminLookups);
    }

    public function testTracksTheSuccessfulCheckAndPublishesTheAdminHealthSignal(): void
    {
        $this->state->setImportedBuildNumber(self::OLD_IMPORTED_BUILD);
        $handler = $this->handlerReading($this->latestResponse(self::ANNOUNCED_BUILD), [$this->firstAdmin]);

        $handler(new CheckSdeBuild());

        self::assertSame(['start', 'complete'], array_column($this->trackerCalls, 0));
        self::assertSame(['sde-build', 'sde-build'], array_column($this->trackerCalls, 1));
        self::assertSame(['sde-build'], $this->publishedAdminHealthScopes());
    }

    public function testKeepsThePreviousLatestBuildAndFailsTheTaskWhenTheLatestBuildCannotBeRead(): void
    {
        $this->state
            ->setImportedBuildNumber(self::OLD_IMPORTED_BUILD)
            ->setLatestBuildNumber(self::ANNOUNCED_BUILD)
            ->setLatestReleaseDate(new \DateTimeImmutable('2026-11-12T11:07:00Z'))
            ->setNotifiedBuildNumber(self::ANNOUNCED_BUILD);
        $handler = $this->handlerReading(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]), [$this->firstAdmin]);

        $failure = null;
        try {
            $handler(new CheckSdeBuild());
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'The failure must be rethrown');
        self::assertStringContainsString('latest', $failure->getMessage());
        self::assertSame(self::ANNOUNCED_BUILD, $this->state->getLatestBuildNumber());
        self::assertSame('2026-11-12T11:07:00+00:00', $this->state->getLatestReleaseDate()?->format(\DateTimeInterface::ATOM));
        self::assertSame(['start', 'fail'], array_column($this->trackerCalls, 0));
        self::assertSame($failure->getMessage(), $this->trackerCalls[1][2]);
        self::assertSame([], $this->dispatched);
        self::assertSame(['sde-build'], $this->publishedAdminHealthScopes());
    }

    public function testDoesNotNotifyWhenTheLatestBuildCannotBeReadEvenIfTheKnownOneIsOutdated(): void
    {
        $this->state
            ->setImportedBuildNumber(self::OLD_IMPORTED_BUILD)
            ->setLatestBuildNumber(self::ANNOUNCED_BUILD);
        $handler = $this->handlerReading(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']), [$this->firstAdmin]);

        try {
            $handler(new CheckSdeBuild());
            self::fail('The failure must be rethrown');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->dispatched);
        self::assertNull($this->state->getNotifiedBuildNumber());
    }

    private function handlerReading(\Closure $latestResponse, array $admins): CheckSdeBuildHandler
    {
        $stateRepository = $this->createStub(SdeBuildStateRepository::class);
        $stateRepository->method('current')->willReturn($this->state);
        $stateRepository->method('save')->willReturnCallback(function (SdeBuildState $saved): void {
            self::assertSame($this->state, $saved);
            ++$this->saveCount;
        });

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findAdminsByMainCharacterEveIds')->willReturnCallback(function (array $eveCharacterIds) use ($admins): array {
            $this->adminLookups[] = $eveCharacterIds;

            return $admins;
        });

        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null): void {
            $this->dispatched[] = compact('user', 'category', 'level', 'title', 'message', 'data', 'route');
        });

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

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'id';
        });

        return new CheckSdeBuildHandler(
            new SdeLatestBuildFetcher(new MockHttpClient($latestResponse)),
            $stateRepository,
            new SdeBuildStatusResolver(),
            $userRepository,
            new AdminChecker(self::ADMIN_CHARACTER_IDS),
            $dispatcher,
            $tracker,
            new MercurePublisherService($hub, new NullLogger()),
            new MockClock('2026-11-13 15:30:00 UTC'),
        );
    }

    private function latestResponse(int $buildNumber): \Closure
    {
        return static fn (): MockResponse => new MockResponse(json_encode(['_key' => 'sde', 'buildNumber' => $buildNumber, 'releaseDate' => '2026-11-12T11:07:00Z'], JSON_THROW_ON_ERROR));
    }

    private function publishedAdminHealthScopes(): array
    {
        return array_map(
            static fn (Update $update): string => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['adminHealth'],
            $this->publishedUpdates,
        );
    }
}
