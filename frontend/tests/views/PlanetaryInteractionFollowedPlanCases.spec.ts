import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import type { Colony } from '@/stores/planetary'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { PLANNER_CATALOG } from '../planetaryPlannerFixtures'
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

const CATALOG_ERROR_MESSAGE = 'planner data unavailable'

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

async function mountView(templates: PlanTemplate[], catalogFails = false) {
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string, options?: RequestInit) => {
    if (endpoint === '/planetary') return Promise.resolve([BARREN_COLONY])
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
    if (endpoint.startsWith('/planetary/templates/') && options?.method === 'DELETE') return Promise.resolve(null)
    if (endpoint === '/planetary/templates') return Promise.resolve(templates)
    if (endpoint === '/planetary/planner/account') return Promise.resolve({ id: 'account', ...accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 }) })
    if (endpoint === '/planetary/planner') return catalogFails ? Promise.reject(new Error(CATALOG_ERROR_MESSAGE)) : Promise.resolve({ id: 'planner', ...PLANNER_CATALOG })
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const wrapper = mount(PlanetaryInteraction, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
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

function requestsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === endpoint && ((call[1] as RequestInit | undefined)?.method ?? 'GET') === 'GET').length
}

enableAutoUnmount(afterEach)

describe('PlanetaryInteraction followed plan edge cases', () => {
  it('shows the error of the planner store on the colonies tab, next to the colonies, when the catalog cannot be loaded while a plan is followed', async () => {
    const wrapper = await mountView([RING_TEMPLATE, COOLANT_LINE_TEMPLATE], true)

    expect(usePlanetaryPlannerStore().followedTemplate?.id).toBe('tpl-ring')
    expect(usePlanetaryPlannerStore().error).toBe(CATALOG_ERROR_MESSAGE)
    expect(wrapper.findAll('pi-colony-card-stub')).toHaveLength(1)
    expect(wrapper.text()).toContain(CATALOG_ERROR_MESSAGE)
  })

  it('removes the banner and keeps the colonies when the followed template is deleted', async () => {
    const wrapper = await mountView([RING_TEMPLATE, COOLANT_LINE_TEMPLATE])
    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(true)

    await usePlanetaryPlannerStore().deleteTemplate('tpl-ring')
    await flushPromises()

    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
    expect(wrapper.findAll('pi-colony-card-stub')).toHaveLength(1)
  })

  it('requests the catalog, the account and the templates exactly once each when it opens', async () => {
    await mountView([RING_TEMPLATE])

    expect(requestsTo('/planetary/planner')).toBe(1)
    expect(requestsTo('/planetary/planner/account')).toBe(1)
    expect(requestsTo('/planetary/templates')).toBe(1)
  })
})
