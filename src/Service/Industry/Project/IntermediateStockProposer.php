<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Stock\PersonalStockReader;

final readonly class IntermediateStockProposer
{
    private const string COMPONENT_JOB_GROUP = 'component';
    private const string REASON_PERSONAL_ASSETS_NOT_SYNCED = 'personal_assets_not_synced';

    public function __construct(
        private CachedAssetRepository $assetRepository,
        private ProjectContainerService $containerService,
        private IndustryProjectContributionRepository $contributionRepository,
        private ChainRecipeIngredients $recipeIngredients,
        private PersonalStockReader $personalStock,
        private IndustryStructureConfigRepository $structureConfigRepository,
    ) {
    }

    public function proposalOf(IndustryProject $project, User $reader): IntermediateStockProposal
    {
        if (ProjectStatus::Completed === $project->getStatus()) {
            return new IntermediateStockProposal(IntermediateStockProposal::STATE_NOT_SOLO, null, []);
        }

        if (!$this->assetRepository->hasPersonalAssetsOf($reader)) {
            return $this->unknown(self::REASON_PERSONAL_ASSETS_NOT_SYNCED);
        }

        $personalStock = $this->personalStock->of($reader);
        if (null !== $personalStock->unknownReason()) {
            return $this->unknown($personalStock->unknownReason());
        }

        $containerQuantities = null;
        if ($project->isSoloProjectOf($reader) && [] !== $project->getContainerNames()) {
            $containerStock = $this->containerService->containerStockOf($project);
            if (null !== $containerStock->unknownReason) {
                return $this->unknown($containerStock->unknownReason);
            }
            $containerQuantities = $containerStock->quantitiesByTypeId;
        }

        $personalQuantities = $personalStock->quantitiesByTypeId;
        $approvedStock = $this->contributionRepository->findIntermediateStockByTypeId($project, $reader);
        $contributedBomItemIds = $this->contributionRepository->findBomItemIdsWithMaterialContributionOf($project, $reader);

        $bomLines = array_values($project->getBomItems()->toArray());
        $levels = ChainManufacturingLevels::byTypeId(
            $bomLines,
            $this->recipeIngredients->producedIngredientsOf(ChainManufacturingLevels::jobTypeIdsOf($bomLines)),
        );

        $ownerStructuresById = [];
        foreach ($this->structureConfigRepository->findByUser($project->getOwner()) as $structure) {
            $ownerStructuresById[$structure->getId()->toRfc4122()] = $structure;
        }

        $lines = [];
        foreach ($this->rankZeroComponentLines($bomLines) as $bomLine) {
            $line = $this->lineOf($bomLine, $personalQuantities, $personalStock->locationsByTypeId, $ownerStructuresById, $containerQuantities, $approvedStock, $contributedBomItemIds);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        usort($lines, static fn (IntermediateStockProposalLine $left, IntermediateStockProposalLine $right): int => [$levels[$right->typeId], $left->typeName] <=> [$levels[$left->typeId], $right->typeName]);

        return new IntermediateStockProposal(IntermediateStockProposal::STATE_READY, null, $lines);
    }

    /**
     * @param array<int, int>      $personalQuantities
     * @param array<int, array{total: int, locations: list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}>}> $personalLocations
     * @param array<string, IndustryStructureConfig> $ownerStructuresById
     * @param array<int, int>|null $containerQuantities
     * @param array<int, int>      $approvedStock
     * @param list<string>         $contributedBomItemIds
     */
    private function lineOf(
        IndustryProjectBomLine $bomLine,
        array $personalQuantities,
        array $personalLocations,
        array $ownerStructuresById,
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

        $plannedStructure = self::plannedStationOf($bomLine, $ownerStructuresById);

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
            self::stockByStationOf(\array_key_exists($typeId, $personalLocations) ? $personalLocations[$typeId]['locations'] : [], $plannedStructure?->getLocationId()),
            $plannedStructure?->getName(),
        );
    }

    /**
     * @param array<string, IndustryStructureConfig> $ownerStructuresById
     */
    private static function plannedStationOf(IndustryProjectBomLine $bomLine, array $ownerStructuresById): ?IndustryStructureConfig
    {
        $plannedStructureId = $bomLine->getPlannedStructureId();
        if (null === $plannedStructureId || !isset($ownerStructuresById[$plannedStructureId])) {
            return null;
        }

        $plannedStructure = $ownerStructuresById[$plannedStructureId];

        return null === $plannedStructure->getLocationId() ? null : $plannedStructure;
    }

    /**
     * @param list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}> $locations
     *
     * @return list<IntermediateStockStationQuantity>
     */
    private static function stockByStationOf(array $locations, ?int $plannedLocationId): array
    {
        $stations = array_map(
            static fn (array $location): IntermediateStockStationQuantity => new IntermediateStockStationQuantity(
                $location['locationId'],
                $location['locationName'],
                $location['systemName'],
                $location['quantity'],
                null === $plannedLocationId ? null : $location['locationId'] === $plannedLocationId,
            ),
            $locations,
        );

        if (null !== $plannedLocationId) {
            usort($stations, static fn (IntermediateStockStationQuantity $left, IntermediateStockStationQuantity $right): int => (int) $right->onSite <=> (int) $left->onSite);
        }

        return $stations;
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
