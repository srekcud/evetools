<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Service\ESI\EsiErrorBudgetBroadcaster;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Unit\Service\Mercure\RecordsAdminHealthUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EsiErrorBudgetBroadcaster::class)]
#[Group('issue-813')]
final class EsiErrorBudgetBroadcasterTest extends TestCase
{
    use RecordsAdminHealthUpdates;

    private const string START = '2026-11-13 10:30:00';
    private const int LIMITED_RESPONSES_IN_ONE_BURST = 131;
    private const float BURST_STEP_SECONDS = 0.03;

    private MockClock $clock;
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::START . ' UTC');
        $this->pool = new ArrayAdapter();
    }

    public function testTheThrottleWindowIsTenSeconds(): void
    {
        self::assertSame(10, EsiErrorBudgetBroadcaster::THROTTLE_SECONDS);
    }

    public function testHundredAndThirtyOneLimitedResponsesInUnderFiveSecondsPublishASingleSignal(): void
    {
        $broadcaster = $this->broadcaster();

        for ($response = 0; $response < self::LIMITED_RESPONSES_IN_ONE_BURST; ++$response) {
            $broadcaster->limitedResponseRecorded();
            $this->clock->sleep(self::BURST_STEP_SECONDS);
        }

        self::assertSame(['esi-error-budget'], $this->publishedScopes());
    }

    public function testALimitedResponseElevenSecondsAfterThePreviousSignalPublishesAgain(): void
    {
        $broadcaster = $this->broadcaster();

        $broadcaster->limitedResponseRecorded();
        $this->clock->sleep(11);
        $broadcaster->limitedResponseRecorded();

        self::assertSame(['esi-error-budget', 'esi-error-budget'], $this->publishedScopes());
    }

    public function testTwoWorkersSharingTheCacheShareTheSameThrottleWindow(): void
    {
        $firstWorker = $this->broadcaster();
        $secondWorker = $this->broadcaster();

        $firstWorker->limitedResponseRecorded();
        $this->clock->sleep(3);
        $secondWorker->limitedResponseRecorded();

        self::assertSame(['esi-error-budget'], $this->publishedScopes());
    }

    public function testTheFirstObservedLevelIsPublished(): void
    {
        $this->broadcaster()->levelObserved('ok');

        self::assertSame(['esi-error-budget'], $this->publishedScopes());
    }

    public function testALevelChangeFromOkToLowIsPublishedAtOnce(): void
    {
        $broadcaster = $this->broadcaster();

        $broadcaster->levelObserved('ok');
        $broadcaster->levelObserved('low');

        self::assertSame(['esi-error-budget', 'esi-error-budget'], $this->publishedScopes());
    }

    public function testTheSameLevelObservedAgainIsNotPublished(): void
    {
        $broadcaster = $this->broadcaster();

        $broadcaster->levelObserved('ok');
        $this->clock->sleep(60);
        $broadcaster->levelObserved('ok');

        self::assertSame(['esi-error-budget'], $this->publishedScopes());
    }

    public function testALevelChangeIsPublishedInsideTheWindowOfALimitedResponseSignal(): void
    {
        $broadcaster = $this->broadcaster();

        $broadcaster->limitedResponseRecorded();
        $this->clock->sleep(2);
        $broadcaster->levelObserved('critical');

        self::assertSame(['esi-error-budget', 'esi-error-budget'], $this->publishedScopes());
    }

    public function testALevelPublicationRestartsTheThrottleWindowOfLimitedResponses(): void
    {
        $broadcaster = $this->broadcaster();

        $broadcaster->limitedResponseRecorded();
        $this->clock->sleep(6);
        $broadcaster->levelObserved('low');
        $this->clock->sleep(6);
        $broadcaster->limitedResponseRecorded();

        self::assertSame(['esi-error-budget', 'esi-error-budget'], $this->publishedScopes());

        $this->clock->sleep(5);
        $broadcaster->limitedResponseRecorded();

        self::assertSame(['esi-error-budget', 'esi-error-budget', 'esi-error-budget'], $this->publishedScopes());
    }

    public function testAHubDownIsLoggedAsAWarningAndNeverRaised(): void
    {
        $broadcaster = $this->broadcaster(publisher: $this->adminHealthPublisher(new \RuntimeException('hub down')));

        $broadcaster->limitedResponseRecorded();
        $broadcaster->levelObserved('low');

        self::assertSame([], $this->publishedScopes());
        self::assertCount(2, $this->warningCollector()->warnings);
    }

    public function testACacheFailureIsLoggedAsAWarningAndNeverRaised(): void
    {
        $failingPool = $this->createStub(CacheItemPoolInterface::class);
        $failingPool->method('getItem')->willThrowException(new \RuntimeException('redis down'));
        $broadcaster = $this->broadcaster(pool: $failingPool);

        $broadcaster->limitedResponseRecorded();
        $broadcaster->levelObserved('low');

        self::assertCount(2, $this->warningCollector()->warnings);
    }

    private function broadcaster(?CacheItemPoolInterface $pool = null, ?MercurePublisherService $publisher = null): EsiErrorBudgetBroadcaster
    {
        return new EsiErrorBudgetBroadcaster(
            $pool ?? $this->pool,
            $publisher ?? $this->adminHealthPublisher(),
            $this->warningCollector(),
            $this->clock,
        );
    }
}
