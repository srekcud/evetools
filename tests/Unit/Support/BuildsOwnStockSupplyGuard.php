<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\ChainRecipeIngredients;
use App\Service\Industry\Project\IntermediateStockProposer;
use App\Service\Industry\Project\OwnStockSupplyGuard;
use App\Service\Industry\Project\ProjectContainerService;
use App\Tests\Unit\Service\Industry\Project\InMemoryChainRecipeIngredients;

trait BuildsOwnStockSupplyGuard
{
    use StubsPersonalStockReader;

    private function ownStockSupplyGuardOver(callable $personalQuantitiesOf, bool $personalAssetsSynced = true): OwnStockSupplyGuard
    {
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturn($personalAssetsSynced);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturnCallback(static fn (User $user): array => $personalQuantitiesOf($user));

        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findIntermediateStockByTypeId')->willReturnCallback(static fn (IndustryProject $project, ?User $contributor = null): array => []);
        $contributionRepository->method('findBomItemIdsWithMaterialContributionOf')->willReturn([]);

        $chainRecipeIngredients = $this->createStub(ChainRecipeIngredients::class);
        $chainRecipeIngredients->method('producedIngredientsOf')->willReturnCallback(
            static fn (array $productTypeIds): array => (new InMemoryChainRecipeIngredients([]))->producedIngredientsOf($productTypeIds),
        );

        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturn([]);

        return new OwnStockSupplyGuard(new IntermediateStockProposer(
            $assetRepository,
            $this->createStub(ProjectContainerService::class),
            $contributionRepository,
            $chainRecipeIngredients,
            $this->personalStockReaderOn($assetRepository),
            $structureConfigRepository,
        ));
    }
}
