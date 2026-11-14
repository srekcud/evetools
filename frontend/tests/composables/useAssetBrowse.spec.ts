import { flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useAssetBrowse } from '@/composables/useAssetBrowse'
import { assetBrowseFetch, endpointCalls, type AssetBrowseFetch } from '../assetBrowseApi'
import { deferred } from '../deferred'

vi.mock('@/services/api', async importOriginal => ({
  ...(await importOriginal<typeof import('@/services/api')>()),
  apiRequest: vi.fn(),
}))

const OWNER = '/me/corporation/assets'
const STATION_ID = 60003760
const OTHER_STATION_ID = 60008494
const CONTAINER_ID = 1050000000201
const NESTED_CONTAINER_ID = 1050000000301
const STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const OTHER_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy'

function row(overrides: Record<string, unknown>) {
  return {
    categoryId: 4,
    locationId: STATION_ID,
    locationName: STATION_NAME,
    locationType: 'station',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    cachedAt: '2026-11-14T10:00:00+00:00',
    ...overrides,
  }
}

const inventory = [
  row({ id: 'a1', itemId: 1050000000101, typeId: 34, typeName: 'Tritanium', quantity: 8800 }),
  row({ id: 'a2', itemId: CONTAINER_ID, typeId: 3296, typeName: 'Station Container', quantity: 1, itemName: '1_Srek' }),
  row({ id: 'a3', itemId: NESTED_CONTAINER_ID, typeId: 3296, typeName: 'Station Container', quantity: 1, itemName: 'Inner', locationId: CONTAINER_ID, locationType: 'item', locationFlag: 'Unlocked' }),
  row({ id: 'a4', itemId: 1050000000401, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120, locationId: NESTED_CONTAINER_ID, locationType: 'item', locationFlag: 'Unlocked' }),
  row({ id: 'a5', itemId: 1050000000501, typeId: 35, typeName: 'Pyerite', quantity: 300, locationId: OTHER_STATION_ID, locationName: OTHER_STATION_NAME, solarSystemName: 'Amarr', divisionName: 'Hangar - Alpha' }),
]

let fetchMock: ReturnType<typeof vi.fn<AssetBrowseFetch>>

function serveInventory() {
  fetchMock = vi.fn(assetBrowseFetch(inventory))
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const response = await fetchMock(`/api${endpoint}`)
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }
    return response.json()
  })
}

function callsTo(pattern: RegExp): string[] {
  return endpointCalls(fetchMock, pattern)
}

beforeEach(() => {
  serveInventory()
})

