import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import type { Colony } from '@/stores/planetary'
import { apiRequest } from '@/services/api'
import { PLANNER_ACCOUNT, PLANNER_CATALOG } from '../planetaryPlannerFixtures'
import { COOLANT_LINE_TEMPLATE, RING_TEMPLATE } from '../planetaryFollowHarness'

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

const PLANET_NAMES = ['Jita IV', 'Jita VII', 'Perimeter II', 'Amamake III']

function colony(id: string, planetName: string, tier: string | null): Colony {
  return {
    id,
    characterId: 2113830001,
    characterName: 'Ilyra Castell',
    planetId: 40009000 + id.length,
    planetName,
    planetType: 'barren',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    solarSystemSecurity: 0.9,
    upgradeLevel: 4,
    tier,
    storageFull: null,
    commandCenterUpgradable: false,
    numPins: 12,
    lastUpdate: '2026-11-26T03:00:00+00:00',
    cachedAt: '2026-11-26T03:00:00+00:00',
    extractorCount: 0,
    factoryCount: 0,
    activeExtractors: 0,
    nearestExpiry: null,
    factoryProgress: null,
    status: 'idle',
    pins: [],
    routes: [],
  }
}

const COLONIES: Colony[] = [
  colony('a', 'Jita IV', 'P1'),
  colony('bb', 'Jita VII', 'P2'),
  colony('ccc', 'Perimeter II', 'P4'),
  colony('dddd', 'Amamake III', null),
]

async function mountView(options: { followed: boolean; tab?: string }) {
  route.query = options.tab === undefined ? {} : { tab: options.tab }
  const templates = options.followed ? [{ ...COOLANT_LINE_TEMPLATE, followed: true }] : [{ ...RING_TEMPLATE, followed: false }]
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary') return COLONIES
    if (endpoint === '/planetary/stats') return {}
    if (endpoint === '/planetary/production') return { tiers: [] }
    if (endpoint === '/planetary/slots') return { characters: [], totalUsed: 0, totalMax: 0 }
    if (endpoint === '/planetary/input-needs') return { colonies: [], extractions: [], weekly: [], complete: true, launchpads: [] }
    if (endpoint === '/planetary/templates') return templates
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner') return { id: 'planner', ...PLANNER_CATALOG }
    return new Promise(() => {})
  }) as never)
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
        PiPlannerTab: { template: '<button data-testid="stub-show-colonies" @click="$emit(\'show-colonies\', \'P2\')">stub</button>', emits: ['show-colonies'] },
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

function planetNames(wrapper: Wrapper): string[] {
  return wrapper.findAll('[data-testid="colony-card"]').map(card => PLANET_NAMES.find(name => card.text().includes(name)) as string)
}

enableAutoUnmount(afterEach)

describe('PlanetaryInteraction followed plan colony links', () => {
  it('shows every colony and no plan chip while no plan is followed', async () => {
    const wrapper = await mountView({ followed: false })

    expect(wrapper.find('[data-testid="filter-chip-plan"]').exists()).toBe(false)
    expect([...planetNames(wrapper)].sort()).toEqual(['Amamake III', 'Jita IV', 'Jita VII', 'Perimeter II'])
  })

  it('offers a plan chip counting the colonies of the tiers the followed plan builds in', async () => {
    const wrapper = await mountView({ followed: true })

    const chip = wrapper.get('[data-testid="filter-chip-plan"]')
    expect(chip.attributes('aria-pressed')).toBe('false')
    expect(chip.attributes('data-count')).toBe('1')
  })

  it('filters the colonies on the tiers of the followed plan when the banner link is clicked, and the plan chip is pressed', async () => {
    const wrapper = await mountView({ followed: true })

    await wrapper.get('[data-testid="followed-plan-filter"]').trigger('click')

    expect(planetNames(wrapper)).toEqual(['Jita VII'])
    expect(wrapper.get('[data-testid="filter-chip-plan"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid="filter-chip-all"]').attributes('aria-pressed')).toBe('false')
  })

  it('leaves the plan filter when another chip is pressed', async () => {
    const wrapper = await mountView({ followed: true })
    await wrapper.get('[data-testid="followed-plan-filter"]').trigger('click')

    await wrapper.get('[data-testid="filter-chip-all"]').trigger('click')

    expect(planetNames(wrapper)).toHaveLength(4)
    expect(wrapper.get('[data-testid="filter-chip-plan"]').attributes('aria-pressed')).toBe('false')
  })

  it('opens the colonies tab filtered on the tier when the planner asks to show the colonies of a tier', async () => {
    const wrapper = await mountView({ followed: true, tab: 'planner' })

    await wrapper.get('[data-testid="stub-show-colonies"]').trigger('click')
    await flushPromises()

    expect(planetNames(wrapper)).toEqual(['Jita VII'])
    expect(wrapper.get('[data-testid="filter-chip-P2"]').attributes('aria-pressed')).toBe('true')
  })
})
