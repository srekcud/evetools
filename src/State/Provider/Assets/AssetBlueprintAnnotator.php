<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use App\ApiResource\Assets\AssetBlueprintResource;
use App\ApiResource\Assets\AssetItemResource;
use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Repository\OwnedBlueprintRepository;

final readonly class AssetBlueprintAnnotator
{
    private const int BLUEPRINT_CATEGORY_ID = 9;

    public function __construct(private OwnedBlueprintRepository $ownedBlueprintRepository)
    {
    }

    /** @param array<AssetItemResource> $items */
    public function annotateCharacterItems(Character $character, array $items): void
    {
        $this->annotate($items, fn (): array => $this->ownedBlueprintRepository->findByCharacters([$character]));
    }

    /** @param array<AssetItemResource> $items */
    public function annotateCorporationItems(int $corporationId, array $items): void
    {
        $this->annotate($items, fn (): array => $this->ownedBlueprintRepository->findByCorporationId($corporationId));
    }

    /**
     * @param array<AssetItemResource> $items
     * @param \Closure(): list<OwnedBlueprint> $loadOwnedBlueprints
     */
    private function annotate(array $items, \Closure $loadOwnedBlueprints): void
    {
        $blueprintRows = array_filter(
            $items,
            static fn (AssetItemResource $item): bool => $item->categoryId === self::BLUEPRINT_CATEGORY_ID,
        );

        if ($blueprintRows === []) {
            return;
        }

        $ownedByItemId = [];
        foreach ($loadOwnedBlueprints() as $owned) {
            $ownedByItemId[$owned->getItemId()] = $owned;
        }

        foreach ($blueprintRows as $item) {
            $item->blueprint = $this->stateOf($ownedByItemId[$item->itemId] ?? null);
        }
    }

    private function stateOf(?OwnedBlueprint $owned): AssetBlueprintResource
    {
        if ($owned === null) {
            return new AssetBlueprintResource(AssetBlueprintResource::NOT_SYNCED, null, null, null);
        }

        if ($owned->isCopy()) {
            return new AssetBlueprintResource(
                AssetBlueprintResource::COPY,
                $owned->getRuns(),
                $owned->getMaterialEfficiency(),
                $owned->getTimeEfficiency(),
            );
        }

        return new AssetBlueprintResource(
            AssetBlueprintResource::ORIGINAL,
            null,
            $owned->getMaterialEfficiency(),
            $owned->getTimeEfficiency(),
        );
    }
}
