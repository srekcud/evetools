import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

const USER_ID = '00000000-0000-4000-8000-0000000000u1'
const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const CONTAINER_ITEM_ID = 3

function corpAsset(overrides: Record<string, unknown>) {
  return {
    categoryId: 4,
    locationId: 1035466617946,
    locationName: HOME_STATION,
    locationType: 'other',
    locationFlag: 'CorpSAG1',
    divisionName: 'Production',
    solarSystemId: 30004711,
    solarSystemName: 'Home System',
    itemName: null,
    cachedAt: '2026-11-12T10:00:00+00:00',
    ...overrides,
  }
}

const rawCorporationAssets = [
  corpAsset({ id: '00000000-0000-4000-8000-000000000001', itemId: 1, typeId: 34, typeName: 'Tritanium', quantity: 8800 }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000002', itemId: 2, typeId: 35, typeName: 'Pyerite', quantity: 200, locationFlag: 'CorpSAG2', divisionName: 'Stock' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000003', itemId: CONTAINER_ITEM_ID, typeId: 3465, typeName: 'Large Secure Container', quantity: 1, itemName: 'Project Box' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000004', itemId: 4, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120, locationId: CONTAINER_ITEM_ID, locationName: `Project Box - ${HOME_STATION}`, locationType: 'item', locationFlag: 'Unlocked' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000005', itemId: 5, typeId: 36, typeName: 'Mexallon', quantity: 50, locationId: 60003760, locationName: MARKET_STATION, locationType: 'station', solarSystemId: 30000142, solarSystemName: 'Jita', locationFlag: 'CorpDeliveries', divisionName: null }),
]

const rawCharacterAssets = [
  { id: '00000000-0000-4000-8000-000000000011', itemId: 11, typeId: 587, typeName: 'Rifter', quantity: 1, categoryId: 6, locationId: 1035466617946, locationName: HOME_STATION, locationType: 'other', locationFlag: 'Hangar', divisionName: null, solarSystemId: 30004711, solarSystemName: 'Home System', itemName: null, cachedAt: '2026-11-12T10:00:00+00:00' },
]

let fetchMock: ReturnType<typeof vi.fn>

async function mountAssets(query: LocationQuery = { view: 'corporation' }) {
  route.query = query
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: USER_ID,
    authStatus: 'valid',
    mainCharacter: null,
    characters: [{
      id: CHARACTER_ID,
      eveCharacterId: 2112000001,
      name: 'Main Pilot',
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
  const corporationBrowse = assetBrowseFetch(rawCorporationAssets)
  const characterBrowse = assetBrowseFetch(rawCharacterAssets)
  fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/visibility')) {
      return new Response(JSON.stringify({ isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null }), { status: 200 })
    }
    return url.includes('/me/corporation/assets') ? corporationBrowse(input, init) : characterBrowse(input, init)
  })
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

type Wrapper = Awaited<ReturnType<typeof mountAssets>>

function chips(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="division-chip"]')
}

function chipLabelled(wrapper: Wrapper, text: string) {
  const chip = chips(wrapper).find(candidate => candidate.text().replace(/\s+/g, ' ').includes(text))
  if (!chip) {
    throw new Error(`division chip "${text}" not rendered`)
  }
  return chip
}

function locationGroup(wrapper: Wrapper, locationName: string) {
  const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.find('h3').text() === locationName)
  if (!group) {
    throw new Error(`location group "${locationName}" not rendered`)
  }
  return group
}

async function chooseChip(wrapper: Wrapper, text: string): Promise<void> {
  await chipLabelled(wrapper, text).trigger('click')
  await flushPromises()
}

async function openLocation(wrapper: Wrapper, locationName: string): Promise<void> {
  const header = locationGroup(wrapper, locationName).get('[aria-expanded]')
  if (header.attributes('aria-expanded') === 'false') {
    await header.trigger('click')
    await flushPromises()
  }
}

function totalItemsCard(wrapper: Wrapper): string {
  const card = wrapper.findAll('p').find(paragraph => paragraph.text() === en.assets.stats.totalItems)
  if (!card) {
    throw new Error('total items card not rendered')
  }
  return card.element.previousElementSibling?.textContent ?? ''
}

