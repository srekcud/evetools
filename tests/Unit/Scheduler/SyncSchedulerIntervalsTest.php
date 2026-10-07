<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\CheckAlertPrices;
use App\Message\PurgeExpiredSharedLists;
use App\Message\PurgeOldMarketHistory;
use App\Message\PurgeOldNotifications;
use App\Message\SyncAdjustedPrices;
use App\Message\SyncCostIndices;
use App\Message\SyncIndustryJobs;
use App\Message\SyncPublicContracts;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;
use App\Scheduler\SyncScheduler;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\CoversClass;
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
    ];

    /** Maintenance planifiée dont les handlers n'utilisent pas le SyncTracker. */
    private const UNTRACKED_HOUSEKEEPING_MESSAGES = [
        PurgeOldNotifications::class,
        PurgeOldMarketHistory::class,
        PurgeExpiredSharedLists::class,
    ];

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
            'cost-indices' => 7200,
            'industry' => 1800,
            'market-jita' => 3600,
            'market-structure' => 3600,
            'mining' => 3600,
            'planetary' => 1800,
            'public-contracts' => 1800,
            'pve' => 3600,
            'wallet' => 3600,
        ], $this->scheduledIntervalsBySyncTrackerKey());
    }

    public function testHousekeepingPurgesAreScheduledDaily(): void
    {
        // Issue #34 : la purge des listes partagées expirées rejoint les purges quotidiennes.
        $expectedDailyPurges = array_fill_keys(self::UNTRACKED_HOUSEKEEPING_MESSAGES, self::ONE_DAY_IN_SECONDS);
        ksort($expectedDailyPurges);

        $scheduledPurges = array_intersect_key(
            $this->scheduledIntervalsByMessageClass(),
            $expectedDailyPurges,
        );

        $this->assertSame($expectedDailyPurges, $scheduledPurges);
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
                if (\in_array($message::class, self::UNTRACKED_HOUSEKEEPING_MESSAGES, true)) {
                    continue;
                }

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
