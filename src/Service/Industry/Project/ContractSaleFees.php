<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectSale;
use App\Repository\CachedWalletJournalEntryRepository;

final readonly class ContractSaleFees
{
    public function __construct(
        private CachedWalletJournalEntryRepository $journalRepository,
    ) {
    }

    /**
     * @param iterable<IndustryProjectSale> $sales
     *
     * @return array<int, float> fees read in the wallet journal, per contract, for the contracts the journal knows
     */
    public function knownFeesByContractId(iterable $sales): array
    {
        $contractIds = [];
        foreach ($sales as $sale) {
            if ($sale->getContractId() !== null) {
                $contractIds[] = $sale->getContractId();
            }
        }

        return $this->journalRepository->contractFeesByContractId($contractIds);
    }

    /**
     * @param iterable<IndustryProjectSale> $sales
     */
    public function totalOf(iterable $sales): ?float
    {
        $feesByContractId = $this->knownFeesByContractId($sales);

        $total = 0.0;
        foreach ($sales as $sale) {
            $contractId = $sale->getContractId();
            if ($contractId === null) {
                continue;
            }

            if (!isset($feesByContractId[$contractId])) {
                return null;
            }
            $total += $feesByContractId[$contractId];
        }

        return $total;
    }
}
