<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Contract;

final readonly class ContractSaleCandidatesRead
{
    /**
     * @param list<ContractSaleCandidate> $candidates
     * @param array<int, string> $unreadableReasonByEveCharacterId
     */
    public function __construct(
        public array $candidates,
        public array $unreadableReasonByEveCharacterId,
    ) {
    }

    public function candidateOf(int $contractId, int $sellerEveCharacterId): ?ContractSaleCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->contractId === $contractId && $candidate->sellerEveCharacterId === $sellerEveCharacterId) {
                return $candidate;
            }
        }

        return null;
    }
}
