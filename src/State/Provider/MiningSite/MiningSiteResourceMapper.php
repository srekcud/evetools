<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use App\ApiResource\MiningSite\MiningSiteResource;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Repository\MiningSiteScanRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\MiningSite\MiningSiteHistorySummarizer;

final readonly class MiningSiteResourceMapper
{
    private const string PRESENT_TYPE = 'present';

    public function __construct(
        private MiningSiteScanRepository $scanRepository,
        private MiningSiteHistorySummarizer $summarizer,
        private MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function siteToResource(MiningSite $site): MiningSiteResource
    {
        $resource = new MiningSiteResource();
        $resource->id = PersistedUuid::of($site->getId());
        $resource->operationId = $site->getOperation() === null ? null : PersistedUuid::of($site->getOperation()->getId());
        $resource->label = $site->getLabel();
        $resource->solarSystemId = $site->getSolarSystemId();
        $resource->solarSystemName = $this->solarSystemNameOf($site);
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
        $resource->lastScanNumber = $this->scanRepository->countUpTo($site, $last->getScannedAt());
        $resource->lastScanAuthorName = $this->authorNameOf($last);

        return $resource;
    }

    public function scanToResource(MiningSiteScan $scan): MiningSiteScanResource
    {
        return $this->numberedScanToResource($scan, $this->scanRepository->countUpTo($scan->getSite(), $scan->getScannedAt()));
    }

    /**
     * @param list<MiningSiteScan> $chronologicalScans
     *
     * @return list<MiningSiteScanResource>
     */
    public function scansToResources(array $chronologicalScans): array
    {
        $resources = [];
        foreach ($chronologicalScans as $index => $scan) {
            $resources[] = $this->numberedScanToResource($scan, $index + 1);
        }

        return $resources;
    }

    private function numberedScanToResource(MiningSiteScan $scan, int $number): MiningSiteScanResource
    {
        $resource = new MiningSiteScanResource();
        $resource->id = PersistedUuid::of($scan->getId());
        $resource->number = $number;
        $resource->authorName = $this->authorNameOf($scan);
        $resource->typeCount = \count(array_filter($scan->getTypes(), static fn (array $entry): bool => $entry['status'] === self::PRESENT_TYPE));
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

    private function solarSystemNameOf(MiningSite $site): ?string
    {
        $solarSystemId = $site->getSolarSystemId();

        return $solarSystemId === null ? null : $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName();
    }

    private function authorNameOf(MiningSiteScan $scan): ?string
    {
        return $scan->getAuthor()?->getMainCharacter()?->getName();
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
