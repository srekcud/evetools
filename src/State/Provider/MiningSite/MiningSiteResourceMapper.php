<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use App\ApiResource\MiningSite\MiningSiteResource;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\MiningSite\MiningSiteHistorySummarizer;

final readonly class MiningSiteResourceMapper
{
    public function __construct(
        private MiningSiteScanRepository $scanRepository,
        private MiningSiteHistorySummarizer $summarizer,
    ) {
    }

    public function siteToResource(MiningSite $site): MiningSiteResource
    {
        $resource = new MiningSiteResource();
        $resource->id = PersistedUuid::of($site->getId());
        $resource->label = $site->getLabel();
        $resource->solarSystemId = $site->getSolarSystemId();
        $resource->status = $site->getStatus()->value;
        $resource->createdAt = $this->inEveTime($site->getCreatedAt());

        $first = $this->scanRepository->findFirstOf($site);
        $last = $this->scanRepository->findLastOf($site);
        if ($first === null || $last === null) {
            return $resource;
        }

        $summary = $this->summarizer->summarize($first, $last);
        $resource->firstScanAt = $this->inEveTime($first->getScannedAt());
        $resource->lastScanAt = $this->inEveTime($last->getScannedAt());
        $resource->observedSeconds = $summary->observedSeconds;
        $resource->firstVolumeM3 = $summary->firstVolumeM3;
        $resource->firstValue = $summary->firstValue;
        $resource->averageTrendRate = $summary->averageTrendRate;
        $resource->remainingValue = $last->getRemainingValue();
        $resource->missingPriceTypeIds = $last->getMissingPriceTypeIds();
        $resource->recentEta = $this->inEveTimeOrNull($last->getRecentEta());
        $resource->trendEta = $this->inEveTimeOrNull($last->getTrendEta());

        return $resource;
    }

    public function scanToResource(MiningSiteScan $scan): MiningSiteScanResource
    {
        $resource = new MiningSiteScanResource();
        $resource->id = PersistedUuid::of($scan->getId());
        $resource->site = new MiningSiteResource();
        $resource->site->id = PersistedUuid::of($scan->getSite()->getId());
        $resource->scannedAt = $this->inEveTime($scan->getScannedAt());
        $resource->totalUnits = $scan->getTotalUnits();
        $resource->totalVolumeM3 = $scan->getTotalVolumeM3();
        $resource->remainingValue = $scan->getRemainingValue();
        $resource->missingPriceTypeIds = $scan->getMissingPriceTypeIds();
        $resource->recentEta = $this->inEveTimeOrNull($scan->getRecentEta());
        $resource->trendEta = $this->inEveTimeOrNull($scan->getTrendEta());
        $resource->types = $scan->getTypes();

        return $resource;
    }

    private function inEveTime(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }

    private function inEveTimeOrNull(?\DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        return $moment === null ? null : $this->inEveTime($moment);
    }
}
