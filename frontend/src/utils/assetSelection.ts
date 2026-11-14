import type { Asset } from '@/types'
import type { TypeGroup } from '@/utils/assetsByType'

export interface SelectionLine {
  typeName: string
  quantity: number
}

export interface SelectionSummary {
  lines: SelectionLine[]
  itemCount: number
  unknownTypeCount: number
}

export function summarizeTypeSelection(
  groups: readonly TypeGroup[],
  unnamedTypeIds: ReadonlySet<number>,
): SelectionSummary {
  const lines: SelectionLine[] = []
  let itemCount = 0
  let unknownTypeCount = 0

  for (const group of groups) {
    itemCount += group.totalQuantity
    if (unnamedTypeIds.has(group.typeId)) {
      unknownTypeCount += 1
    } else {
      lines.push({ typeName: group.typeName, quantity: group.totalQuantity })
    }
  }

  lines.sort((a, b) => a.typeName.localeCompare(b.typeName))
  return { lines, itemCount, unknownTypeCount }
}

export function summarizeSelection(
  locations: readonly { assets: readonly Asset[] }[],
  containerContents: ReadonlyMap<number, readonly Asset[]>,
): SelectionSummary {
  const quantityByType = new Map<number, SelectionLine>()
  const unknownTypeIds = new Set<number>()
  const visitedContainers = new Set<number>()
  let itemCount = 0

  function add(asset: Asset): void {
    itemCount += asset.quantity
    if (asset.typeName === null) {
      unknownTypeIds.add(asset.typeId)
    } else {
      const known = quantityByType.get(asset.typeId)
      if (known === undefined) {
        quantityByType.set(asset.typeId, { typeName: asset.typeName, quantity: asset.quantity })
      } else {
        known.quantity += asset.quantity
      }
    }
    const contents = containerContents.get(asset.itemId)
    if (contents !== undefined && !visitedContainers.has(asset.itemId)) {
      visitedContainers.add(asset.itemId)
      contents.forEach(add)
    }
  }

  for (const location of locations) {
    location.assets.forEach(add)
  }

  const lines = Array.from(quantityByType.values()).sort((a, b) => a.typeName.localeCompare(b.typeName))
  return { lines, itemCount, unknownTypeCount: unknownTypeIds.size }
}
