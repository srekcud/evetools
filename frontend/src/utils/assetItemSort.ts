import type { Asset } from '@/types'
import { summarizeVolume, type PackagedVolumeCatalog } from '@/utils/assetVolume'

export type AssetItemSort = 'name' | 'quantity' | 'volume'

export const ASSET_ITEM_SORTS: readonly AssetItemSort[] = ['name', 'quantity', 'volume']
export const DEFAULT_ASSET_ITEM_SORT: AssetItemSort = 'name'

interface AssetItemSortOptions {
  sort: AssetItemSort
  catalog: PackagedVolumeCatalog | null
  contentsOf: (itemId: number) => readonly Asset[]
  projectPinnedItemIds: ReadonlySet<number>
}

function orderedBy(assets: readonly Asset[], options: AssetItemSortOptions): Asset[] {
  const { sort, catalog, contentsOf } = options
  if (sort === 'quantity') {
    return [...assets].sort((a, b) => b.quantity - a.quantity)
  }
  if (sort === 'volume' && catalog !== null) {
    return assets
      .map(asset => ({ asset, cubicMeters: summarizeVolume([asset], contentsOf, catalog).cubicMeters }))
      .sort((a, b) => b.cubicMeters - a.cubicMeters)
      .map(entry => entry.asset)
  }
  return [...assets]
}

export function sortAssetItems(assets: readonly Asset[], options: AssetItemSortOptions): Asset[] {
  const ordered = orderedBy(assets, options)
  if (options.projectPinnedItemIds.size === 0) {
    return ordered
  }
  return [
    ...ordered.filter(asset => options.projectPinnedItemIds.has(asset.itemId)),
    ...ordered.filter(asset => !options.projectPinnedItemIds.has(asset.itemId)),
  ]
}
