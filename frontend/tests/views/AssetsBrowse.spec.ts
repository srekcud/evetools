import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch, endpointCalls } from '../assetBrowseApi'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const HOME_STATION_ID = 60003760
const HOME_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const CONTAINER_ITEM_ID = 1050000000201
const FIRST_FILLER_STATION_ID = 61000000
const FILLER_STATION_COUNT = 11
const SEARCH_DEBOUNCE_MS = 300
const OWNER = `/api/me/characters/${CHARACTER_ID}/assets`

function asset(overrides: Record<string, unknown>): Record<string, unknown> {
  return {
    categoryId: 4,
    locationId: HOME_STATION_ID,
    locationName: HOME_STATION_NAME,
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

function fillerAsset(index: number) {
  return asset({
    id: `filler-${index}`,
    itemId: 2050000000000 + index,
    typeId: 36,
    typeName: 'Mexallon',
    quantity: 5,
    locationId: FIRST_FILLER_STATION_ID + index,
    locationName: `Filler Station ${index}`,
    solarSystemName: `Filler System ${index}`,
  })
}

const homeAssets = [
  asset({ id: 'h1', itemId: 1050000000101, typeId: 34, typeName: 'Tritanium', quantity: 8800 }),
  asset({ id: 'h2', itemId: CONTAINER_ITEM_ID, typeId: 3296, typeName: 'Station Container', quantity: 1, itemName: '1_Srek' }),
  asset({ id: 'h3', itemId: 1050000000301, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120, locationId: CONTAINER_ITEM_ID, locationType: 'item', locationFlag: 'Unlocked' }),
  asset({ id: 'h4', itemId: 1050000000302, typeId: 4247, typeName: 'Oxygen Fuel Block', quantity: 45, locationId: CONTAINER_ITEM_ID, locationType: 'item', locationFlag: 'Unlocked' }),
]

const smallInventory = homeAssets.map(row => (row.typeName === 'Tritanium' ? { ...row, quantity: 10 } : row))

const largeInventory = [...homeAssets, ...Array.from({ length: FILLER_STATION_COUNT }, (_, index) => fillerAsset(index))]

let fetchMock: ReturnType<typeof vi.fn>

async function mountAssets(rawAssets: Record<string, unknown>[] = largeInventory, serve: (input: RequestInfo | URL) => Promise<Response> = assetBrowseFetch(rawAssets)) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [{
      id: CHARACTER_ID,
      eveCharacterId: 2112000001,
      name: 'Test Pilot',
      corporationId: 98000001,
      corporationName: 'Test Corp',
      allianceId: null,
      allianceName: null,
      isMain: true,
      hasValidToken: true,
      lastSyncAt: null,
    }],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as User
  fetchMock = vi.fn(serve)
  vi.stubGlobal('fetch', fetchMock)
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PatrimoineModuleTabs: true,
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

type MountedAssets = Awaited<ReturnType<typeof mountAssets>>

function callsTo(pattern: RegExp): string[] {
  return endpointCalls(fetchMock, pattern)
}

function locationGroup(wrapper: MountedAssets, locationName: string) {
  const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.find('h3').text() === locationName)
  if (!group) {
    throw new Error(`location "${locationName}" not rendered`)
  }
  return group
}

async function openLocation(wrapper: MountedAssets, locationName: string): Promise<void> {
  await locationGroup(wrapper, locationName).get('[aria-expanded]').trigger('click')
  await flushPromises()
}

async function typeSearch(wrapper: MountedAssets, query: string): Promise<void> {
  await wrapper.get(`input[aria-label="${en.assets.searchPlaceholder}"]`).setValue(query)
  await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
  await flushPromises()
}

const text = (wrapper: MountedAssets) => wrapper.text().replace(/\s+/g, ' ')

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('Assets browse: summary first', () => {
  it('reads one summary and no contents when the inventory has many locations', async () => {
    const wrapper = await mountAssets()

    expect(callsTo(/\/assets\/locations$/)).toEqual([`${OWNER}/locations`])
    expect(callsTo(/\/assets$|\/locations\/|\/containers\/|\/search/)).toEqual([])
    expect(wrapper.findAll('[data-testid="location-group"]')).toHaveLength(FILLER_STATION_COUNT + 1)
    expect(text(wrapper)).not.toContain('Tritanium')
  })

  it('opens the locations of a small inventory by itself and loads their contents', async () => {
    const wrapper = await mountAssets(smallInventory)

    expect(callsTo(new RegExp(`/locations/${HOME_STATION_ID}$`))).toHaveLength(1)
    expect(text(wrapper)).toContain('Tritanium')
  })

  it('reads the statistics from the summary', async () => {
    const wrapper = await mountAssets()

    const totals = (label: string) => wrapper.findAll('p').find(paragraph => paragraph.text() === label)?.element.parentElement?.textContent ?? ''
    expect(totals(en.assets.stats.totalItems)).toContain('9,021')
    expect(totals(en.assets.stats.locations)).toContain('12')
  })
})

describe('Assets browse: contents on expansion', () => {
  it('loads a location once when it is opened, then a container when it is opened', async () => {
    const wrapper = await mountAssets()

    await openLocation(wrapper, HOME_STATION_NAME)
    expect(callsTo(new RegExp(`/locations/${HOME_STATION_ID}$`))).toHaveLength(1)
    expect(text(wrapper)).toContain('Tritanium')
    expect(text(wrapper)).not.toContain('Hydrogen Fuel Block')

    await wrapper.get('[role="button"][aria-expanded="false"][tabindex="0"]:not([data-testid="location-select"])').trigger('click')
    await flushPromises()

    expect(callsTo(new RegExp(`/containers/${CONTAINER_ITEM_ID}$`))).toHaveLength(1)
    expect(text(wrapper)).toContain('Hydrogen Fuel Block')
  })

  it('does not call the server again when a loaded location is reopened', async () => {
    const wrapper = await mountAssets()
    await openLocation(wrapper, HOME_STATION_NAME)

    await openLocation(wrapper, HOME_STATION_NAME)
    await openLocation(wrapper, HOME_STATION_NAME)

    expect(callsTo(new RegExp(`/locations/${HOME_STATION_ID}$`))).toHaveLength(1)
  })
})

describe('Assets browse: server search', () => {
  it('searches on the server after the typing pause and opens the matching container', async () => {
    const wrapper = await mountAssets()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

    await typeSearch(wrapper, 'hydrogen')

    expect(callsTo(/\/search\?q=hydrogen$/)).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="location-group"]')).toHaveLength(1)
    expect(text(wrapper)).toContain('Hydrogen Fuel Block')
    expect(text(wrapper)).not.toContain('Oxygen Fuel Block')
    expect(callsTo(new RegExp(`/containers/${CONTAINER_ITEM_ID}$`))).toHaveLength(1)
  })

  it('filters the locations locally and sends nothing for a single character', async () => {
    const wrapper = await mountAssets()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

    await typeSearch(wrapper, 'j')

    expect(callsTo(/\/search/)).toEqual([])
    expect(callsTo(/\/assets$/)).toEqual([])
    expect(wrapper.findAll('[data-testid="location-group"]')).toHaveLength(1)
  })
})

