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
const MAIN_CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const ALT_CHARACTER_ID = '00000000-0000-4000-8000-0000000000c2'
const FOREIGN_CHARACTER_ID = '00000000-0000-4000-8000-0000000000c9'
const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const LAST_CHARACTER_STORAGE_KEY = `assetsLastCharacterId:${USER_ID}`
const CORPORATION_LOCATIONS_ENDPOINT = '/api/me/corporation/assets/locations'

const rawAssets = [
  { id: '00000000-0000-4000-8000-000000000001', itemId: 1, typeId: 587, typeName: 'Rifter', quantity: 1, categoryId: 6, locationId: 1035466617946, locationName: HOME_STATION, locationType: 'other', locationFlag: 'Hangar', solarSystemId: 30004711, solarSystemName: 'Home System', itemName: null, cachedAt: '2026-11-12T10:00:00+00:00' },
  { id: '00000000-0000-4000-8000-000000000002', itemId: 2, typeId: 34, typeName: 'Tritanium', quantity: 8800, categoryId: 4, locationId: 60003760, locationName: MARKET_STATION, locationType: 'station', locationFlag: 'Hangar', solarSystemId: 30000142, solarSystemName: 'Jita', itemName: null, cachedAt: '2026-11-12T10:00:00+00:00' },
  { id: '00000000-0000-4000-8000-000000000003', itemId: 3, typeId: 3465, typeName: 'Large Secure Container', quantity: 1, categoryId: 2, locationId: 1035466617946, locationName: HOME_STATION, locationType: 'other', locationFlag: 'Hangar', solarSystemId: 30004711, solarSystemName: 'Home System', itemName: 'Project Box', cachedAt: '2026-11-12T10:00:00+00:00' },
  { id: '00000000-0000-4000-8000-000000000004', itemId: 4, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120, categoryId: 4, locationId: 3, locationName: `Project Box - ${HOME_STATION}`, locationType: 'item', locationFlag: 'Cargo', solarSystemId: 30004711, solarSystemName: 'Home System', itemName: null, cachedAt: '2026-11-12T10:00:00+00:00' },
]

