<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\InFlightMessageRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(InFlightMessageRegistry::class)]
#[Group('issue-838')]
class InFlightMessageRegistryTest extends TestCase
{
    private const START_TIME = 1_800_000_000;
    private const ENTRY_TTL_SECONDS = 86400;

    private MockClock $clock;
    private InFlightMessageRegistry $registry;

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . self::START_TIME);
        $this->registry = new InFlightMessageRegistry(new ArrayAdapter(clock: $this->clock), $this->clock);
    }

    public function testNothingIsRunningWhenNoMessageWasStarted(): void
    {
        $this->assertNull($this->registry->oldestRunningSeconds());
    }

    public function testAStartedMessageIsAgedInSecondsSinceItsStart(): void
    {
        $this->registry->start('on_demand', 'App\Message\SyncCharacterAssets');
        $this->clock->modify('@' . (self::START_TIME + 3601));

        $this->assertSame(3601, $this->registry->oldestRunningSeconds());
    }

    public function testFinishingAMessageClearsItsReceiver(): void
    {
        $this->registry->start('on_demand', 'App\Message\SyncCharacterAssets');
        $this->clock->modify('@' . (self::START_TIME + 120));

        $this->registry->finish('on_demand');

        $this->assertNull($this->registry->oldestRunningSeconds());
    }

    public function testTheOldestMessageAcrossReceiversIsReported(): void
    {
        $this->registry->start('on_demand', 'App\Message\SyncCorporationAssets');
        $this->clock->modify('@' . (self::START_TIME + 500));
        $this->registry->start('async', 'App\Message\SyncIndustryJobs');
        $this->clock->modify('@' . (self::START_TIME + 800));

        $this->assertSame(800, $this->registry->oldestRunningSeconds());

        $this->registry->finish('on_demand');

        $this->assertSame(300, $this->registry->oldestRunningSeconds());
    }

    public function testStartingAgainOnTheSameReceiverReplacesItsEntry(): void
    {
        $this->registry->start('on_demand', 'App\Message\SyncCharacterAssets');
        $this->clock->modify('@' . (self::START_TIME + 700));
        $this->registry->start('on_demand', 'App\Message\TriggerAssetsSync');
        $this->clock->modify('@' . (self::START_TIME + 710));

        $this->assertSame(10, $this->registry->oldestRunningSeconds());
    }

    public function testFinishingAReceiverWithoutEntryChangesNothing(): void
    {
        $this->registry->start('on_demand', 'App\Message\SyncCharacterAssets');
        $this->clock->modify('@' . (self::START_TIME + 60));

        $this->registry->finish('scheduler_default');

        $this->assertSame(60, $this->registry->oldestRunningSeconds());
    }

    public function testAnEntryExpiresAfterTwentyFourHours(): void
    {
        $this->registry->start('on_demand', 'App\Message\SyncCharacterAssets');

        $this->clock->modify('@' . (self::START_TIME + self::ENTRY_TTL_SECONDS - 1));
        $this->assertSame(self::ENTRY_TTL_SECONDS - 1, $this->registry->oldestRunningSeconds());

        $this->clock->modify('@' . (self::START_TIME + self::ENTRY_TTL_SECONDS + 1));
        $this->assertNull($this->registry->oldestRunningSeconds());
    }
}
