<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\OwnedBlueprint;
use App\Enum\ProjectStatus;
use App\Repository\OwnedBlueprintRepository;
use App\Repository\Sde\IndustryActivityProductRepository;

class T2BlueprintCopyProposer
{
    private const string REASON_BLUEPRINTS_NOT_SYNCED = 'blueprints_not_synced';

    public function __construct(
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly ProjectContainerService $containerService,
        private readonly InventedProductsReader $inventedProductsReader,
        private readonly IndustryActivityProductRepository $activityProductRepository,
    ) {
    }

    public function proposalOf(IndustryProject $project): T2BlueprintCopyProposal
    {
        if ($project->isExcludesInvention()) {
            return new T2BlueprintCopyProposal(T2BlueprintCopyProposal::STATE_INVENTION_EXCLUDED, null, []);
        }

        $owner = $project->getOwner();
        if (!$project->isSoloProjectOf($owner) || ProjectStatus::Completed === $project->getStatus()) {
            return new T2BlueprintCopyProposal(T2BlueprintCopyProposal::STATE_NOT_SOLO, null, []);
        }

        $personalBlueprints = $this->ownedBlueprintRepository->findByCharacters($owner->getCharacters()->getValues());
        if ([] === $personalBlueprints) {
            return $this->unknown(self::REASON_BLUEPRINTS_NOT_SYNCED);
        }

        $containerBlueprints = null;
        if ([] !== $project->getContainerNames()) {
            $containerStock = $this->containerService->containerStockOf($project);
            if (null !== $containerStock->unknownReason) {
                return $this->unknown($containerStock->unknownReason);
            }
            $containerBlueprints = $this->corporationBlueprintsInContainer($project, $containerStock);
        }

        $personalRunsByBlueprintTypeId = self::copyRunsByBlueprintTypeId($personalBlueprints);
        $containerRunsByBlueprintTypeId = null === $containerBlueprints ? null : self::copyRunsByBlueprintTypeId($containerBlueprints);
        $declaredRunsByProductTypeId = $project->declaredRunsByProductTypeId();

        $lines = [];
        foreach ($this->inventedProductsReader->inventedProductsOf($project) as $productTypeId => $product) {
            $blueprintTypeId = $this->activityProductRepository->producerOf($productTypeId)->getTypeId();
            $personalRuns = self::runsOf($personalRunsByBlueprintTypeId, $blueprintTypeId);
            $containerRuns = null === $containerRunsByBlueprintTypeId ? null : self::runsOf($containerRunsByBlueprintTypeId, $blueprintTypeId);
            $availableRuns = null === $containerRuns ? $personalRuns : $personalRuns + $containerRuns;
            if (0 === $availableRuns) {
                continue;
            }

            $lines[] = new T2BlueprintCopyProposalLine(
                $productTypeId,
                $product['typeName'],
                $product['manufacturingRuns'],
                $personalRuns,
                $containerRuns,
                $availableRuns,
                min($availableRuns, $product['manufacturingRuns']),
                self::runsOf($declaredRunsByProductTypeId, $productTypeId),
            );
        }

        usort($lines, static fn (T2BlueprintCopyProposalLine $left, T2BlueprintCopyProposalLine $right): int => $left->typeName <=> $right->typeName);

        return new T2BlueprintCopyProposal(T2BlueprintCopyProposal::STATE_READY, null, $lines);
    }

    /**
     * @return list<OwnedBlueprint>
     */
    private function corporationBlueprintsInContainer(IndustryProject $project, ProjectContainerStock $containerStock): array
    {
        $corporationId = $project->getOwner()->getCorporationId();
        if (null === $corporationId) {
            throw new \LogicException('The owner of a project with a known container has no corporation.');
        }

        $contentItemIds = array_flip($containerStock->contentItemIds);

        return array_values(array_filter(
            $this->ownedBlueprintRepository->findByCorporationId($corporationId),
            static fn (OwnedBlueprint $blueprint): bool => isset($contentItemIds[$blueprint->getItemId()]),
        ));
    }

    /**
     * @param list<OwnedBlueprint> $blueprints
     *
     * @return array<int, int>
     */
    private static function copyRunsByBlueprintTypeId(array $blueprints): array
    {
        $runsByBlueprintTypeId = [];
        foreach ($blueprints as $blueprint) {
            if (!$blueprint->isCopy()) {
                continue;
            }

            $typeId = $blueprint->getTypeId();
            $runsByBlueprintTypeId[$typeId] = self::runsOf($runsByBlueprintTypeId, $typeId) + $blueprint->getRuns();
        }

        return $runsByBlueprintTypeId;
    }

    /**
     * @param array<int, int> $runsByTypeId
     */
    private static function runsOf(array $runsByTypeId, int $typeId): int
    {
        return \array_key_exists($typeId, $runsByTypeId) ? $runsByTypeId[$typeId] : 0;
    }

    private function unknown(string $reason): T2BlueprintCopyProposal
    {
        return new T2BlueprintCopyProposal(T2BlueprintCopyProposal::STATE_ASSETS_UNKNOWN, $reason, []);
    }
}
