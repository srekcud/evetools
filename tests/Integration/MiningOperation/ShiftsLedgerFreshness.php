<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningOperation;

use App\Entity\MiningEntry;

trait ShiftsLedgerFreshness
{
    private const string STALE_SYNC_AT = '2026-11-10T20:30:00+00:00';

    /**
     * @param array<string, string> $freshAtByCharacterName
     */
    private function freshenLedgerEntries(array $freshAtByCharacterName): void
    {
        foreach ($this->em->getRepository(MiningEntry::class)->findAll() as $entry) {
            $countedDay = self::HAULER_PLAN_LEDGER_DAY === $entry->getDate()->format('Y-m-d') && self::ALDRANETTE === $entry->getSolarSystemId();
            $freshAt = $countedDay ? ($freshAtByCharacterName[$entry->getCharacterName()] ?? self::STALE_SYNC_AT) : self::STALE_SYNC_AT;
            $entry->setSyncedAt(new \DateTimeImmutable($freshAt));
        }
        $this->em->flush();
    }
}
