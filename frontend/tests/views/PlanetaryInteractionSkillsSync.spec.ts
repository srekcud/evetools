import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import type { Colony, PlanetarySlots } from '@/stores/planetary'
import { apiRequest } from '@/services/api'
import { deferred } from '../deferred'

const EMPTY_INPUT_NEEDS = { launchpads: [], colonies: [], extractions: [], weekly: [], complete: true }

const route = reactive<{ path: string; params: Record<string, string>; query: LocationQuery }>({ path: '/planetary', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ILYRA_ID = 2113840001
const DORAN_ID = 2113840002

const SKILLS_SYNC_ENDPOINT = '/industry/character-skills/sync'

function colony(overrides: Partial<Colony>): Colony {
  return {
    id: 'colony',
    characterId: ILYRA_ID,
    characterName: 'Ilyra Castell',
    planetId: 40009077,
    planetName: 'Jita IV',
    planetType: 'barren',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    solarSystemSecurity: 0.9,
    upgradeLevel: 4,
    tier: null,
    storageFull: null,
    numPins: 12,
    lastUpdate: '2026-10-22T09:00:00+00:00',
    cachedAt: '2026-10-22T09:05:00+00:00',
    extractorCount: 2,
    factoryCount: 6,
    activeExtractors: 2,
    nearestExpiry: '2026-10-24T09:00:00+00:00',
    factoryProgress: null,
    status: 'active',
    pins: [],
    routes: [],
    ...overrides,
  }
}

const COLONIES: Colony[] = [
  colony({ id: 'ilyra-barren', characterId: ILYRA_ID, characterName: 'Ilyra Castell', planetId: 40009077 }),
  colony({ id: 'doran-gas', characterId: DORAN_ID, characterName: 'Doran Vex', planetId: 40009081, planetName: 'Perimeter II', planetType: 'gas' }),
]

const KNOWN_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: ILYRA_ID, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 1, consolidationLevel: 4, maxColonies: 5 },
    { characterId: DORAN_ID, characterName: 'Doran Vex', isMain: false, coloniesUsed: 1, consolidationLevel: 2, maxColonies: 3 },
  ],
  totalUsed: 2,
  totalMax: 8,
}

const UNKNOWN_MAXIMUM_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: ILYRA_ID, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 1, consolidationLevel: null, maxColonies: null },
    { characterId: DORAN_ID, characterName: 'Doran Vex', isMain: false, coloniesUsed: 1, consolidationLevel: null, maxColonies: null },
  ],
  totalUsed: 2,
  totalMax: null,
}

const STILL_UNKNOWN_FOR_DORAN_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: ILYRA_ID, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 1, consolidationLevel: 4, maxColonies: 5 },
    { characterId: DORAN_ID, characterName: 'Doran Vex', isMain: false, coloniesUsed: 1, consolidationLevel: null, maxColonies: null },
  ],
  totalUsed: 2,
  totalMax: null,
}

interface MountOptions {
  slotsAfterSync?: PlanetarySlots
  syncResponse?: unknown
  syncError?: Error
}

