const SHIP_CATEGORY_ID = 6
const MAX_DEPTH = 5
const MAX_HITS = 200

type RawAsset = Record<string, unknown>

interface Row {
  raw: RawAsset
  itemId: number
  locationId: number
  locationType: string
  quantity: number
  typeId: number
  typeName: string | null
  itemName: string | null
  categoryId: number | null
  isSingleton: boolean | null
  divisionName: string | null
  solarSystemName: string | null
  locationName: string
  cachedAt: string
}

function toRow(raw: RawAsset): Row {
  return {
    raw,
    itemId: raw.itemId as number,
    locationId: raw.locationId as number,
    locationType: raw.locationType as string,
    quantity: raw.quantity as number,
    typeId: raw.typeId as number,
    typeName: (raw.typeName as string | null | undefined) ?? null,
    itemName: (raw.itemName as string | null | undefined) ?? null,
    categoryId: (raw.categoryId as number | null | undefined) ?? null,
    isSingleton: (raw.isSingleton as boolean | null | undefined) ?? null,
    divisionName: (raw.divisionName as string | null | undefined) ?? null,
    solarSystemName: (raw.solarSystemName as string | null | undefined) ?? null,
    locationName: raw.locationName as string,
    cachedAt: raw.cachedAt as string,
  }
}

function foldAccents(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status })
}

class Inventory {
  private readonly rows: Row[]
  private readonly itemIds: Set<number>

  constructor(rawAssets: RawAsset[]) {
    this.rows = rawAssets.map(toRow)
    this.itemIds = new Set(this.rows.map(row => row.itemId))
  }

  private isRoot(row: Row): boolean {
    return row.locationType !== 'item' || !this.itemIds.has(row.locationId)
  }

  private childrenOf(itemId: number): Row[] {
    return this.rows.filter(row => row.locationType === 'item' && row.locationId === itemId)
  }

  private descendantsOf(row: Row, depth = 0): Row[] {
    if (depth >= MAX_DEPTH) {
      return []
    }
    return this.childrenOf(row.itemId).flatMap(child => [child, ...this.descendantsOf(child, depth + 1)])
  }

  private roots(divisionName: string | null): Row[] {
    return this.rows.filter(row => this.isRoot(row) && (divisionName === null || row.divisionName === divisionName))
  }

  private item(row: Row): Record<string, unknown> {
    return { ...row.raw, childCount: this.childrenOf(row.itemId).length }
  }

  fullList(): RawAsset[] {
    return this.rows.map(row => row.raw)
  }

  summary(divisionName: string | null): Record<string, unknown> {
    const byLocation = new Map<number, Row[]>()
    for (const root of this.roots(divisionName)) {
      byLocation.set(root.locationId, [...(byLocation.get(root.locationId) ?? []), root])
    }
    const locations = Array.from(byLocation, ([locationId, roots]) => {
      const everyRow = roots.flatMap(root => [root, ...this.descendantsOf(root)])
      const divisionCounts = new Map<string, number>()
      for (const root of roots) {
        if (root.divisionName !== null) {
          const total = [root, ...this.descendantsOf(root)].reduce((sum, row) => sum + row.quantity, 0)
          divisionCounts.set(root.divisionName, (divisionCounts.get(root.divisionName) ?? 0) + total)
        }
      }
      return {
        locationId,
        locationType: roots[0].locationType,
        locationName: roots[0].locationName,
        solarSystemName: roots[0].solarSystemName,
        itemCount: everyRow.reduce((sum, row) => sum + row.quantity, 0),
        shipCount: roots.filter(row => row.categoryId === SHIP_CATEGORY_ID && row.isSingleton === true).reduce((sum, row) => sum + row.quantity, 0),
        divisions: Array.from(divisionCounts, ([name, count]) => ({ name, count })).sort((a, b) => b.count - a.count || a.name.localeCompare(b.name)),
        cachedAt: roots.map(row => row.cachedAt).sort().at(-1) ?? null,
      }
    }).sort((a, b) => b.itemCount - a.itemCount || a.locationName.localeCompare(b.locationName))
    const everyRow = this.roots(divisionName).flatMap(root => [root, ...this.descendantsOf(root)])
    const cachedAts = everyRow.map(row => row.cachedAt).sort()
    return {
      id: 'corporation',
      itemCount: locations.reduce((sum, location) => sum + location.itemCount, 0),
      typeCount: new Set(everyRow.map(row => row.typeId)).size,
      systemCount: new Set(this.roots(divisionName).map(row => row.solarSystemName).filter(name => name !== null)).size,
      locationCount: locations.length,
      lastSyncedAt: cachedAts.at(-1) ?? null,
      nextRefreshAt: null,
      locations,
    }
  }

