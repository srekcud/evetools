<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\CheckAlertPrices;
use App\Message\CheckCourierContracts;
use App\Message\CheckMiningSiteDepletionAlerts;
use App\Message\CheckMoonChunkAlerts;
use App\Message\PurgeExpiredSharedLists;
use App\Message\PurgeOldMarketHistory;
use App\Message\PurgeOldNotifications;
use App\Message\SyncAdjustedPrices;
use App\Message\SyncBlueprints;
use App\Message\SyncCharacterOrders;
use App\Message\SyncCostIndices;
use App\Message\SyncIndustryJobs;
use App\Message\SyncPublicContracts;
use App\Message\SyncRegionalMarketVolumes;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerCorporationMiningExtractionsSync;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningOperationRecurrences;
use App\Message\TriggerMiningSync;
use App\Message\TriggerMoonTaxSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;
use App\Scheduler\SyncScheduler;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * Issue #31 : le schedule et `SyncTracker::EXPECTED_INTERVALS` (santé du dashboard admin)
 * doivent décrire les mêmes syncs récurrentes, aux mêmes intervalles.
 */
#[CoversClass(SyncScheduler::class)]
#[CoversClass(SyncTracker::class)]
class SyncSchedulerIntervalsTest extends TestCase
{
    /**
     * Message planifié → clé passée à `SyncTracker::start()` par son handler
     * (directement, ou via le message qu'il dispatche).
     */
    private const SYNC_TRACKER_KEY_BY_SCHEDULED_MESSAGE = [
        TriggerAnsiblexSync::class => 'ansiblex',
        TriggerStructureMarketSync::class => 'market-structure',
        TriggerJitaMarketSync::class => 'market-jita',
        TriggerPveSync::class => 'pve',
        SyncIndustryJobs::class => 'industry',
        TriggerMiningSync::class => 'mining',
        SyncWalletTransactions::class => 'wallet',
        TriggerPlanetarySync::class => 'planetary', // via SyncPlanetaryColoniesHandler
        CheckAlertPrices::class => 'alert-prices',
        SyncAdjustedPrices::class => 'adjusted-prices',
        SyncCostIndices::class => 'cost-indices',
        SyncPublicContracts::class => 'public-contracts',
        TriggerCorporationMiningExtractionsSync::class => 'corporation-mining-extractions',
        TriggerMoonTaxSync::class => 'moon-tax',
        SyncBlueprints::class => 'blueprints',
        SyncCharacterOrders::class => 'character-orders',
        SyncRegionalMarketVolumes::class => 'market-volumes',
        CheckCourierContracts::class => 'courier-contracts',
        TriggerMiningOperationRecurrences::class => 'mining-operation-recurrences',
        CheckMoonChunkAlerts::class => 'moon-chunk-alerts',
        CheckMiningSiteDepletionAlerts::class => 'mining-site-depletion-alerts',
        PurgeOldNotifications::class => 'notifications-purge',
        PurgeOldMarketHistory::class => 'market-history-purge',
        PurgeExpiredSharedLists::class => 'shared-lists-purge',
    ];

    private const DAILY_PURGE_MESSAGES = [
        PurgeOldNotifications::class,
        PurgeOldMarketHistory::class,
        PurgeExpiredSharedLists::class,
    ];

    private const FIVE_MINUTES_IN_SECONDS = 300;

    private const THIRTY_MINUTES_IN_SECONDS = 1800;

    private const ONE_HOUR_IN_SECONDS = 3600;

    private const ONE_DAY_IN_SECONDS = 86400;

    public function testEveryScheduledSyncIsTrackedWithTheSameIntervalAndNothingElseIsTracked(): void
    {
        $scheduledIntervalsBySyncTrackerKey = $this->scheduledIntervalsBySyncTrackerKey();

        $expectedIntervals = SyncTracker::EXPECTED_INTERVALS;
        ksort($expectedIntervals);

        $this->assertSame($scheduledIntervalsBySyncTrackerKey, $expectedIntervals);
    }

    public function testScheduledSyncIntervalsInSeconds(): void
    {
        // Fige les intervalles actuels du schedule : toute modification doit être
        // répercutée dans SyncTracker::EXPECTED_INTERVALS (et CLAUDE.md).
        $this->assertSame([
            'adjusted-prices' => 86400,
            'alert-prices' => 1800,
            'ansiblex' => 43200,
            'blueprints' => 3600,
            'character-orders' => 300,
            'corporation-mining-extractions' => 1800,
            'cost-indices' => 7200,
            'courier-contracts' => 1800,
            'industry' => 1800,
            'market-history-purge' => 86400,
            'market-jita' => 3600,
            'market-structure' => 3600,
            'market-volumes' => 86400,
            'mining' => 3600,
            'mining-operation-recurrences' => 1800,
            'mining-site-depletion-alerts' => 300,
            'moon-chunk-alerts' => 300,
            'moon-tax' => 3600,
            'notifications-purge' => 86400,
            'planetary' => 1800,
            'public-contracts' => 1800,
            'pve' => 3600,
            'shared-lists-purge' => 86400,
            'wallet' => 3600,
        ], $this->scheduledIntervalsBySyncTrackerKey());
    }

