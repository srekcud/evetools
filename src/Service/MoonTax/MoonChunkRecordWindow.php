<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonObserverRecord;

final readonly class MoonChunkRecordWindow
{
    private const string DAY_FORMAT = 'Y-m-d';

    /**
     * @param list<CorporationMiningExtraction> $chunksOfRefinery
     * @param list<MoonObserverRecord>          $records
     *
     * @return list<MoonObserverRecord>
     */
    public function recordsOf(CorporationMiningExtraction $chunk, array $chunksOfRefinery, array $records): array
    {
        return array_values(array_filter(
            $records,
            fn (MoonObserverRecord $record): bool => $this->ownerOf($record->getLastUpdated()->format(self::DAY_FORMAT), $chunksOfRefinery) === $chunk,
        ));
    }

    /**
     * @param list<CorporationMiningExtraction> $chunksOfRefinery
     */
    private function ownerOf(string $day, array $chunksOfRefinery): ?CorporationMiningExtraction
    {
        $owner = null;
        foreach ($chunksOfRefinery as $candidate) {
            if ($day < $this->dayOf($candidate->getChunkArrivalTime()) || $day > $this->dayOf($candidate->getNaturalDecayTime())) {
                continue;
            }
            if ($owner === null || $candidate->getChunkArrivalTime() > $owner->getChunkArrivalTime()) {
                $owner = $candidate;
            }
        }

        return $owner;
    }

    private function dayOf(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(self::DAY_FORMAT);
    }
}
