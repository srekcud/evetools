<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;

final readonly class OwnStockSupplyGuard
{
    public function __construct(
        private IntermediateStockProposer $proposer,
    ) {
    }

    public function assertWithin(IndustryProjectBomLine $line, User $contributor, int $quantity): void
    {
        $proposal = $this->proposer->proposalOf($line->getProject(), $contributor);
        if (ProjectStatus::Selling === $line->getProject()->getStatus()) {
            throw new \DomainException('The project is selling: its stock can no longer be supplied.');
        }

        if (IntermediateStockProposal::STATE_NOT_SOLO === $proposal->state) {
            throw new \DomainException('The project is completed: nothing can be supplied.');
        }

        if (IntermediateStockProposal::STATE_READY !== $proposal->state) {
            throw new \DomainException(\sprintf('Your own stock cannot be read (%s): it cannot be supplied.', $proposal->unknownReason ?? $proposal->state));
        }

        $lineId = $line->getId()->toRfc4122();
        $proposedLine = null;
        foreach ($proposal->lines as $candidate) {
            if ($candidate->bomItemId === $lineId) {
                $proposedLine = $candidate;
                break;
            }
        }

        if (null === $proposedLine) {
            throw new \DomainException('This line cannot be supplied from your own stock.');
        }

        $availableQuantity = $proposedLine->proposedQuantity;
        if ($quantity > $availableQuantity) {
            throw new \DomainException(\sprintf('The quantity exceeds your own stock available for this line (%d).', $availableQuantity));
        }
    }
}
