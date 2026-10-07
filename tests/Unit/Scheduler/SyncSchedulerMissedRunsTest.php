<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\CheckAlertPrices;
use App\Message\SyncCostIndices;
use App\Message\SyncIndustryJobs;
use App\Message\SyncPublicContracts;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerPveSync;
use App\Message\TriggerStructureMarketSync;
use App\Scheduler\SyncScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

/**
 * Après une indisponibilité du worker, le scheduler ne doit pas rejouer chaque période
 * manquée (10 syncs industrie d'affilée après 5 h d'arrêt) : seule la dernière est émise.
 */
#[CoversClass(SyncScheduler::class)]
class SyncSchedulerMissedRunsTest extends TestCase
{
    private const SCHEDULE_NAME = 'default';

    public function testOnlyLastMissedRunOfEachRecurringMessageIsEmittedAfterDowntime(): void
    {
        $sharedCache = new ArrayAdapter();
        $clock = new MockClock('2026-10-07 12:00:00');

        $workerBeforeDowntime = new MessageGenerator(new SyncScheduler($sharedCache, new LockFactory(new InMemoryStore())), self::SCHEDULE_NAME, $clock);
        // Initialise le checkpoint, rien n'est encore dû.
        $this->assertSame([], $this->drainMessageClasses($workerBeforeDowntime->getMessages()));

        // 5 h d'arrêt : 10 périodes de 30 min, 5 d'1 h et 2 de 2 h manquées ; 12 h / 24 h pas encore dues.
        $clock->modify('+5 hours +1 second');

        // Le worker redémarré retrouve le checkpoint dans le cache partagé ; son ancien lock
        // a disparu avec le processus (flock), d'où un store de lock neuf.
        $workerAfterDowntime = new MessageGenerator(new SyncScheduler($sharedCache, new LockFactory(new InMemoryStore())), self::SCHEDULE_NAME, $clock);

        $this->assertSame(
            [
                SyncIndustryJobs::class,
                TriggerPlanetarySync::class,
                CheckAlertPrices::class,
                SyncPublicContracts::class,
                TriggerStructureMarketSync::class,
                TriggerJitaMarketSync::class,
                TriggerPveSync::class,
                TriggerMiningSync::class,
                SyncWalletTransactions::class,
                SyncCostIndices::class,
            ],
            $this->drainMessageClasses($workerAfterDowntime->getMessages()),
        );
    }

    /**
     * @param \Generator<mixed, object> $messages
     *
     * @return list<class-string>
     */
    private function drainMessageClasses(\Generator $messages): array
    {
        $classes = [];
        while ($messages->valid()) {
            $classes[] = $messages->current()::class;
            $messages->next();
        }

        return $classes;
    }
}
