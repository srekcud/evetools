import { onUnmounted, ref, shallowRef, type Ref } from 'vue'
import { apiRequest } from '@/services/api'
import { ASSET_SEARCH_MIN_LENGTH } from '@/constants/assetsConstants'
import type { Asset, AssetSearchAnswer, AssetSummary } from '@/types'

export interface AssetSearchState {
  query: string
  truncated: boolean
  locationIds: Set<number>
  rootLocationIds: Set<number>
  hitItemIds: Set<number>
  pathItemIds: Set<number>
  pathsByLocation: Map<number, number[][]>
}

interface ItemsAnswer {
  items?: Asset[]
}

function groupBy(rows: Asset[], keyOf: (row: Asset) => number): Map<number, Asset[]> {
  const grouped = new Map<number, Asset[]>()
  for (const row of rows) {
    const key = keyOf(row)
    const known = grouped.get(key)
    if (known === undefined) {
      grouped.set(key, [row])
    } else {
      known.push(row)
    }
  }
  return grouped
}

function withEntry<V>(map: Map<number, V>, key: number, value: V): Map<number, V> {
  const next = new Map(map)
  next.set(key, value)
  return next
}

function withoutEntry(set: Set<number>, key: number): Set<number> {
  const next = new Set(set)
  next.delete(key)
  return next
}

