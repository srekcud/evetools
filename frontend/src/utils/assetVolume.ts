import type { Asset } from '@/types'

export interface PackagedVolumeCatalog {
  volumes: ReadonlyMap<number, number>
  excludedTypeIds: ReadonlySet<number>
}

export interface VolumeSummary {
  cubicMeters: number
  unknownTypeCount: number
}

const VOLUME_DECIMALS = 2

export function summarizeVolume(
  assets: readonly Asset[],
  contentsOf: (itemId: number) => readonly Asset[],
  catalog: PackagedVolumeCatalog,
): VolumeSummary {
  let cubicMeters = 0
  const unknownTypeIds = new Set<number>()
  const visitedItemIds = new Set<number>()

  function add(asset: Asset): void {
    if (!catalog.excludedTypeIds.has(asset.typeId)) {
      const unitVolume = catalog.volumes.get(asset.typeId)
      if (unitVolume === undefined) {
        unknownTypeIds.add(asset.typeId)
      } else {
        cubicMeters += unitVolume * asset.quantity
      }
    }
    if (!visitedItemIds.has(asset.itemId)) {
      visitedItemIds.add(asset.itemId)
      contentsOf(asset.itemId).forEach(add)
    }
  }

  assets.forEach(add)

  return { cubicMeters, unknownTypeCount: unknownTypeIds.size }
}

export function formatCubicMeters(cubicMeters: number): string {
  return cubicMeters.toLocaleString(undefined, { maximumFractionDigits: VOLUME_DECIMALS })
}