function lastReplacedQuery(): LocationQuery {
  const lastCall = routerReplace.mock.calls.at(-1)
  if (!lastCall) {
    throw new Error('router.replace was never called')
  }
  return lastCall[0].query
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  routerReplace.mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Assets corporation division chips', () => {
  it('offers All plus one chip per division with its row count, prefixed with Hangar', async () => {
    const wrapper = await mountAssets()

    const labels = chips(wrapper).map(chip => chip.text().replace(/\s+/g, ' '))

    expect(labels).toEqual([
      expect.stringContaining('All'),
      expect.stringContaining('Hangar - Production'),
      expect.stringContaining('Hangar - Stock'),
    ])
    expect(chipLabelled(wrapper, 'Hangar - Production').text()).toContain('8,921')
    expect(chipLabelled(wrapper, 'Hangar - Stock').text()).toContain('200')
    expect(chipLabelled(wrapper, 'All').text()).toContain('9,171')
  })

  it('does not show the chips in the character view', async () => {
    const wrapper = await mountAssets({})

    expect(chips(wrapper)).toHaveLength(0)
  })

  it('keeps only the rows of the chosen division', async () => {
    const wrapper = await mountAssets()

    await chooseChip(wrapper, 'Hangar - Stock')
    await openLocation(wrapper, HOME_STATION)
    const text = wrapper.text().replace(/\s+/g, ' ')

    expect(text).toContain('Pyerite')
    expect(text).not.toContain('Tritanium')
    expect(text).not.toContain('Mexallon')
  })

  it('keeps the contents of a container of the chosen division', async () => {
    const wrapper = await mountAssets()

    await chooseChip(wrapper, 'Hangar - Production')
    await openLocation(wrapper, HOME_STATION)
    await wrapper.get('[role="button"][aria-expanded="false"][tabindex="0"]:not([data-testid="division-chip"])').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Project Box (Large Secure Container)')
  })

  it('filters the item count card with the division', async () => {
    const wrapper = await mountAssets()
    expect(totalItemsCard(wrapper)).toBe('9,171')

    await chooseChip(wrapper, 'Hangar - Stock')

    expect(totalItemsCard(wrapper)).toBe('200')
  })

  it('marks the chosen chip as pressed and All again clears the filter', async () => {
    const wrapper = await mountAssets()

    await chooseChip(wrapper, 'Hangar - Stock')
    expect(chipLabelled(wrapper, 'Hangar - Stock').attributes('aria-pressed')).toBe('true')

    await chooseChip(wrapper, 'All')

    await openLocation(wrapper, HOME_STATION)

    expect(chipLabelled(wrapper, 'All').attributes('aria-pressed')).toBe('true')
    expect(wrapper.text()).toContain('Tritanium')
  })

  it('writes the chosen division in the URL and reads it back', async () => {
    const wrapper = await mountAssets()
    await chooseChip(wrapper, 'Hangar - Stock')
    expect(lastReplacedQuery().division).toBe('Stock')

    const reopened = await mountAssets({ view: 'corporation', division: 'Stock' })
    await openLocation(reopened, HOME_STATION)

    expect(reopened.text()).toContain('Pyerite')
    expect(reopened.text()).not.toContain('Tritanium')
  })

  it('drops a division of the URL that the corporation does not have', async () => {
    const wrapper = await mountAssets({ view: 'corporation', division: 'Unknown' })
    await openLocation(wrapper, HOME_STATION)

    expect(wrapper.text()).toContain('Tritanium')
    expect(lastReplacedQuery().division).toBeUndefined()
  })
})

describe('Assets corporation division display', () => {
  it('breaks each location header down per division when All is selected', async () => {
    const wrapper = await mountAssets()

    const header = locationGroup(wrapper, HOME_STATION).get('[data-testid="location-division-breakdown"]').text().replace(/\s+/g, ' ')

    expect(header).toContain('Production')
    expect(header).toContain('Stock')
  })

  it('shows no division breakdown on a location without any division', async () => {
    const wrapper = await mountAssets()

    expect(locationGroup(wrapper, MARKET_STATION).find('[data-testid="location-division-breakdown"]').exists()).toBe(false)
  })

  it('drops the breakdown once a division is chosen', async () => {
    const wrapper = await mountAssets()

    await chooseChip(wrapper, 'Hangar - Stock')

    expect(wrapper.find('[data-testid="location-division-breakdown"]').exists()).toBe(false)
  })

  it('shows the division on each row instead of the raw CorpSAG flag', async () => {
    const wrapper = await mountAssets()
    await openLocation(wrapper, HOME_STATION)
    const rowDivisions = locationGroup(wrapper, HOME_STATION).findAll('[data-testid="asset-division"]').map(row => row.text())

    expect(wrapper.text()).not.toContain('CorpSAG')
    expect(rowDivisions).toEqual(['Hangar - Production', 'Hangar - Stock', 'Hangar - Production'])
  })

  it('keeps the raw flag of a row that has no division', async () => {
    const wrapper = await mountAssets()
    await openLocation(wrapper, MARKET_STATION)

    expect(locationGroup(wrapper, MARKET_STATION).text()).toContain('(CorpDeliveries)')
  })
})
