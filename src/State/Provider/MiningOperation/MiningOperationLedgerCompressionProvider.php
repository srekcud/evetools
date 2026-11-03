<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationLedgerResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationCompressionReport;
use App\Service\MiningOperation\MiningOperationRequest;

/**
 * @implements ProviderInterface<MiningOperationLedgerResource>
 */
final readonly class MiningOperationLedgerCompressionProvider implements ProviderInterface
{
    public function __construct(
        private MiningOperationLedgerProvider $ledgerProvider,
        private MiningOperationRepository $operationRepository,
        private MiningOperationCompressionReport $compressionReport,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationLedgerResource
    {
        $ledger = $this->ledgerProvider->provide($operation, $uriVariables, $context);
        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $ledger->compression = $this->compressionReport->of($miningOperation, $this->minedTypeIdsOf($ledger));

        return $ledger;
    }

    /**
     * @return list<int>
     */
    private function minedTypeIdsOf(MiningOperationLedgerResource $ledger): array
    {
        $typeIds = [];
        foreach ($ledger->participants as $participant) {
            foreach ($participant['characters'] as $character) {
                foreach ($character['quantities'] as $quantity) {
                    if ($quantity['quantity'] > 0) {
                        $typeIds[$quantity['typeId']] = $quantity['typeId'];
                    }
                }
            }
        }
        sort($typeIds);

        return $typeIds;
    }
}