describe('Assets browse: functions that read the whole list', () => {
  it('loads the full list when the by-type view is chosen', async () => {
    const wrapper = await mountAssets()

    expect(callsTo(/\/assets$/)).toEqual([])
    const byType = wrapper.findAll('button').find(button => button.text() === en.assets.groupBy.type)
    await byType?.trigger('click')
    await flushPromises()

    expect(callsTo(/\/assets$/)).toEqual([OWNER])
    expect(wrapper.findAll('[data-testid="asset-type-row"]').length).toBeGreaterThan(0)
  })

  it('loads the full list once a location is selected and shows the selection', async () => {
    const wrapper = await mountAssets()

    expect(callsTo(/\/assets$/)).toEqual([])
    await wrapper.get(`[aria-label="Select ${HOME_STATION_NAME}"]`).trigger('click')
    await flushPromises()

    expect(callsTo(/\/assets$/)).toEqual([OWNER])
    expect(wrapper.get('[data-testid="asset-selection-bar"]').text()).toContain('8,966 items')
  })

  it('reads the asset safety block from the summary without loading contents', async () => {
    const safetyAsset = asset({
      id: 'safety-1',
      itemId: 3050000000001,
      typeId: 587,
      typeName: 'Rifter',
      quantity: 1,
      isSingleton: true,
      categoryId: 6,
      locationId: 1050000000999,
      locationName: 'Asset Safety (Misaba)',
      locationType: 'other',
      solarSystemName: 'Misaba',
    })

    const wrapper = await mountAssets([...largeInventory, safetyAsset])

    expect(wrapper.find('[data-testid="asset-safety-block"]').exists()).toBe(true)
    expect(callsTo(/\/assets$|\/locations\/|\/containers\//)).toEqual([])
  })
})

describe('Assets browse: failures', () => {
  it('shows the load failure when the summary cannot be read', async () => {
    const wrapper = await mountAssets([], async () => new Response('{}', { status: 500 }))

    expect(callsTo(/\/assets\/locations$/)).toHaveLength(1)
    expect(wrapper.text()).toContain(en.assets.loadFailed)
  })

  it('shows the load failure when the contents of a location cannot be read', async () => {
    const inventory = assetBrowseFetch(largeInventory)
    const wrapper = await mountAssets(largeInventory, async input => (/\/locations\/\d+$/.test(String(input)) ? new Response('{}', { status: 500 }) : inventory(input)))

    await openLocation(wrapper, HOME_STATION_NAME)

    expect(wrapper.text()).toContain(en.assets.loadFailed)
  })
})
