import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import type { Colony } from '@/stores/planetary'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { BROADCAST_NODE, PLANNER_CATALOG, TOXIC_METALS, WATER } from '../planetaryPlannerFixtures'
import { COOLANT_LINE_TEMPLATE, RING_TEMPLATE, accountWithFactories } from '../planetaryFollowHarness'

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

const CONTEXT_ENDPOINTS = ['/planetary/planner', '/planetary/planner/account', '/planetary/templates']

const BARREN_COLONY: Colony = {
  id: 'barren',
  characterId: 2112000001,
  characterName: 'Ilyra Castell',
  planetId: 40009077,
  planetName: 'Jita IV',
  planetType: 'barren',
  solarSystemId: 30000142,
  solarSystemName: 'Jita',
  solarSystemSecurity: 0.9,
  upgradeLevel: 4,
  numPins: 12,
  lastUpdate: '2026-10-22T09:00:00+00:00',
  cachedAt: '2026-10-22T09:05:00+00:00',
  extractorCount: 2,
  factoryCount: 6,
  activeExtractors: 2,
  nearestExpiry: '2026-10-24T09:00:00+00:00',
  factoryProgress: null,
  status: 'active',
  tier: null,
  storageFull: null,
  pins: [],
  routes: [],
}

type ContextAnswer = 'served' | 'pending' | 'failing'

async function mountView(templates: PlanTemplate[], context: ContextAnswer = 'served', query: LocationQuery = {}) {
  route.query = query
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return Promise.resolve([BARREN_COLONY])
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
    if (!CONTEXT_ENDPOINTS.includes(endpoint)) return new Promise(() => {})
    if (context === 'pending') return new Promise(() => {})
    if (context === 'failing') return Promise.reject(new Error('context unavailable'))
    if (endpoint === '/planetary/templates') return Promise.resolve(templates)
    if (endpoint === '/planetary/planner/account') return Promise.resolve({ id: 'account', ...accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 }) })
    return Promise.resolve({ id: 'planner', ...PLANNER_CATALOG })
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
        PiColonyCard: true,
        PiTierLegend: true,
        PiKpiCards: true,
        PiPlannerTab: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function selectedTab(wrapper: Wrapper): string | undefined {
  return wrapper.findAll('[role="tab"]').find(tab => tab.attributes('aria-selected') === 'true')?.text()
}

enableAutoUnmount(afterEach)

describe('PlanetaryInteraction followed plan', () => {
  it('loads the catalog, the account and the templates when it opens, without the planner tab', async () => {
    await mountView([RING_TEMPLATE])

    const requested = vi.mocked(apiRequest).mock.calls.map(call => call[0])
    expect(CONTEXT_ENDPOINTS.every(endpoint => requested.includes(endpoint))).toBe(true)
  })

  it('shows the followed plan banner above the colonies with its name and its gaps: 2 free in P1, missing 3 in P2', async () => {
    const wrapper = await mountView([RING_TEMPLATE, COOLANT_LINE_TEMPLATE])

    expect(selectedTab(wrapper)).toBe(en.pi.tabs.colonies)
    expect(wrapper.get('[data-testid="followed-plan-name"]').text()).toBe('Broadcast ring')
    expect(wrapper.get('[data-testid="followed-plan-tier"][data-tier="P1"] [data-testid="followed-plan-gap"]').text()).toBe('2 free')
    expect(wrapper.get('[data-testid="followed-plan-tier"][data-tier="P2"] [data-testid="followed-plan-gap"]').text()).toBe('missing 3')
    expect(wrapper.findAll('pi-colony-card-stub')).toHaveLength(1)
  })

  it('shows no banner while no template is followed', async () => {
    const wrapper = await mountView([{ ...RING_TEMPLATE, followed: false }, COOLANT_LINE_TEMPLATE])

    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
    expect(wrapper.findAll('pi-colony-card-stub')).toHaveLength(1)
  })

  it('shows no banner on the planner tab', async () => {
    const wrapper = await mountView([RING_TEMPLATE], 'served', { tab: 'planner' })

    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(true)
    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
  })

  it('lists the colonies and shows no banner while the follow context is still loading', async () => {
    const wrapper = await mountView([RING_TEMPLATE], 'pending')

    expect(wrapper.findAll('pi-colony-card-stub')).toHaveLength(1)
    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
  })

  it('lists the colonies and shows no banner when the follow context cannot be loaded', async () => {
    const wrapper = await mountView([RING_TEMPLATE], 'failing')

    expect(wrapper.findAll('pi-colony-card-stub')).toHaveLength(1)
    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
  })

  it('opens the planner with the followed template loaded when the player clicks Open the plan', async () => {
    const wrapper = await mountView([RING_TEMPLATE, COOLANT_LINE_TEMPLATE])

    await wrapper.get('[data-testid="followed-plan-open"]').trigger('click')
    await flushPromises()

    expect(selectedTab(wrapper)).toBe(en.pi.tabs.planner)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(true)
    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
    expect(usePlanetaryPlannerStore().productions).toEqual([{ typeId: BROADCAST_NODE, targetPerDay: 36 }])
    expect(usePlanetaryPlannerStore().choices).toEqual({ [WATER]: 'produce', [TOXIC_METALS]: 'produce' })
  })
})
