import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import PiKpiCards from '@/components/planetary/PiKpiCards.vue'
import type { Colony, PlanetaryStats, ProductionTier } from '@/stores/planetary'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function colony(overrides: Partial<Colony>): Colony {
  return {
    id: 'colony',
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
    status: 'active',
    pins: [],
    routes: [],
    ...overrides,
  }
}

const BARREN_COLONY = colony({ id: 'barren', characterId: 2112000001, characterName: 'Ilyra Castell', planetId: 40009077, planetType: 'barren', extractorCount: 2, activeExtractors: 2 })
const GAS_COLONY = colony({ id: 'gas', characterId: 2112000002, characterName: 'Doran Vex', planetId: 40009081, planetName: 'Perimeter II', planetType: 'gas', solarSystemId: 30000144, solarSystemName: 'Perimeter', extractorCount: 3, activeExtractors: 1, status: 'expiring' })

const PRODUCTION_TIERS: ProductionTier[] = [
  { tier: 'P1', items: [], dailyIskValue: 3_250_000 },
]

const KNOWN_STATS: PlanetaryStats = {
  totalColonies: 2,
  totalExtractors: 9,
  activeExtractors: 7,
  expiringExtractors: 0,
  expiredExtractors: 2,
  totalFactories: 12,
  estimatedDailyIsk: 4_100_000,
  nearestExpiry: '2026-10-24T09:00:00+00:00',
}

function respondWith(stats: () => Promise<unknown>) {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return Promise.resolve([BARREN_COLONY, GAS_COLONY])
    if (endpoint === '/planetary/stats') return stats()
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: PRODUCTION_TIERS })
    return new Promise(() => {})
  })
}

async function mountView() {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(PlanetaryInteraction, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        ErrorBanner: true,
        PiAlertsBanner: true,
        PiProductionTable: true,
        PiColonyCard: true,
        PiTierLegend: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function kpiCard(wrapper: Awaited<ReturnType<typeof mountView>>, label: string) {
  const card = wrapper.findComponent(PiKpiCards).findAll('div.rounded-xl').find(div => div.get('span').text() === label)
  if (!card) throw new Error(`No KPI card labelled ${label}`)
  return card
}

function kpiValue(wrapper: Awaited<ReturnType<typeof mountView>>, label: string) {
  return (kpiCard(wrapper, label).element.children[1] as HTMLElement).textContent?.trim()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('PlanetaryInteraction KPI cards while the planetary stats are unknown', () => {
  beforeEach(() => {
    respondWith(() => Promise.reject(new Error('stats unavailable')))
  })

  it('shows the active extractors as unknown instead of 0', async () => {
    const wrapper = await mountView()

    const activeExtractors = kpiCard(wrapper, en.pi.kpi.activeExtractors)
    expect(kpiValue(wrapper, en.pi.kpi.activeExtractors)).toBe('—')
    expect(activeExtractors.get('[title]').attributes('title')).toBe('Unknown value')
  })

  it('shows the total extractors as unknown instead of 0', async () => {
    const wrapper = await mountView()

    const activeExtractors = kpiCard(wrapper, en.pi.kpi.activeExtractors)
    expect(activeExtractors.text()).not.toMatch(/\d/)
    expect(activeExtractors.text()).not.toContain('0 extractors total')
  })

  it('shows the expiring extractors as unknown instead of 0', async () => {
    const wrapper = await mountView()

    expect(kpiValue(wrapper, en.pi.kpi.expiringSoon)).toBe('—')
    expect(kpiCard(wrapper, en.pi.kpi.expiringSoon).get('[title]').attributes('title')).toBe('Unknown value')
  })

  it('keeps the colonies count and the daily ISK from the colonies and production lists', async () => {
    const wrapper = await mountView()

    expect(kpiValue(wrapper, en.pi.kpi.colonies)).toBe('2')
    expect(kpiCard(wrapper, en.pi.kpi.colonies).text()).toContain('across 2 characters')
    expect(kpiValue(wrapper, en.pi.kpi.theoreticalProduction)).toBe('3.25 M')
  })
})

describe('PlanetaryInteraction KPI cards once the planetary stats are known', () => {
  beforeEach(() => {
    respondWith(() => Promise.resolve(KNOWN_STATS))
  })

  it('shows the known extractor counts, a known zero included', async () => {
    const wrapper = await mountView()

    const activeExtractors = kpiCard(wrapper, en.pi.kpi.activeExtractors)
    expect(kpiValue(wrapper, en.pi.kpi.activeExtractors)).toMatch(/^7\s*expired$/)
    expect(activeExtractors.text()).toContain('9 extractors total')
    expect(kpiValue(wrapper, en.pi.kpi.expiringSoon)).toBe('0')
    expect(kpiValue(wrapper, en.pi.kpi.theoreticalProduction)).toBe('4.10 M')
  })
})
