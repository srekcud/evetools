import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import IndustryHangars from '@/views/IndustryHangars.vue'
import { useAuthStore } from '@/stores/auth'
import type { CorpAssetVisibility, User } from '@/types'

const routerPush = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ push: routerPush, replace: vi.fn(), resolve: vi.fn() })) as never,
}))

const BOB_ID = '0199a1f0-0000-7000-8000-000000571002'
const MINERALS_DIVISION = 1
const FUEL_DIVISION = 2
const AMMO_DIVISION = 3
const VISIBILITY_URL = '/api/me/corporation/assets/visibility'
const NO_DIRECTOR_WARNING = '[data-testid="hangars-no-director"]'
const UNKNOWN_VALUE = '[data-testid="unknown-value"]'
const ERROR = '[data-testid="hangars-error"]'
const BACK = '[data-testid="hangars-back"]'
const ROWS = '[data-testid^="hangars-division-"]'

function visibility(overrides: Partial<CorpAssetVisibility> = {}): CorpAssetVisibility {
  return {
    visibleDivisions: [FUEL_DIVISION],
    restrictedDivisions: { [MINERALS_DIVISION]: [{ id: BOB_ID, name: 'Bob Tamm' }] },
    accessibleDivisions: [FUEL_DIVISION],
    players: [{ id: BOB_ID, name: 'Bob Tamm' }],
    directorConnected: true,
    allDivisions: { [MINERALS_DIVISION]: 'Minerals', [FUEL_DIVISION]: 'Fuel', [AMMO_DIVISION]: 'Ammo' },
    isDirector: false,
    configuredByName: 'Aria Solberg',
    updatedAt: '2026-11-07T20:00:00+00:00',
    ...overrides,
  }
}

function stubVisibility(response: CorpAssetVisibility | 'failure') {
  const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => response === 'failure'
    ? new Response('{}', { status: 500 })
    : new Response(JSON.stringify(response), { status: 200 }))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

async function mountView(): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = { id: 'user-1', authStatus: 'valid', mainCharacter: null, characters: [], corporationId: 98_000_001, corporationName: 'Test Corp' } as User
  const wrapper = mount(IndustryHangars, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'fr', messages: { fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' } },
    },
  })
  await flushPromises()
  return wrapper
}

function rowOf(wrapper: VueWrapper, division: number) {
  return wrapper.get(`[data-testid="hangars-division-${division}"]`)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  routerPush.mockClear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('IndustryHangars', () => {
  it('shows, for each division, whether all members, a list of players or nobody sees it', async () => {
    const fetchMock = stubVisibility(visibility())

    const wrapper = await mountView()

    expect(fetchMock).toHaveBeenCalledWith(VISIBILITY_URL, expect.anything())
    expect(wrapper.findAll(ROWS)).toHaveLength(3)
    expect(rowOf(wrapper, MINERALS_DIVISION).text()).toContain('Minerals')
    expect(rowOf(wrapper, MINERALS_DIVISION).text()).toContain('Liste')
    expect(rowOf(wrapper, MINERALS_DIVISION).text()).toContain('Bob Tamm')
    expect(rowOf(wrapper, FUEL_DIVISION).text()).toContain('Tous les membres')
    expect(rowOf(wrapper, AMMO_DIVISION).text()).toContain('Masquée')
    expect(wrapper.find(NO_DIRECTOR_WARNING).exists()).toBe(false)
    expect(wrapper.find(UNKNOWN_VALUE).exists()).toBe(false)
  })

  it('reads the setting without editing it for a member who is not a director', async () => {
    stubVisibility(visibility({ isDirector: false }))

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Aria Solberg')
    expect(wrapper.find('[data-testid="visibility-panel-toggle"]').exists()).toBe(false)
  })

  it('shows the visibility as unknown, not hidden, when no director is connected', async () => {
    stubVisibility(visibility({ directorConnected: false, visibleDivisions: [], restrictedDivisions: {}, accessibleDivisions: [] }))

    const wrapper = await mountView()

    expect(wrapper.find(NO_DIRECTOR_WARNING).exists()).toBe(true)
    expect(wrapper.findAll(UNKNOWN_VALUE)).toHaveLength(3)
    expect(wrapper.text()).not.toContain('Masquée')
  })

  it('lets a director change the visibility of a division and saves it', async () => {
    const fetchMock = stubVisibility(visibility({ isDirector: true }))
    const wrapper = await mountView()

    await wrapper.get('[data-testid="visibility-panel-toggle"]').trigger('click')
    await wrapper.get(`[data-testid="division-${AMMO_DIVISION}-mode-all"]`).trigger('click')
    await wrapper.get('[data-testid="save"]').trigger('click')
    await flushPromises()

    const saveCall = fetchMock.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
    expect(JSON.parse((saveCall?.[1] as RequestInit).body as string)).toEqual({
      visibleDivisions: [FUEL_DIVISION, AMMO_DIVISION],
      restrictedDivisions: { [MINERALS_DIVISION]: [BOB_ID] },
    })
  })

  it('shows an error and no division when the visibility cannot be loaded', async () => {
    stubVisibility('failure')

    const wrapper = await mountView()

    expect(wrapper.find(ERROR).exists()).toBe(true)
    expect(wrapper.findAll(ROWS)).toHaveLength(0)
  })

  it('goes back to the workshop', async () => {
    stubVisibility(visibility())
    const wrapper = await mountView()

    await wrapper.get(BACK).trigger('click')

    expect(routerPush).toHaveBeenCalledExactlyOnceWith({ name: 'industry' })
  })
})