  locationContents(locationId: number): Response {
    const roots = this.roots(null).filter(row => row.locationId === locationId)
    if (roots.length === 0) {
      return json({ error: 'not found' }, 404)
    }
    const items = roots
      .sort((a, b) => (a.typeName ?? '').localeCompare(b.typeName ?? ''))
      .map(row => this.item(row))
    return json({ locationId, items })
  }

  containerContents(itemId: number): Response {
    const container = this.rows.find(row => row.itemId === itemId)
    if (container === undefined) {
      return json({ error: 'not found' }, 404)
    }
    return json({ itemId, items: this.childrenOf(itemId).map(row => this.item(row)) })
  }

  search(query: string): Response {
    const needle = foldAccents(query.trim())
    if (needle.length < 2) {
      return json({ error: 'query too short' }, 400)
    }
    const contains = (text: string | null) => text !== null && foldAccents(text).includes(needle)
    const roots = this.roots(null)
    const locationIds = Array.from(new Set(roots.filter(row => contains(row.locationName) || contains(row.solarSystemName)).map(row => row.locationId)))
    const hits: Record<string, unknown>[] = []
    for (const root of roots) {
      const walk = (row: Row, path: number[], depth: number) => {
        if (contains(row.typeName) || contains(row.itemName)) {
          hits.push({ ...this.item(row), rootLocationId: root.locationId, containerPath: path })
        }
        if (depth < MAX_DEPTH) {
          this.childrenOf(row.itemId).forEach(child => walk(child, [...path, row.itemId], depth + 1))
        }
      }
      walk(root, [], 0)
    }
    return json({ query, truncated: hits.length > MAX_HITS, locationIds, hits: hits.slice(0, MAX_HITS) })
  }
}

export interface AssetBrowseFetch {
  (input: RequestInfo | URL, init?: RequestInit): Promise<Response>
}

export function assetBrowseFetch(
  rawAssets: RawAsset[],
  fallback: (url: string, init?: RequestInit) => Response | null = () => null,
): AssetBrowseFetch {
  const inventory = new Inventory(rawAssets)
  return async (input, init) => {
    const url = String(input)
    const other = fallback(url, init)
    if (other !== null) {
      return other
    }
    const [path, queryString = ''] = url.split('?')
    const query = new URLSearchParams(queryString)
    const summary = /\/assets\/locations$/.exec(path)
    if (summary !== null) {
      return json(inventory.summary(query.get('divisionName')))
    }
    const location = /\/assets\/locations\/(\d+)$/.exec(path)
    if (location !== null) {
      return inventory.locationContents(Number(location[1]))
    }
    const container = /\/assets\/containers\/(\d+)$/.exec(path)
    if (container !== null) {
      return inventory.containerContents(Number(container[1]))
    }
    if (/\/assets\/search$/.test(path)) {
      return inventory.search(query.get('q') ?? '')
    }
    return json({ items: inventory.fullList(), nextRefreshAt: null })
  }
}

export const ASSET_LOCATIONS_PATH = '/assets/locations'

export function endpointCalls(fetchMock: { mock: { calls: unknown[][] } }, pattern: RegExp): string[] {
  return fetchMock.mock.calls.map(call => String(call[0])).filter(url => pattern.test(url))
}