export function useAssetBrowse(ownerPath: Ref<string | null>) {
  const summary = ref<AssetSummary | null>(null)
  const divisionSummary = ref<AssetSummary | null>(null)
  const rootRows = shallowRef<Map<number, Asset[]>>(new Map())
  const childRows = shallowRef<Map<number, Asset[]>>(new Map())
  const locationOfItem = shallowRef<Map<number, number>>(new Map())
  const loadingLocationIds = ref<Set<number>>(new Set())
  const loadingContainerIds = ref<Set<number>>(new Set())
  const fullListLoaded = ref(false)
  const isLoadingFullList = ref(false)
  const search = ref<AssetSearchState | null>(null)

  let generation = 0
  let searchSequence = 0
  let summarySequence = 0
  const controllers = new Set<AbortController>()
  const inflight = new Map<string, Promise<void>>()

  function requirePath(): string {
    if (ownerPath.value === null) {
      throw new Error('No asset owner selected')
    }
    return ownerPath.value
  }

  async function read<T>(endpoint: string): Promise<T> {
    const controller = new AbortController()
    controllers.add(controller)
    try {
      return await apiRequest<T>(endpoint, { signal: controller.signal })
    } finally {
      controllers.delete(controller)
    }
  }

  function isAbort(error: unknown, startedIn: number): boolean {
    return startedIn !== generation || (error instanceof DOMException && error.name === 'AbortError')
  }

  async function fetchLocationRows(locationId: number): Promise<Asset[]> {
    const answer = await read<ItemsAnswer>(`${requirePath()}/locations/${locationId}`)
    return answer.items ?? []
  }

  async function fetchContainerRows(itemId: number): Promise<Asset[]> {
    const answer = await read<ItemsAnswer>(`${requirePath()}/containers/${itemId}`)
    return answer.items ?? []
  }

  function once(key: string, run: () => Promise<void>): Promise<void> {
    const running = inflight.get(key)
    if (running !== undefined) {
      return running
    }
    const started = run().finally(() => inflight.delete(key))
    inflight.set(key, started)
    return started
  }

  function rememberLocations(rows: Asset[], locationId: number): void {
    const next = new Map(locationOfItem.value)
    rows.forEach(row => next.set(row.itemId, locationId))
    locationOfItem.value = next
  }

  async function loadSummary(division: string): Promise<void> {
    const startedIn = generation
    const sequence = ++summarySequence
    const base = requirePath()
    try {
      const [full, filtered] = await Promise.all([
        read<AssetSummary>(`${base}/locations`),
        division === '' ? Promise.resolve(null) : read<AssetSummary>(`${base}/locations?divisionName=${encodeURIComponent(division)}`),
      ])
      if (startedIn !== generation || sequence !== summarySequence) return
      summary.value = full
      divisionSummary.value = filtered
    } catch (error) {
      if (isAbort(error, startedIn)) return
      throw error
    }
  }

  function loadLocation(locationId: number): Promise<void> {
    if (rootRows.value.has(locationId) || fullListLoaded.value) {
      return Promise.resolve()
    }
    return once(`location:${locationId}`, async () => {
      const startedIn = generation
      loadingLocationIds.value = new Set(loadingLocationIds.value).add(locationId)
      try {
        const rows = await fetchLocationRows(locationId)
        if (startedIn !== generation) return
        rootRows.value = withEntry(rootRows.value, locationId, rows)
        rememberLocations(rows, locationId)
      } catch (error) {
        if (isAbort(error, startedIn)) return
        throw error
      } finally {
        if (startedIn === generation) {
          loadingLocationIds.value = withoutEntry(loadingLocationIds.value, locationId)
        }
      }
    })
  }

  function loadContainer(itemId: number): Promise<void> {
    if (childRows.value.has(itemId) || fullListLoaded.value) {
      return Promise.resolve()
    }
    return once(`container:${itemId}`, async () => {
      const startedIn = generation
      loadingContainerIds.value = new Set(loadingContainerIds.value).add(itemId)
      try {
        const rows = await fetchContainerRows(itemId)
        if (startedIn !== generation) return
        childRows.value = withEntry(childRows.value, itemId, rows)
        const locationId = locationOfItem.value.get(itemId)
        if (locationId !== undefined) {
          rememberLocations(rows, locationId)
        }
      } catch (error) {
        if (isAbort(error, startedIn)) return
        throw error
      } finally {
        if (startedIn === generation) {
          loadingContainerIds.value = withoutEntry(loadingContainerIds.value, itemId)
        }
      }
    })
  }

  function applyFullList(rows: Asset[]): void {
    const knownItemIds = new Set(rows.map(row => row.itemId))
    const isRoot = (row: Asset) => row.locationType !== 'item' || !knownItemIds.has(row.locationId)
    rootRows.value = groupBy(rows.filter(isRoot), row => row.locationId)
    childRows.value = groupBy(rows.filter(row => !isRoot(row)), row => row.locationId)
    const parents = new Map(rows.map(row => [row.itemId, row]))
    const locations = new Map<number, number>()
    const locationOf = (row: Asset): number => {
      const known = locations.get(row.itemId)
      if (known !== undefined) {
        return known
      }
      const parent = isRoot(row) ? undefined : parents.get(row.locationId)
      const resolved = parent === undefined || parent.itemId === row.itemId ? row.locationId : locationOf(parent)
      locations.set(row.itemId, resolved)
      return resolved
    }
    rows.forEach(locationOf)
    locationOfItem.value = locations
    fullListLoaded.value = true
  }

  function loadFullList(): Promise<void> {
    if (fullListLoaded.value) {
      return Promise.resolve()
    }
    return once('full-list', async () => {
      const startedIn = generation
      isLoadingFullList.value = true
      try {
        const answer = await read<ItemsAnswer>(requirePath())
        if (startedIn !== generation) return
        applyFullList(answer.items ?? [])
      } catch (error) {
        if (isAbort(error, startedIn)) return
        throw error
      } finally {
        if (startedIn === generation) {
          isLoadingFullList.value = false
        }
      }
    })
  }

  async function runSearch(query: string): Promise<void> {
    const sequence = ++searchSequence
    const trimmed = query.trim()
    if (trimmed.length < ASSET_SEARCH_MIN_LENGTH) {
      search.value = null
      return
    }
    const startedIn = generation
    try {
      const answer = await read<AssetSearchAnswer>(`${requirePath()}/search?q=${encodeURIComponent(trimmed)}`)
      if (startedIn !== generation || sequence !== searchSequence) return
      const pathsByLocation = new Map<number, number[][]>()
      const pathItemIds = new Set<number>()
      for (const hit of answer.hits) {
        pathsByLocation.set(hit.rootLocationId, [...(pathsByLocation.get(hit.rootLocationId) ?? []), hit.containerPath])
        hit.containerPath.forEach(itemId => pathItemIds.add(itemId))
      }
      search.value = {
        query: answer.query,
        truncated: answer.truncated,
        locationIds: new Set(answer.locationIds),
        rootLocationIds: new Set(answer.hits.map(hit => hit.rootLocationId)),
        hitItemIds: new Set(answer.hits.map(hit => hit.itemId)),
        pathItemIds,
        pathsByLocation,
      }
    } catch (error) {
      if (isAbort(error, startedIn)) return
      throw error
    }
  }

  async function reloadLoaded(): Promise<void> {
    const startedIn = generation
    try {
      if (fullListLoaded.value) {
        const answer = await read<ItemsAnswer>(requirePath())
        if (startedIn !== generation) return
        applyFullList(answer.items ?? [])
        return
      }
      const locationIds = Array.from(rootRows.value.keys())
      const containerIds = Array.from(childRows.value.keys())
      const [locations, containers] = await Promise.all([
        Promise.all(locationIds.map(fetchLocationRows)),
        Promise.all(containerIds.map(fetchContainerRows)),
      ])
      if (startedIn !== generation) return
      rootRows.value = new Map(locationIds.map((id, index) => [id, locations[index]]))
      childRows.value = new Map(containerIds.map((id, index) => [id, containers[index]]))
      const rebuilt = new Map<number, number>()
      locationIds.forEach((id, index) => locations[index].forEach(row => rebuilt.set(row.itemId, id)))
      for (const [containerId, rows] of childRows.value) {
        const locationId = rebuilt.get(containerId) ?? locationOfItem.value.get(containerId)
        if (locationId !== undefined) {
          rows.forEach(row => rebuilt.set(row.itemId, locationId))
        }
      }
      locationOfItem.value = rebuilt
    } catch (error) {
      if (isAbort(error, startedIn)) return
      throw error
    }
  }

  function reset(): void {
    generation++
    searchSequence++
    controllers.forEach(controller => controller.abort())
    controllers.clear()
    inflight.clear()
    summary.value = null
    divisionSummary.value = null
    rootRows.value = new Map()
    childRows.value = new Map()
    locationOfItem.value = new Map()
    loadingLocationIds.value = new Set()
    loadingContainerIds.value = new Set()
    fullListLoaded.value = false
    isLoadingFullList.value = false
    search.value = null
  }

  function replaceRow(itemId: number, patch: Partial<Asset>): void {
    const patchIn = (rows: Map<number, Asset[]>): Map<number, Asset[]> | null => {
      for (const [key, list] of rows) {
        const index = list.findIndex(row => row.itemId === itemId)
        if (index !== -1) {
          const updated = list.slice()
          updated[index] = { ...updated[index], ...patch }
          return withEntry(rows, key, updated)
        }
      }
      return null
    }
    const roots = patchIn(rootRows.value)
    if (roots !== null) {
      rootRows.value = roots
      return
    }
    const children = patchIn(childRows.value)
    if (children !== null) {
      childRows.value = children
    }
  }

  onUnmounted(() => controllers.forEach(controller => controller.abort()))

  return {
    summary,
    divisionSummary,
    rootRows,
    childRows,
    locationOfItem,
    loadingLocationIds,
    loadingContainerIds,
    fullListLoaded,
    isLoadingFullList,
    search,
    loadSummary,
    loadLocation,
    loadContainer,
    loadFullList,
    runSearch,
    reloadLoaded,
    reset,
    replaceRow,
  }
}
