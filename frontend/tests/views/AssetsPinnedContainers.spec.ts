import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { PinnedContainer, User } from '@/types'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

const HOME_STATION = 'Home Station'
const ALPHA_BOX_ITEM_ID = 6
const PROJECT_BOX_ITEM_ID = 3
const FUEL_BLOCK_NAME = 'Hydrogen Fuel Block'

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
  corpAsset({ id: '00000000-0000-4000-8000-000000000006', itemId: ALPHA_BOX_ITEM_ID, typeId: 3465, typeName: 'Large Secure Container', quantity: 1, itemName: 'Alpha Box' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000007', itemId: 7, typeId: 36, typeName: 'Mexallon', quantity: 5, locationId: ALPHA_BOX_ITEM_ID, locationType: 'item', locationFlag: 'Unlocked' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000003', itemId: PROJECT_BOX_ITEM_ID, typeId: 3465, typeName: 'Large Secure Container', quantity: 1, itemName: 'Project Box' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000004', itemId: 4, typeId: 4246, typeName: FUEL_BLOCK_NAME, quantity: 120, locationId: PROJECT_BOX_ITEM_ID, locationType: 'item', locationFlag: 'Unlocked' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000002', itemId: 2, typeId: 35, typeName: 'Pyerite', quantity: 200, locationFlag: 'CorpSAG2', divisionName: 'Stock' }),
]

function pinned(overrides: Partial<PinnedContainer>): PinnedContainer {
  return {
    id: String(PROJECT_BOX_ITEM_ID),
    itemId: PROJECT_BOX_ITEM_ID,
    name: 'Project Box',
    locationName: HOME_STATION,
    solarSystemName: 'Home System',
    divisionName: 'Production',
    itemCount: 120,
    found: true,
    projectId: 'p1',
    projectName: 'Capital rush',
    ...overrides,
  }
}

let pinnedEntries: PinnedContainer[]
let fetchMock: ReturnType<typeof vi.fn>

async function mountAssets(query: LocationQuery = { view: 'corporation' }) {
  route.query = query
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [{
      id: '00000000-0000-4000-8000-0000000000c1',
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
  fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.includes('/me/pinned-containers')) {
      return new Response(JSON.stringify(pinnedEntries), { status: 200 })
    }
    if (url.endsWith('/visibility')) {
      return new Response(JSON.stringify({ isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null }), { status: 200 })
    }
    return new Response(JSON.stringify({ items: rawCorporationAssets }), { status: 200 })
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

function stars(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="container-pin"]')
}

function starOf(wrapper: Wrapper, containerName: string) {
  const star = stars(wrapper).find(candidate => candidate.attributes('aria-label')?.includes(containerName))
  if (!star) {
    throw new Error(`star of ${containerName} not rendered`)
  }
  return star
}

function calls(method: string): [string, RequestInit][] {
  return fetchMock.mock.calls
    .map(call => [String(call[0]), (call[1] ?? {}) as RequestInit] as [string, RequestInit])
    .filter(([url, init]) => url.includes('/me/pinned-containers') && (init.method ?? 'GET') === method)
}

describe('Assets pinned containers', () => {
  enableAutoUnmount(afterEach)

  beforeEach(() => {
    pinnedEntries = []
    routerReplace.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the pinned containers panel in the corporation view only', async () => {
    pinnedEntries = [pinned({})]

    const corporation = await mountAssets({ view: 'corporation' })
    expect(corporation.find('[data-testid="pinned-panel"]').exists()).toBe(true)
    expect(corporation.get('[data-testid="pinned-panel"]').text()).toContain('Capital rush · 1 container watched')

    const character = await mountAssets({})
    expect(character.find('[data-testid="pinned-panel"]').exists()).toBe(false)
    expect(stars(character)).toHaveLength(0)
  })

  it('opens the location and the container of an entry, clearing the search and the division filter', async () => {
    pinnedEntries = [pinned({})]
    const wrapper = await mountAssets({ view: 'corporation', division: 'Stock', q: 'Pyerite' })
    expect(wrapper.text()).not.toContain(FUEL_BLOCK_NAME)

    await wrapper.get('[data-testid="pinned-entry"]').trigger('click')
    await flushPromises()

    const lastQuery = routerReplace.mock.calls.at(-1)?.[0].query as LocationQuery
    expect(lastQuery.q).toBeUndefined()
    expect(lastQuery.division).toBeUndefined()
    expect(wrapper.text()).toContain(FUEL_BLOCK_NAME)
  })

  it('lists the containers pinned by a project first in their location', async () => {
    pinnedEntries = [pinned({})]

    const wrapper = await mountAssets()

    const rowTexts = wrapper.get('[data-testid="location-group"]').text()
    expect(rowTexts.indexOf('Project Box')).toBeLessThan(rowTexts.indexOf('Alpha Box'))
  })

  it('pins a container from its star and reloads the pins', async () => {
    const wrapper = await mountAssets()
    expect(starOf(wrapper, 'Alpha Box').attributes('aria-pressed')).toBe('false')
    pinnedEntries = [pinned({ id: String(ALPHA_BOX_ITEM_ID), itemId: ALPHA_BOX_ITEM_ID, name: 'Alpha Box', projectId: null, projectName: null })]

    await starOf(wrapper, 'Alpha Box').trigger('click')
    await flushPromises()

    expect(calls('POST')).toHaveLength(1)
    expect(JSON.parse(String(calls('POST')[0][1].body))).toEqual({ itemId: ALPHA_BOX_ITEM_ID })
    expect(wrapper.get('[data-testid="pinned-panel"]').text()).toContain('My pinned containers')
    expect(starOf(wrapper, 'Alpha Box').attributes('aria-pressed')).toBe('true')
  })

  it('unpins a pinned container from its star', async () => {
    pinnedEntries = [pinned({ id: String(ALPHA_BOX_ITEM_ID), itemId: ALPHA_BOX_ITEM_ID, name: 'Alpha Box', projectId: null, projectName: null })]
    const wrapper = await mountAssets()
    pinnedEntries = []

    await starOf(wrapper, 'Alpha Box').trigger('click')
    await flushPromises()

    expect(calls('DELETE').map(([url]) => url)).toEqual([`/api/me/pinned-containers/${ALPHA_BOX_ITEM_ID}`])
    expect(wrapper.find('[data-testid="pinned-panel"]').exists()).toBe(false)
  })

  it('does not let a container pinned by a project be unpinned', async () => {
    pinnedEntries = [pinned({})]
    const wrapper = await mountAssets()

    const star = starOf(wrapper, 'Project Box')
    await star.trigger('click')

    expect(star.attributes('disabled')).toBeDefined()
    expect(calls('DELETE')).toHaveLength(0)
  })
})
