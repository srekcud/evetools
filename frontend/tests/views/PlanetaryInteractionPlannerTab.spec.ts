import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import PiStockTab from '@/components/planetary/PiStockTab.vue'
import type { Colony } from '@/stores/planetary'
import { apiRequest } from '@/services/api'
import { deferred } from '../deferred'

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

const PLANNER_TAB_LABEL = (en.pi.tabs as Record<string, string>).planner

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
  pins: [],
  routes: [],
}

async function mountView(colonies: Colony[], query: LocationQuery = {}) {
  return mountViewAnswering(() => Promise.resolve(colonies), query)
}

async function mountViewAnswering(coloniesRequest: () => Promise<unknown>, query: LocationQuery = {}) {
  route.query = query
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return coloniesRequest()
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
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
        PiColonyCard: true,
        PiTierLegend: true,
        PiKpiCards: true,
        PiPlannerTab: true,
        PiStockTab: true,
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

describe('PlanetaryInteraction planner tab', () => {
  it('lists the planner first, then the colonies, the stock and the revenue', async () => {
    const wrapper = await mountView([BARREN_COLONY])

    expect(wrapper.findAll('[role="tab"]').map(tab => tab.text())).toEqual([PLANNER_TAB_LABEL, en.pi.tabs.colonies, en.pi.tabs.stock, en.pi.tabs.revenue])
  })

  it('opens the stock tab when the player clicks it and leaves the colonies tab open by default', async () => {
    const wrapper = await mountView([BARREN_COLONY])
    const stockTab = wrapper.findAll('[role="tab"]').find(tab => tab.text() === en.pi.tabs.stock)
    const stockOpenedByDefault = wrapper.findComponent(PiStockTab).exists()

    await stockTab?.trigger('click')
    await flushPromises()

    expect(stockTab).toBeDefined()
    expect(stockOpenedByDefault).toBe(false)
    expect(selectedTab(wrapper)).toBe(en.pi.tabs.stock)
    expect(wrapper.findComponent(PiStockTab).exists()).toBe(true)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(false)
  })

  it('keeps the colonies tab open by default when the player has colonies', async () => {
    const wrapper = await mountView([BARREN_COLONY])

    expect(selectedTab(wrapper)).toBe(en.pi.tabs.colonies)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(false)
  })

  it('opens the planner when the player has no colony and the address names no tab', async () => {
    const wrapper = await mountView([])

    expect(selectedTab(wrapper)).toBe(PLANNER_TAB_LABEL)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(true)
  })

  it('keeps the colonies tab when the address names it, even without any colony', async () => {
    const wrapper = await mountView([], { tab: 'colonies' })

    expect(selectedTab(wrapper)).toBe(en.pi.tabs.colonies)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(false)
    expect(wrapper.text()).toContain(en.pi.noColonies)
  })

  it('keeps the planner closed while the colonies are loading and opens it only once they arrive empty', async () => {
    const pending = deferred<Colony[]>()
    const wrapper = await mountViewAnswering(() => pending.promise)

    expect(selectedTab(wrapper)).toBe(en.pi.tabs.colonies)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(false)

    pending.resolve([])
    await flushPromises()

    expect(selectedTab(wrapper)).toBe(PLANNER_TAB_LABEL)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(true)
  })

  it('keeps the planner closed when the colonies fail to load and shows the error', async () => {
    const wrapper = await mountViewAnswering(() => Promise.reject(new Error('colonies unavailable')))

    expect(selectedTab(wrapper)).toBe(en.pi.tabs.colonies)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(false)
    expect(wrapper.get('error-banner-stub').attributes('message')).toBe('colonies unavailable')
  })

  it('opens the planner when the address names it, even though the player has colonies', async () => {
    const wrapper = await mountView([BARREN_COLONY], { tab: 'planner' })

    expect(selectedTab(wrapper)).toBe(PLANNER_TAB_LABEL)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(true)
  })

  it('opens the planner when the player clicks its tab', async () => {
    const wrapper = await mountView([BARREN_COLONY])
    const plannerTab = wrapper.findAll('[role="tab"]').find(tab => tab.text() === PLANNER_TAB_LABEL)

    await plannerTab?.trigger('click')
    await flushPromises()

    expect(plannerTab).toBeDefined()
    expect(selectedTab(wrapper)).toBe(PLANNER_TAB_LABEL)
    expect(wrapper.findComponent(PiPlannerTab).exists()).toBe(true)
  })
})
