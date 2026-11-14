<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use App\ApiResource\Assets\AssetBrowseItemResource;
use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\AssetSearchHitResource;
use App\Constant\NameableAssetType;

final readonly class AssetBrowseItemMapper
{
    public function __construct(private AssetBlueprintAnnotator $blueprintAnnotator)
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<AssetBrowseItemResource>
     */
    public function items(AssetBrowseOwner $owner, array $rows): array
    {
        $items = array_map(fn (array $row): AssetBrowseItemResource => $this->fill(new AssetBrowseItemResource(), $row), $rows);
        $this->annotate($owner, $items);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<AssetSearchHitResource>
     */
    public function hits(AssetBrowseOwner $owner, array $rows): array
    {
        $hits = array_map(function (array $row): AssetSearchHitResource {
            $hit = $this->fill(new AssetSearchHitResource(), $row);
            $hit->rootLocationId = $row['rootLocationId'];
            $hit->containerPath = $row['containerPath'];

            return $hit;
        }, $rows);
        $this->annotate($owner, $hits);

        return $hits;
    }

    /**
     * @template TItem of AssetBrowseItemResource
     *
     * @param TItem $item
     * @param array<string, mixed> $row
     *
     * @return TItem
     */
    private function fill(AssetBrowseItemResource $item, array $row): AssetBrowseItemResource
    {
        $item->id = $row['id'];
        $item->itemId = $row['itemId'];
        $item->typeId = $row['typeId'];
        $item->typeName = $row['typeName'];
        $item->categoryId = $row['categoryId'];
        $item->quantity = $row['quantity'];
        $item->locationId = $row['locationId'];
        $item->locationName = $row['locationName'];
        $item->locationType = $row['locationType'];
        $item->locationFlag = $row['locationFlag'] ?? '';
        $item->solarSystemId = $row['solarSystemId'];
        $item->solarSystemName = $row['solarSystemName'];
        $item->itemName = $row['itemName'];
        $item->divisionName = $row['divisionName'];
        $item->isSingleton = $row['isSingleton'];
        $item->canResolveItemName = NameableAssetType::accepts($row['categoryId'], $row['groupId'])
            && NameableAssetType::mayHoldCustomName($row['isSingleton'], $row['quantity'], $row['itemName'], $row['locationFlag']);
        $item->cachedAt = $row['cachedAt'];
        $item->childCount = $row['childCount'];

        return $item;
    }

    /**
     * @param list<AssetItemResource> $items
     */
    private function annotate(AssetBrowseOwner $owner, array $items): void
    {
        if ($owner->character !== null) {
            $this->blueprintAnnotator->annotateCharacterItems($owner->character, $items);

            return;
        }

        $this->blueprintAnnotator->annotateCorporationItems(
            $owner->corporationId ?? throw new \LogicException('A corporation owner carries its corporation id'),
            $items,
        );
    }
}