describe('loading the summary', () => {
  it('reads one row per location without loading any contents', async () => {
    const browse = useAssetBrowse(ref(OWNER))

    await browse.loadSummary('')

    expect(browse.summary.value?.locations.map(location => location.locationName)).toEqual([STATION_NAME, OTHER_STATION_NAME])
    expect(browse.summary.value?.itemCount).toBe(9222)
    expect(callsTo(/\/assets\/locations\/|\/containers\//)).toEqual([])
  })

  it('keeps the unfiltered summary and reads the division summary separately', async () => {
    const browse = useAssetBrowse(ref(OWNER))

    await browse.loadSummary('Hangar - Alpha')

    expect(browse.summary.value?.locationCount).toBe(2)
    expect(browse.divisionSummary.value?.locations.map(location => location.locationName)).toEqual([OTHER_STATION_NAME])
    expect(callsTo(/locations\?divisionName=Hangar%20-%20Alpha$|locations\?divisionName=Hangar\+-\+Alpha$/)).toHaveLength(1)
  })
})

describe('loading contents on expansion', () => {
  it('loads the root rows of a location once and flags containers by their child count', async () => {
    const browse = useAssetBrowse(ref(OWNER))
    await browse.loadSummary('')

    await browse.loadLocation(STATION_ID)
    await browse.loadLocation(STATION_ID)

    expect(callsTo(new RegExp(`/locations/${STATION_ID}$`))).toHaveLength(1)
    const rows = browse.rootRows.value.get(STATION_ID) ?? []
    expect(rows.map(asset => asset.typeName)).toEqual(['Station Container', 'Tritanium'])
    expect(rows.find(asset => asset.itemId === CONTAINER_ID)?.childCount).toBe(1)
  })

  it('loads the direct contents of a container once', async () => {
    const browse = useAssetBrowse(ref(OWNER))
    await browse.loadLocation(STATION_ID)

    await browse.loadContainer(CONTAINER_ID)
    await browse.loadContainer(CONTAINER_ID)

    expect(callsTo(new RegExp(`/containers/${CONTAINER_ID}$`))).toHaveLength(1)
    expect((browse.childRows.value.get(CONTAINER_ID) ?? []).map(asset => asset.itemId)).toEqual([NESTED_CONTAINER_ID])
  })

  it('reports the location as loading until its contents arrive', async () => {
    const pending = deferred<Response>()
    fetchMock = vi.fn(() => pending.promise)
    const browse = useAssetBrowse(ref(OWNER))

    const loading = browse.loadLocation(STATION_ID)
    await flushPromises()
    expect(browse.loadingLocationIds.value.has(STATION_ID)).toBe(true)

    pending.resolve(new Response(JSON.stringify({ locationId: STATION_ID, items: [] }), { status: 200 }))
    await loading
    expect(browse.loadingLocationIds.value.has(STATION_ID)).toBe(false)
  })
})

describe('loading the full list on demand', () => {
  it('splits the rows into roots and container contents and stops further content calls', async () => {
    const browse = useAssetBrowse(ref(OWNER))

    await browse.loadFullList()
    await browse.loadLocation(STATION_ID)
    await browse.loadContainer(CONTAINER_ID)

    expect(browse.fullListLoaded.value).toBe(true)
    expect(callsTo(/\/assets$/)).toHaveLength(1)
    expect(callsTo(/\/locations\/|\/containers\//)).toEqual([])
    expect((browse.childRows.value.get(NESTED_CONTAINER_ID) ?? []).map(asset => asset.typeName)).toEqual(['Hydrogen Fuel Block'])
    expect((browse.rootRows.value.get(OTHER_STATION_ID) ?? []).map(asset => asset.typeName)).toEqual(['Pyerite'])
  })
})

describe('server search', () => {
  it('does not call the server for fewer than two characters', async () => {
    const browse = useAssetBrowse(ref(OWNER))

    await browse.runSearch('t')

    expect(browse.search.value).toBeNull()
    expect(callsTo(/\/search/)).toEqual([])
  })

  it('exposes matching locations, hit rows and the containers to open', async () => {
    const browse = useAssetBrowse(ref(OWNER))

    await browse.runSearch('hydrogen')

    expect(browse.search.value).toMatchObject({
      query: 'hydrogen',
      truncated: false,
      locationIds: new Set(),
      rootLocationIds: new Set([STATION_ID]),
      hitItemIds: new Set([1050000000401]),
      pathItemIds: new Set([CONTAINER_ID, NESTED_CONTAINER_ID]),
    })
  })

  it('ignores an answer that a newer search has superseded', async () => {
    const slow = deferred<Response>()
    const fast = assetBrowseFetch(inventory)
    fetchMock = vi.fn((input: RequestInfo | URL) => (String(input).includes('q=tri') ? slow.promise : fast(input)))
    const browse = useAssetBrowse(ref(OWNER))

    const first = browse.runSearch('tri')
    const second = browse.runSearch('pyerite')
    await second
    slow.resolve(await fast('/api/me/corporation/assets/search?q=tri'))
    await first

    expect(browse.search.value?.query).toBe('pyerite')
  })
})

describe('reloading and resetting', () => {
  it('rereads the contents that were already loaded and replaces them', async () => {
    const browse = useAssetBrowse(ref(OWNER))
    await browse.loadLocation(STATION_ID)
    await browse.loadContainer(CONTAINER_ID)
    fetchMock.mockClear()

    await browse.reloadLoaded()

    expect(callsTo(new RegExp(`/locations/${STATION_ID}$`))).toHaveLength(1)
    expect(callsTo(new RegExp(`/containers/${CONTAINER_ID}$`))).toHaveLength(1)
    expect(callsTo(/\/locations\/60008494$/)).toEqual([])
  })

  it('drops an answer that arrives after a reset', async () => {
    const pending = deferred<Response>()
    fetchMock = vi.fn(() => pending.promise)
    const browse = useAssetBrowse(ref(OWNER))

    const loading = browse.loadLocation(STATION_ID)
    browse.reset()
    pending.resolve(new Response(JSON.stringify({ locationId: STATION_ID, items: [{ itemId: 1, typeId: 34 }] }), { status: 200 }))
    await loading

    expect(browse.rootRows.value.size).toBe(0)
    expect(browse.summary.value).toBeNull()
  })

  it('reports a failed content request instead of inventing rows', async () => {
    fetchMock = vi.fn(async () => new Response('{}', { status: 500 }))
    const browse = useAssetBrowse(ref(OWNER))

    await expect(browse.loadLocation(STATION_ID)).rejects.toThrow('HTTP 500')
    expect(browse.rootRows.value.has(STATION_ID)).toBe(false)
  })
})
