<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\DataFreshness;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Admin\SyncTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\ItemInterface;

#[Group('issue-236')]
final class DataFreshnessApiTest extends WebTestCase
{
    private const int EVE_CHARACTER_ID = 2_112_236_001;
    private const string ESI_FAILURE_MESSAGE = 'ESI 502 Bad Gateway from tranquility-edge-7f3a';
    private const int STATE_TTL = 86400 * 7;

    private KernelBrowser $client;
    private SyncTracker $syncTracker;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->syncTracker = self::getContainer()->get(SyncTracker::class);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Ostrava Kell')
            ->setCorporationId(98_236_001)
            ->setCorporationName('Kell Price Watchers');
        $this->pilot = new User();
        $this->pilot->addCharacter($character);
        $this->pilot->setMainCharacter($character);
        $em->persist($this->pilot);
        $em->persist($character);
        $em->flush();
    }

    public function testAnonymousCallIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/data-freshness', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testGlobalSyncsNeverRunAreListedInOrderWithoutKnownSuccess(): void
    {
        self::assertSame(
            [
                ['source' => 'market-jita', 'lastSuccessAt' => null, 'expectedInterval' => 3600, 'lastRunFailed' => false],
                ['source' => 'cost-indices', 'lastSuccessAt' => null, 'expectedInterval' => 7200, 'lastRunFailed' => false],
                ['source' => 'adjusted-prices', 'lastSuccessAt' => null, 'expectedInterval' => 86400, 'lastRunFailed' => false],
            ],
            $this->fetchFreshness(),
        );
    }

    public function testExpectedIntervalOfEachSourceIsTheScheduledIntervalOfItsSync(): void
    {
        self::assertSame(
            [
                SyncTracker::EXPECTED_INTERVALS['market-jita'],
                SyncTracker::EXPECTED_INTERVALS['cost-indices'],
                SyncTracker::EXPECTED_INTERVALS['adjusted-prices'],
            ],
            array_column($this->fetchFreshness(), 'expectedInterval'),
        );
    }

    public function testCompletedSyncGivesItsCompletionTimeInIso8601(): void
    {
        $before = \time();
        $this->syncTracker->complete('market-jita', '4210 prices refreshed');
        $after = \time();

        $lastSuccessAt = $this->fetchFreshness()[0]['lastSuccessAt'];

        self::assertIsString($lastSuccessAt);
        $parsed = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $lastSuccessAt);
        self::assertNotFalse($parsed);
        self::assertSame($lastSuccessAt, $parsed->format('c'));
        self::assertGreaterThanOrEqual($before, $parsed->getTimestamp());
        self::assertLessThanOrEqual($after, $parsed->getTimestamp());
    }

    public function testFailureAfterASuccessFlagsTheLastRunFailedAndKeepsTheLastSuccess(): void
    {
        $this->syncTracker->complete('market-jita');
        $lastSuccessAt = $this->fetchFreshness()[0]['lastSuccessAt'];
        self::assertNotNull($lastSuccessAt);

        $this->syncTracker->start('market-jita');
        $this->syncTracker->fail('market-jita', self::ESI_FAILURE_MESSAGE);

        self::assertSame(
            [
                ['source' => 'market-jita', 'lastSuccessAt' => $lastSuccessAt, 'expectedInterval' => 3600, 'lastRunFailed' => true],
                ['source' => 'cost-indices', 'lastSuccessAt' => null, 'expectedInterval' => 7200, 'lastRunFailed' => false],
                ['source' => 'adjusted-prices', 'lastSuccessAt' => null, 'expectedInterval' => 86400, 'lastRunFailed' => false],
            ],
            $this->fetchFreshness(),
        );
    }

    public function testSuccessAfterAFailureNoLongerFlagsTheLastRunFailed(): void
    {
        $this->syncTracker->complete('cost-indices');
        $this->syncTracker->fail('cost-indices', self::ESI_FAILURE_MESSAGE);
        $before = \time();
        $this->syncTracker->complete('cost-indices');
        $after = \time();

        $costIndices = $this->fetchFreshness()[1];

        self::assertSame(['cost-indices', false], [$costIndices['source'], $costIndices['lastRunFailed']]);
        self::assertIsString($costIndices['lastSuccessAt']);
        $timestamp = (new \DateTimeImmutable($costIndices['lastSuccessAt']))->getTimestamp();
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);
    }

    public function testRunningSyncWithoutPreviousSuccessHasNoKnownSuccessAndNoFailedRun(): void
    {
        $this->syncTracker->start('adjusted-prices');

        self::assertSame(
            ['source' => 'adjusted-prices', 'lastSuccessAt' => null, 'expectedInterval' => 86400, 'lastRunFailed' => false],
            $this->fetchFreshness()[2],
        );
    }

    public function testRunningSyncAfterASuccessKeepsTheLastSuccess(): void
    {
        $this->syncTracker->complete('market-jita');
        $lastSuccessAt = $this->fetchFreshness()[0]['lastSuccessAt'];
        self::assertNotNull($lastSuccessAt);

        $this->syncTracker->start('market-jita');

        self::assertSame(
            ['source' => 'market-jita', 'lastSuccessAt' => $lastSuccessAt, 'expectedInterval' => 3600, 'lastRunFailed' => false],
            $this->fetchFreshness()[0],
        );
    }

    public function testFirstRunFailingHasNoKnownSuccessAndFlagsTheLastRunFailed(): void
    {
        $this->syncTracker->fail('adjusted-prices', self::ESI_FAILURE_MESSAGE);

        self::assertSame(
            ['source' => 'adjusted-prices', 'lastSuccessAt' => null, 'expectedInterval' => 86400, 'lastRunFailed' => true],
            $this->fetchFreshness()[2],
        );
    }

    public function testOkStateWrittenBeforeTheLastSuccessfulSyncWasRecordedGivesItsCompletionTime(): void
    {
        $this->writeStateWithoutLastSuccess('sync_tracker_market_jita', 'ok', '2026-10-31T09:00:00+00:00');

        self::assertSame(
            ['source' => 'market-jita', 'lastSuccessAt' => '2026-10-31T09:00:00+00:00', 'expectedInterval' => 3600, 'lastRunFailed' => false],
            $this->fetchFreshness()[0],
        );
    }

    public function testErrorStateWrittenBeforeTheLastSuccessfulSyncWasRecordedHasNoKnownSuccess(): void
    {
        $this->writeStateWithoutLastSuccess('sync_tracker_cost_indices', 'error', '2026-10-31T09:00:00+00:00');

        self::assertSame(
            ['source' => 'cost-indices', 'lastSuccessAt' => null, 'expectedInterval' => 7200, 'lastRunFailed' => true],
            $this->fetchFreshness()[1],
        );
    }

    public function testEachEntryCarriesOnlyItsFourFieldsAndNeverTheFailureMessage(): void
    {
        $this->syncTracker->start('market-jita');
        $this->syncTracker->fail('market-jita', self::ESI_FAILURE_MESSAGE);
        $this->syncTracker->complete('cost-indices', '312 solar systems refreshed');

        $freshness = $this->fetchFreshness();

        self::assertSame(
            array_fill(0, 3, ['source', 'lastSuccessAt', 'expectedInterval', 'lastRunFailed']),
            array_map(static fn (array $entry): array => array_keys($entry), $freshness),
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('tranquility-edge-7f3a', $body);
        self::assertStringNotContainsString('312 solar systems refreshed', $body);
        self::assertStringNotContainsString('started', $body);
        self::assertStringNotContainsString('status', $body);
        self::assertStringNotContainsString('message', $body);
    }

    private function writeStateWithoutLastSuccess(string $stateKey, string $status, string $completedAt): void
    {
        self::getContainer()->get('cache.app')->get(
            $stateKey,
            static function (ItemInterface $item) use ($status, $completedAt): array {
                $item->expiresAfter(self::STATE_TTL);

                return ['started_at' => '2026-10-31T08:55:00+00:00', 'completed_at' => $completedAt, 'status' => $status, 'message' => null];
            },
        );
    }

    private function fetchFreshness(): array
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/data-freshness', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