async function mountView(initialSlots: PlanetarySlots, options: MountOptions = {}) {
  route.query = {}
  let slotsReads = 0
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return Promise.resolve(COLONIES)
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
    if (endpoint === '/planetary/slots') {
      slotsReads += 1
      return Promise.resolve(slotsReads === 1 ? initialSlots : (options.slotsAfterSync ?? initialSlots))
    }
    if (endpoint === '/planetary/input-needs') return Promise.resolve(EMPTY_INPUT_NEEDS)
    if (endpoint === SKILLS_SYNC_ENDPOINT) {
      if (options.syncError !== undefined) return Promise.reject(options.syncError)
      return Promise.resolve(options.syncResponse ?? { characters: [], failedCharacters: [] })
    }
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const wrapper = mount(PlanetaryInteraction, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        ErrorBanner: true,
        PiAlertsBanner: true,
        PiProductionTable: true,
        PiTierLegend: true,
        PiKpiCards: true,
        PiPlannerTab: true,
        RouterLink: { template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function banner(wrapper: Wrapper) {
  return wrapper.find('[data-testid="slots-skills-sync"]')
}

function syncButton(wrapper: Wrapper) {
  return wrapper.find('[data-testid="slots-skills-sync-button"]')
}

function skillsSyncCalls() {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SKILLS_SYNC_ENDPOINT)
}

function slotsReadCount() {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === '/planetary/slots').length
}

async function clickSync(wrapper: Wrapper) {
  await syncButton(wrapper).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

describe('PlanetaryInteraction skills sync when a colony maximum is unknown', () => {
  it('offers no skills sync when the maximum of every character is known', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    expect(banner(wrapper).exists()).toBe(false)
    expect(syncButton(wrapper).exists()).toBe(false)
  })

  it('offers no skills sync while the slots could not be loaded, an absent figure is not an unknown skill', async () => {
    const wrapper = await mountView(null as unknown as PlanetarySlots)

    expect(banner(wrapper).exists()).toBe(false)
  })

  it('shows a visible Sync skills action naming the Interplanetary Consolidation skill when one maximum is unknown', async () => {
    const wrapper = await mountView(STILL_UNKNOWN_FOR_DORAN_SLOTS)

    expect(banner(wrapper).exists()).toBe(true)
    expect(banner(wrapper).text()).toContain('Interplanetary Consolidation')
    expect(syncButton(wrapper).text()).toBe('Sync skills')
    expect(syncButton(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('posts to the existing skills sync endpoint, then reloads the slots and drops the action once every maximum is known', async () => {
    const wrapper = await mountView(UNKNOWN_MAXIMUM_SLOTS, { slotsAfterSync: KNOWN_SLOTS })
    expect(slotsReadCount()).toBe(1)

    await clickSync(wrapper)

    expect(skillsSyncCalls()).toHaveLength(1)
    expect(skillsSyncCalls()[0][1]).toMatchObject({ method: 'POST' })
    expect(slotsReadCount()).toBe(2)
    expect(banner(wrapper).exists()).toBe(false)
    const rows = wrapper.findAll('[data-testid="slots-character"]').map(row => row.text())
    expect(rows[0]).toMatch(/Ilyra Castell\s*1\s*\/\s*5/)
    expect(rows[1]).toMatch(/Doran Vex\s*1\s*\/\s*3/)
  })

  it('disables the action and says it is syncing until the sync answers', async () => {
    const wrapper = await mountView(UNKNOWN_MAXIMUM_SLOTS, { slotsAfterSync: KNOWN_SLOTS })
    const pending = deferred<{ characters: never[]; failedCharacters: never[] }>()
    const implementation = vi.mocked(apiRequest).getMockImplementation()
    vi.mocked(apiRequest).mockImplementation((endpoint: string, options?: RequestInit) =>
      endpoint === SKILLS_SYNC_ENDPOINT ? (pending.promise as never) : (implementation as typeof apiRequest)(endpoint, options))

    await syncButton(wrapper).trigger('click')

    expect(syncButton(wrapper).attributes('disabled')).toBeDefined()
    expect(syncButton(wrapper).text()).toBe('Syncing...')
    pending.resolve({ characters: [], failedCharacters: [] })
    await flushPromises()
    expect(banner(wrapper).exists()).toBe(false)
  })

  it('keeps the action and lists the characters the sync could not read when a maximum stays unknown', async () => {
    const wrapper = await mountView(UNKNOWN_MAXIMUM_SLOTS, {
      slotsAfterSync: STILL_UNKNOWN_FOR_DORAN_SLOTS,
      syncResponse: {
        characters: [],
        failedCharacters: [{ characterName: 'Doran Vex', reason: 'missing_scope', statusCode: null }],
      },
    })

    await clickSync(wrapper)

    expect(slotsReadCount()).toBe(2)
    const failures = wrapper.find('[data-testid="slots-skills-sync-failures"]')
    expect(failures.exists()).toBe(true)
    expect(failures.text()).toContain('Doran Vex')
    expect(banner(wrapper).exists()).toBe(true)
    expect(syncButton(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('shows the error and keeps the action available when the sync request fails, without reloading the slots', async () => {
    const wrapper = await mountView(UNKNOWN_MAXIMUM_SLOTS, { syncError: new Error('Skills sync unavailable') })

    await clickSync(wrapper)

    expect(wrapper.find('[data-testid="slots-skills-sync-error"]').text()).toContain('Skills sync unavailable')
    expect(slotsReadCount()).toBe(1)
    expect(banner(wrapper).exists()).toBe(true)
    expect(syncButton(wrapper).attributes('disabled')).toBeUndefined()
  })
})
