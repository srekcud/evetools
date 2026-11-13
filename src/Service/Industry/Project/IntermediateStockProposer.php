<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Enum\ProjectStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;

final readonly class IntermediateStockProposer
{
    private const string COMPONENT_JOB_GROUP = 'component';
    private const string REASON_PERSONAL_ASSETS_NOT_SYNCED = 'personal_assets_not_synced';

    public function __construct(
        private CachedAssetRepository $assetRepository,
        private ProjectContainerService $containerService,
        private IndustryProjectContributionRepository $contributionRepository,
    ) {
    }

    public function proposalOf(IndustryProject $project): IntermediateStockProposal
    {
        $owner = $project->getOwner();
        if (!$project->isSoloProjectOf($owner) || ProjectStatus::Completed === $project->getStatus()) {
            return new IntermediateStockProposal(IntermediateStockProposal::STATE_NOT_SOLO, null, []);
        }

        if (!$this->assetRepository->hasPersonalAssetsOf($owner)) {
            return $this->unknown(self::REASON_PERSONAL_ASSETS_NOT_SYNCED);
        }

        $containerQuantities = null;
        if ([] !== $project->getContainerNames()) {
            $containerStock = $this->containerService->containerStockOf($project);
            if (null !== $containerStock->unknownReason) {
                return $this->unknown($containerStock->unknownReason);
            }
            $containerQuantities = $containerStock->quantitiesByTypeId;
        }

        $personalQuantities = $this->assetRepository->getAggregatedQuantitiesByUser($owner);
        $approvedStock = $this->contributionRepository->findIntermediateStockByTypeId($project);
        $contributedBomItemIds = $this->contributionRepository->findBomItemIdsWithMaterialContributionOf($project, $owner);

        $bomLines = array_values($project->getBomItems()->toArray());
        $levels = ChainManufacturingLevels::byTypeId($bomLines);

        $lines = [];
        foreach ($this->rankZeroComponentLines($bomLines) as $bomLine) {
            $line = $this->lineOf($bomLine, $personalQuantities, $containerQuantities, $approvedStock, $contributedBomItemIds);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        usort($lines, static fn (IntermediateStockProposalLine $left, IntermediateStockProposalLine $right): int => [$levels[$right->typeId], $left->typeName] <=> [$levels[$left->typeId], $right->typeName]);

        return new IntermediateStockProposal(IntermediateStockProposal::STATE_READY, null, $lines);
    }

    /**
     * @param array<int, int>      $personalQuantities
     * @param array<int, int>|null $containerQuantities
     * @param array<int, int>      $approvedStock
     * @param list<string>         $contributedBomItemIds
     */
    private function lineOf(
        IndustryProjectBomLine $bomLine,
        array $personalQuantities,
        ?array $containerQuantities,
        array $approvedStock,
        array $contributedBomItemIds,
    ): ?IntermediateStockProposalLine {
        $remainingQuantity = $bomLine->getRemainingQuantity();
        $bomItemId = $bomLine->getId()->toRfc4122();
        if (0 === $remainingQuantity || $bomLine->isCoveredByStock() || \in_array($bomItemId, $contributedBomItemIds, true)) {
            return null;
        }

        $typeId = $bomLine->getTypeId();
        $personalQuantity = self::quantityOf($personalQuantities, $typeId);
        $containerQuantity = null === $containerQuantities ? null : self::quantityOf($containerQuantities, $typeId);
        $stockQuantity = max(0, $personalQuantity + (int) $containerQuantity - self::quantityOf($approvedStock, $typeId));
        $proposedQuantity = min($stockQuantity, $remainingQuantity);
        if (0 === $proposedQuantity) {
            return null;
        }

        return new IntermediateStockProposalLine(
            $bomItemId,
            $typeId,
            $bomLine->getTypeName(),
            $bomLine->getRequiredQuantity(),
            $remainingQuantity,
            $personalQuantity,
            $containerQuantity,
            $stockQuantity,
            $proposedQuantity,
        );
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     *
     * @return list<IndustryProjectBomLine>
     */
    private function rankZeroComponentLines(array $bomLines): array
    {
        $largestByTypeId = [];
        foreach ($bomLines as $bomLine) {
            if (!$bomLine->isJob() || self::COMPONENT_JOB_GROUP !== $bomLine->getJobGroup()) {
                continue;
            }

            $typeId = $bomLine->getTypeId();
            if (!isset($largestByTypeId[$typeId]) || $bomLine->getRequiredQuantity() > $largestByTypeId[$typeId]->getRequiredQuantity()) {
                $largestByTypeId[$typeId] = $bomLine;
            }
        }

        return array_values($largestByTypeId);
    }

    /**
     * @param array<int, int> $quantitiesByTypeId
     */
    private static function quantityOf(array $quantitiesByTypeId, int $typeId): int
    {
        return \array_key_exists($typeId, $quantitiesByTypeId) ? $quantitiesByTypeId[$typeId] : 0;
    }

    private function unknown(string $reason): IntermediateStockProposal
    {
        return new IntermediateStockProposal(IntermediateStockProposal::STATE_ASSETS_UNKNOWN, $reason, []);
    }
}
