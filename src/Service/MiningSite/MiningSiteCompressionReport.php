<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSite;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningOperation\MiningOperationCompressionReport;

final readonly class MiningSiteCompressionReport
{
    private const string PRESENT_TYPE = 'present';

    public function __construct(
        private MiningSiteScanRepository $scanRepository,
        private MiningOperationCompressionReport $operationReport,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function of(MiningSite $site): ?array
    {
        $operation = $site->getOperation();
        if ($operation === null) {
            return null;
        }

        return $this->operationReport->of($operation, $this->presentTypeIdsOfLastScan($site));
    }

    /**
     * @return list<int>
     */
    private function presentTypeIdsOfLastScan(MiningSite $site): array
    {
        $lastScan = $this->scanRepository->findLastOf($site);
        if ($lastScan === null) {
            return [];
        }

        $typeIds = [];
        foreach ($lastScan->getTypes() as $entry) {
            if ($entry['status'] === self::PRESENT_TYPE) {
                $typeIds[$entry['typeId']] = $entry['typeId'];
            }
        }
        sort($typeIds);

        return $typeIds;
    }
}
