<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSiteLedgerEtaUnknownReason;
use App\Enum\MiningSiteStatus;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningOperation\MinedSinceStartReader;

final readonly class MiningSiteLedgerEtaReader
{
    private const string PRESENT_TYPE = 'present';

    public function __construct(
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private MinedSinceStartReader $minedSinceStart,
        private LedgerSiteEtaEstimator $estimator,
    ) {
    }

    public function read(MiningSite $site): ?LedgerSiteEta
    {
        $operation = $site->getOperation();
        if (null === $operation || MiningSiteStatus::Active !== $site->getStatus() || 1 !== $this->scanRepository->countVisibleOf($site)) {
            return null;
        }
        $scan = $this->scanRepository->findLastOf($site);
        if (null === $scan) {
            return null;
        }

        if (MiningOperationStatus::InProgress !== $operation->getStatus()) {
            return $this->unknown(MiningSiteLedgerEtaUnknownReason::OperationNotRunning);
        }
        if (!$this->isFirstSiteOf($operation, $site)) {
            return $this->unknown(MiningSiteLedgerEtaUnknownReason::LaterSite);
        }

        $minedByTypeId = [];
        $ledgerFreshAt = null;
        foreach ($operation->acceptedMembers() as $member) {
            foreach ($member->getCharacters() as $enrolment) {
                $mined = $this->minedSinceStart->ofEnrolment($operation, $enrolment);
                if (null === $mined) {
                    return $this->unknown(MiningSiteLedgerEtaUnknownReason::LedgerUnknown);
                }
                foreach ($mined->quantitiesByTypeId as $typeId => $quantity) {
                    $minedByTypeId[$typeId] = ($minedByTypeId[$typeId] ?? 0) + $quantity;
                }
                if (null !== $mined->lastChangeAt && (null === $ledgerFreshAt || $mined->lastChangeAt > $ledgerFreshAt)) {
                    $ledgerFreshAt = $mined->lastChangeAt;
                }
            }
        }

        return $this->estimator->estimate(
            new SiteScanSample($scan->getScannedAt(), $this->presentUnitsOf($scan)),
            $minedByTypeId,
            $operation->getStartedAt() ?? throw new \LogicException('A running operation has a start date'),
            $ledgerFreshAt,
        );
    }

    private function unknown(MiningSiteLedgerEtaUnknownReason $reason): LedgerSiteEta
    {
        return new LedgerSiteEta(null, $reason);
    }

    private function isFirstSiteOf(MiningOperation $operation, MiningSite $site): bool
    {
        $sites = $this->siteRepository->findOfOperation($operation, null);

        return [] !== $sites && $sites[array_key_last($sites)] === $site;
    }

    /**
     * @return array<int, int>
     */
    private function presentUnitsOf(MiningSiteScan $scan): array
    {
        $units = [];
        foreach ($scan->getTypes() as $entry) {
            if (self::PRESENT_TYPE === $entry['status']) {
                $units[$entry['typeId']] = $entry['units'];
            }
        }

        return $units;
    }
}