function character(id: string, name: string, isMain: boolean) {
  return {
    id,
    eveCharacterId: Number(id.slice(-1)) + 2112000000,
    name,
    corporationId: 98000001,
    corporationName: 'Test Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

let fetchMock: ReturnType<typeof vi.fn>

async function mountAssets(query: LocationQuery = {}) {
  route.query = query
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: USER_ID,
    authStatus: 'valid',
    mainCharacter: null,
    characters: [character(MAIN_CHARACTER_ID, 'Main Pilot', true), character(ALT_CHARACTER_ID, 'Alt Pilot', false)],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as User
  fetchMock = vi.fn(assetBrowseFetch(rawAssets, url => (
    url.endsWith('/visibility')
      ? new Response(JSON.stringify({ isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null }), { status: 200 })
      : null
  )))
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

function requestedEndpoints(): string[] {
  return fetchMock.mock.calls.map(call => String(call[0])).filter(url => url.startsWith('/api/me/') && !url.endsWith('/visibility') && !url.endsWith('/packaged-volumes') && !url.endsWith('/assets/changes'))
}

async function openLocation(wrapper: Wrapper, locationName: string): Promise<void> {
  const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.find('h3').text() === locationName)
  if (!group) {
    throw new Error(`location "${locationName}" not rendered`)
  }
  await group.get('[aria-expanded]').trigger('click')
  await flushPromises()
}

function selectLabelled(wrapper: Wrapper, label: string) {
  const select = wrapper.findAll('select').find(candidate => candidate.attributes('aria-label') === label)
  if (!select) {
    throw new Error(`select "${label}" not rendered`)
  }
  return select
}

function searchInput(wrapper: Wrapper) {
  return wrapper.get(`input[aria-label="${en.assets.searchPlaceholder}"]`)
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

describe('Assets filters read from the URL', () => {
  it('opens the corporation view when the URL says view=corporation', async () => {
    await mountAssets({ view: 'corporation' })

    expect(requestedEndpoints()).toEqual(['/api/me/pinned-containers', CORPORATION_LOCATIONS_ENDPOINT])
  })

  it('opens the character named in the URL', async () => {
    await mountAssets({ character: ALT_CHARACTER_ID })

    expect(requestedEndpoints()).toEqual([`/api/me/characters/${ALT_CHARACTER_ID}/assets/locations`])
  })

  it('ignores a character that does not belong to the user', async () => {
    await mountAssets({ character: FOREIGN_CHARACTER_ID })

    expect(requestedEndpoints()).toEqual([`/api/me/characters/${MAIN_CHARACTER_ID}/assets/locations`])
  })

  it('fills the search box and filters the list with q', async () => {
    const wrapper = await mountAssets({ q: 'trit' })

    expect((searchInput(wrapper).element as HTMLInputElement).value).toBe('trit')
    expect(wrapper.text()).toContain('Tritanium')
    expect(wrapper.text()).not.toContain('Rifter')
  })

  it('opens the containers holding a match of q', async () => {
    const wrapper = await mountAssets({ q: 'hydrogen' })

    expect(wrapper.text()).toContain('Hydrogen Fuel Block')
  })

  it('applies the solar system and the location of the URL', async () => {
    const wrapper = await mountAssets({ system: 'Jita', location: MARKET_STATION })
    await openLocation(wrapper, MARKET_STATION)

    expect((selectLabelled(wrapper, en.assets.solarSystem).element as HTMLSelectElement).value).toBe('Jita')
    expect((selectLabelled(wrapper, en.assets.location).element as HTMLSelectElement).value).toBe(MARKET_STATION)
    expect(wrapper.text()).toContain('Tritanium')
    expect(wrapper.text()).not.toContain('Rifter')
  })

  it('drops a system that the loaded assets do not contain', async () => {
    const wrapper = await mountAssets({ system: 'Nowhere' })
    await openLocation(wrapper, HOME_STATION)

    expect((selectLabelled(wrapper, en.assets.solarSystem).element as HTMLSelectElement).value).toBe('')
    expect(wrapper.text()).toContain('Rifter')
  })
})

describe('Assets filters written to the URL', () => {
  it('writes the search text to q', async () => {
    const wrapper = await mountAssets()

    await searchInput(wrapper).setValue('rifter')

    expect(lastReplacedQuery().q).toBe('rifter')
  })

  it('removes q when the search is cleared', async () => {
    const wrapper = await mountAssets({ q: 'rifter' })

    await searchInput(wrapper).setValue('')

    expect(lastReplacedQuery()).not.toHaveProperty('q')
  })

  it('writes the solar system and the location', async () => {
    const wrapper = await mountAssets()

    await selectLabelled(wrapper, en.assets.solarSystem).setValue('Jita')
    await selectLabelled(wrapper, en.assets.location).setValue(MARKET_STATION)

    expect(lastReplacedQuery()).toMatchObject({ system: 'Jita', location: MARKET_STATION })
  })

  it('writes the selected character', async () => {
    const wrapper = await mountAssets()

    await selectLabelled(wrapper, en.assets.selectCharacter).setValue(ALT_CHARACTER_ID)

    expect(lastReplacedQuery().character).toBe(ALT_CHARACTER_ID)
  })

  it('writes view=corporation and drops the character and the filters when the owner changes', async () => {
    const wrapper = await mountAssets({ character: MAIN_CHARACTER_ID, q: 'rifter' })

    await wrapper.findAll('button').find(button => button.text() === en.assets.corporationAssets)?.trigger('click')
    await flushPromises()

    const query = lastReplacedQuery()
    expect(query.view).toBe('corporation')
    expect(query).not.toHaveProperty('character')
    expect(query).not.toHaveProperty('q')
  })

  it('keeps the query parameters it does not own', async () => {
    const wrapper = await mountAssets({ tab: 'wealth' })

    await searchInput(wrapper).setValue('rifter')

    expect(lastReplacedQuery()).toMatchObject({ tab: 'wealth', q: 'rifter' })
  })
})

describe('Assets location sort in the URL', () => {
  function locationOrder(wrapper: Wrapper): string[] {
    return wrapper.findAll('[data-testid="location-group"] h3').map(heading => heading.text())
  }

  it('sorts by item count when the URL has no sort', async () => {
    const wrapper = await mountAssets()

    expect(locationOrder(wrapper)).toEqual([MARKET_STATION, HOME_STATION])
  })

  it('sorts by name when the URL says sort=name', async () => {
    const wrapper = await mountAssets({ sort: 'name' })

    expect((wrapper.get('[data-testid="location-sort"]').element as HTMLSelectElement).value).toBe('name')
    expect(locationOrder(wrapper)).toEqual([HOME_STATION, MARKET_STATION])
  })

  it('ignores an unknown sort value', async () => {
    const wrapper = await mountAssets({ sort: 'price' })

    expect((wrapper.get('[data-testid="location-sort"]').element as HTMLSelectElement).value).toBe('items')
    expect(locationOrder(wrapper)).toEqual([MARKET_STATION, HOME_STATION])
  })

  it('writes sort=name when the sort changes', async () => {
    const wrapper = await mountAssets()

    await wrapper.get('[data-testid="location-sort"]').setValue('name')

    expect(lastReplacedQuery().sort).toBe('name')
  })

  it('removes sort when the default sort comes back', async () => {
    const wrapper = await mountAssets({ sort: 'name' })

    await wrapper.get('[data-testid="location-sort"]').setValue('items')

    expect(lastReplacedQuery()).not.toHaveProperty('sort')
  })

  it('keeps the other filters when the sort changes', async () => {
    const wrapper = await mountAssets({ q: 'trit' })

    await wrapper.get('[data-testid="location-sort"]').setValue('name')

    expect(lastReplacedQuery()).toMatchObject({ q: 'trit', sort: 'name' })
  })
})

describe('Assets remembers the last character', () => {
  it('stores the selected character for the user', async () => {
    const wrapper = await mountAssets()

    await selectLabelled(wrapper, en.assets.selectCharacter).setValue(ALT_CHARACTER_ID)

    expect(localStorage.getItem(LAST_CHARACTER_STORAGE_KEY)).toBe(ALT_CHARACTER_ID)
  })

  it('opens the remembered character when the URL names none', async () => {
    localStorage.setItem(LAST_CHARACTER_STORAGE_KEY, ALT_CHARACTER_ID)

    await mountAssets()

    expect(requestedEndpoints()).toEqual([`/api/me/characters/${ALT_CHARACTER_ID}/assets/locations`])
  })

  it('prefers the character of the URL over the remembered one', async () => {
    localStorage.setItem(LAST_CHARACTER_STORAGE_KEY, ALT_CHARACTER_ID)

    await mountAssets({ character: MAIN_CHARACTER_ID })

    expect(requestedEndpoints()).toEqual([`/api/me/characters/${MAIN_CHARACTER_ID}/assets/locations`])
  })

  it('falls back to the main character when the remembered one is gone', async () => {
    localStorage.setItem(LAST_CHARACTER_STORAGE_KEY, FOREIGN_CHARACTER_ID)

    await mountAssets()

    expect(requestedEndpoints()).toEqual([`/api/me/characters/${MAIN_CHARACTER_ID}/assets/locations`])
  })
})
