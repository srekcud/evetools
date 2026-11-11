<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;

final readonly class IntermediateStockProposer
{
    public function __construct(
        private CachedAssetRepository $assetRepository,
        private ProjectContainerService $containerService,
        private IndustryProjectContributionRepository $contributionRepository,
    ) {
    }

    public function proposalOf(IndustryProject $project): IntermediateStockProposal
    {
        return new IntermediateStockProposal(IntermediateStockProposal::STATE_ASSETS_UNKNOWN, 'not_implemented', []);
    }
}
