import type { Asset } from '@/types'
import { normalizeSearchText } from '@/utils/commandPaletteSearch'

export type TypeSortKey = 'quantity' | 'name'
export type SortDirection = 'asc' | 'desc'

export interface TypeGroupLocation {
  locationName: string
  solarSystemName: string | null
  quantity: number
}

export interface TypeGroup {
  typeId: number
  typeName: string
  categoryId: number | null
  totalQuantity: number
  locations: TypeGroupLocation[]
}

function locationRoot(asset: Asset, assetsByItemId: Map<number, Asset>): Asset {
  const visited = new Set<number>()
  let current = asset
  while (current.locationType === 'item' && !visited.has(current.itemId)) {
    visited.add(current.itemId)
    const container = assetsByItemId.get(current.locationId)
    if (container === undefined) {
      break
    }
    current = container
  }
  return current
}

export function groupAssetsByType(assets: Asset[], typeLabel: (asset: Asset) => string): TypeGroup[] {
  const assetsByItemId = new Map(assets.map(asset => [asset.itemId, asset]))
  const groups = new Map<number, TypeGroup>()

  for (const asset of assets) {
    const root = locationRoot(asset, assetsByItemId)
    let group = groups.get(asset.typeId)
    if (group === undefined) {
      group = {
        typeId: asset.typeId,
        typeName: typeLabel(asset),
        categoryId: asset.categoryId,
        totalQuantity: 0,
        locations: [],
      }
      groups.set(asset.typeId, group)
    }
    group.totalQuantity += asset.quantity
    const location = group.locations.find(entry => entry.locationName === root.locationName)
    if (location === undefined) {
      group.locations.push({ locationName: root.locationName, solarSystemName: root.solarSystemName, quantity: asset.quantity })
    } else {
      location.quantity += asset.quantity
    }
  }

  for (const group of groups.values()) {
    group.locations.sort((a, b) => b.quantity - a.quantity || a.locationName.localeCompare(b.locationName))
  }

  return sortTypeGroups(Array.from(groups.values()), 'quantity', 'desc')
}

export function sortTypeGroups(groups: TypeGroup[], key: TypeSortKey, direction: SortDirection): TypeGroup[] {
  const sign = direction === 'asc' ? 1 : -1
  const compare = key === 'name'
    ? (a: TypeGroup, b: TypeGroup) => a.typeName.localeCompare(b.typeName)
    : (a: TypeGroup, b: TypeGroup) => a.totalQuantity - b.totalQuantity || a.typeName.localeCompare(b.typeName)
  return groups.slice().sort((a, b) => sign * compare(a, b))
}

export interface TypeGroupFilters {
  solarSystem: string
  location: string
  searchQuery: string
}

export function filterTypeGroups(groups: TypeGroup[], filters: TypeGroupFilters): TypeGroup[] {
  const query = normalizeSearchText(filters.searchQuery)
  const result: TypeGroup[] = []

  for (const group of groups) {
    if (query && !normalizeSearchText(group.typeName).includes(query)) {
      continue
    }
    const locations = group.locations.filter(location =>
      (!filters.solarSystem || location.solarSystemName === filters.solarSystem)
      && (!filters.location || location.locationName === filters.location),
    )
    if (locations.length === 0) {
      continue
    }
    result.push({
      ...group,
      locations,
      totalQuantity: locations.reduce((sum, location) => sum + location.quantity, 0),
    })
  }

  return result
}
