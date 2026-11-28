import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiPlannerSettings from '@/components/planetary/PiPlannerSettings.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { PLANNER_CATALOG, PLANNER_HUB } from '../../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PURCHASE_SOURCES = ['jita-sell', 'jita-buy', 'hub', 'paid', 'best']
const SALE_SOURCES = ['jita-sell', 'jita-buy', 'hub']

async function mountSettings() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({ id: 'planner', ...PLANNER_CATALOG, hub: PLANNER_HUB } as never)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  const wrapper = mount(PiPlannerSettings, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return { wrapper, store }
}

function pressedOf(wrapper: Awaited<ReturnType<typeof mountSettings>>['wrapper'], group: 'purchase' | 'sale', sources: string[]): string[] {
  return sources.filter(source => wrapper.get(`[data-testid="${group}-source-${source}"]`).attributes('aria-pressed') === 'true')
}

describe('PiPlannerSettings purchase and sale sources', () => {
  it('shows a purchase group of five buttons and a sale group of three, Jita sell pressed in both by default', async () => {
    const { wrapper } = await mountSettings()

    expect(wrapper.findAll('[data-testid^="purchase-source-"]').map(button => button.attributes('data-testid'))).toEqual(PURCHASE_SOURCES.map(source => `purchase-source-${source}`))
    expect(wrapper.findAll('[data-testid^="sale-source-"]').map(button => button.attributes('data-testid'))).toEqual(SALE_SOURCES.map(source => `sale-source-${source}`))
    expect(pressedOf(wrapper, 'purchase', PURCHASE_SOURCES)).toEqual(['jita-sell'])
    expect(pressedOf(wrapper, 'sale', SALE_SOURCES)).toEqual(['jita-sell'])
    expect(wrapper.get('[data-testid="purchase-source-hub"]').text()).toContain('Keepstar Test Hub')
    expect(wrapper.get('[data-testid="purchase-source-paid"]').text()).toContain('90')
  })

  it('changes only the purchase source on a click in the purchase group and only the sale source in the sale group', async () => {
    const { wrapper, store } = await mountSettings()

    await wrapper.get('[data-testid="purchase-source-best"]').trigger('click')
    await wrapper.get('[data-testid="sale-source-hub"]').trigger('click')

    expect(store.settings).toEqual({ purchaseSource: 'best', saleSource: 'hub', taxPercent: 4, daysBetweenRounds: 4 })
    expect(pressedOf(wrapper, 'purchase', PURCHASE_SOURCES)).toEqual(['best'])
    expect(pressedOf(wrapper, 'sale', SALE_SOURCES)).toEqual(['hub'])
  })
})
