import type { Asset, PinnedContainer } from '@/types'

export interface PinnedProjectGroup {
  projectId: string
  projectName: string
  entries: PinnedContainer[]
}

export interface GroupedPinnedContainers {
  projects: PinnedProjectGroup[]
  manual: PinnedContainer[]
}

export interface LocatedContainer {
  locationName: string
  containerItemIds: number[]
}

export function groupPinnedEntries(entries: PinnedContainer[]): GroupedPinnedContainers {
  const projects: PinnedProjectGroup[] = []
  const manual: PinnedContainer[] = []
  for (const entry of entries) {
    if (entry.projectId === null) {
      manual.push(entry)
      continue
    }
    const known = projects.find(group => group.projectId === entry.projectId)
    if (known) {
      known.entries.push(entry)
    } else {
      projects.push({ projectId: entry.projectId, projectName: entry.projectName ?? '', entries: [entry] })
    }
  }
  return { projects, manual }
}

export function locateContainer(itemId: number, assetsByItemId: Map<number, Asset>): LocatedContainer | null {
  const container = assetsByItemId.get(itemId)
  if (!container) {
    return null
  }
  const containerItemIds = [container.itemId]
  let root = container
  const visited = new Set<number>(containerItemIds)
  while (root.locationType === 'item') {
    const parent = assetsByItemId.get(root.locationId)
    if (!parent || visited.has(parent.itemId)) {
      break
    }
    visited.add(parent.itemId)
    containerItemIds.push(parent.itemId)
    root = parent
  }
  return { locationName: root.locationName, containerItemIds }
}

export function projectPinnedFirst(assets: Asset[], projectPinnedItemIds: Set<number>): Asset[] {
  if (projectPinnedItemIds.size === 0) {
    return assets
  }
  return [
    ...assets.filter(asset => projectPinnedItemIds.has(asset.itemId)),
    ...assets.filter(asset => !projectPinnedItemIds.has(asset.itemId)),
  ]
}
