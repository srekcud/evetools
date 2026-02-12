<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerAssetsSync;
use App\Message\SyncIndustryJobs;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerMiningSync;
use App\Message\TriggerPlanetarySync;
use App\Message\TriggerPveSync;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerStructureMarketSync;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
class SyncScheduler implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            // Ansiblex sync every 12 hours
            ->add(
                RecurringMessage::every('12 hours', new TriggerAnsiblexSync())
            )
            // Structure market sync every 2 hours (C-J6MT Keepstar)
            ->add(
                RecurringMessage::every('2 hours', new TriggerStructureMarketSync())
            )
            // Jita market sync every 2 hours
            ->add(
                RecurringMessage::every('2 hours', new TriggerJitaMarketSync())
            )
            // PVE data sync every hour
            ->add(
                RecurringMessage::every('1 hour', new TriggerPveSync())
            )
            // Industry jobs sync every 30 minutes
            ->add(
                RecurringMessage::every('30 minutes', new SyncIndustryJobs())
            )
            // Mining ledger sync every hour
            ->add(
                RecurringMessage::every('1 hour', new TriggerMiningSync())
            )
            // Wallet transactions sync every hour
            ->add(
                RecurringMessage::every('1 hour', new SyncWalletTransactions())
            )
            // Planetary colonies sync every 30 minutes
            ->add(
                RecurringMessage::every('30 minutes', new TriggerPlanetarySync())
            );
    }
}