    public function testHousekeepingPurgesAreScheduledDaily(): void
    {
        // Issue #34 : la purge des listes partagées expirées rejoint les purges quotidiennes.
        $expectedDailyPurges = array_fill_keys(self::DAILY_PURGE_MESSAGES, self::ONE_DAY_IN_SECONDS);
        ksort($expectedDailyPurges);

        $scheduledPurges = array_intersect_key(
            $this->scheduledIntervalsByMessageClass(),
            $expectedDailyPurges,
        );

        $this->assertSame($expectedDailyPurges, $scheduledPurges);
    }

    #[Group('issue-310')]
    public function testMiningOperationRecurrencesAreTriggeredEveryThirtyMinutes(): void
    {
        $this->assertSame(
            self::THIRTY_MINUTES_IN_SECONDS,
            $this->scheduledIntervalsByMessageClass()[TriggerMiningOperationRecurrences::class] ?? null,
        );
    }

    #[Group('issue-274')]
    public function testMoonChunkAlertsAreCheckedEveryFiveMinutes(): void
    {
        $this->assertSame(
            self::FIVE_MINUTES_IN_SECONDS,
            $this->scheduledIntervalsByMessageClass()[CheckMoonChunkAlerts::class] ?? null,
        );
    }

    #[Group('issue-271')]
    public function testMiningSiteDepletionAlertsAreCheckedEveryFiveMinutes(): void
    {
        $this->assertSame(
            self::FIVE_MINUTES_IN_SECONDS,
            $this->scheduledIntervalsByMessageClass()[CheckMiningSiteDepletionAlerts::class] ?? null,
        );
    }

    #[Group('issue-273')]
    public function testMoonTaxSyncIsTriggeredEveryHour(): void
    {
        $this->assertSame(
            self::ONE_HOUR_IN_SECONDS,
            $this->scheduledIntervalsByMessageClass()[TriggerMoonTaxSync::class] ?? null,
        );
    }

    /** @return array<class-string, int> */
    private function scheduledIntervalsByMessageClass(): array
    {
        $referenceRun = new \DateTimeImmutable('2026-10-07 12:00:00');
        $intervals = [];

        foreach ($this->buildSyncScheduler()->getSchedule()->getRecurringMessages() as $recurringMessage) {
            $context = new MessageContext(
                'default',
                $recurringMessage->getId(),
                $recurringMessage->getTrigger(),
                $referenceRun,
            );

            foreach ($recurringMessage->getMessages($context) as $message) {
                $nextRun = $recurringMessage->getTrigger()->getNextRunDate($referenceRun);
                $this->assertNotNull($nextRun);

                $intervals[$message::class] = $nextRun->getTimestamp() - $referenceRun->getTimestamp();
            }
        }

        ksort($intervals);

        return $intervals;
    }

    /** @return array<string, int> */
    private function scheduledIntervalsBySyncTrackerKey(): array
    {
        $referenceRun = new \DateTimeImmutable('2026-10-07 12:00:00');
        $intervals = [];

        foreach ($this->buildSyncScheduler()->getSchedule()->getRecurringMessages() as $recurringMessage) {
            $context = new MessageContext(
                'default',
                $recurringMessage->getId(),
                $recurringMessage->getTrigger(),
                $referenceRun,
            );

            foreach ($recurringMessage->getMessages($context) as $message) {
                $this->assertArrayHasKey(
                    $message::class,
                    self::SYNC_TRACKER_KEY_BY_SCHEDULED_MESSAGE,
                    \sprintf('Message planifié %s sans clé SyncTracker connue.', $message::class),
                );

                // Le trigger n'a pas encore de date de départ : le premier run sert de référence.
                $nextRun = $recurringMessage->getTrigger()->getNextRunDate($referenceRun);
                $this->assertNotNull($nextRun);

                $intervals[self::SYNC_TRACKER_KEY_BY_SCHEDULED_MESSAGE[$message::class]]
                    = $nextRun->getTimestamp() - $referenceRun->getTimestamp();
            }
        }

        ksort($intervals);

        return $intervals;
    }

    private function buildSyncScheduler(): SyncScheduler
    {
        // TRANSITOIRE (issue #40) : le LockFactory n'est passé que si symfony/lock est installé,
        // pour que ce test reste indépendant du fix du lock. Retirer la condition après le fix.
        $lockFactoryArguments = class_exists(LockFactory::class) ? [new LockFactory(new InMemoryStore())] : [];

        return new SyncScheduler(new ArrayAdapter(), ...$lockFactoryArguments);
    }
}
