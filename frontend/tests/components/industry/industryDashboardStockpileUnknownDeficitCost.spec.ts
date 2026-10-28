import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import { apiRequest } from '@/services/api'
import type { StockpileItemStatus, StockpileStatus } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const KNOWN_DEFICIT_COST_TEXT = '400 ISK'

const UNPRICED_COMPONENT_TARGET: StockpileItemStatus = {
  id: '00000000-0000-4000-8000-000000057486',
  typeId: 57486,
  typeName: 'Unpriced Component',
  targetQuantity: 100,
  remainingTargetQuantity: 100,
  stock: 10,
  percent: 10,
  status: 'critical',
  unitPrice: null,
  stockValue: null,
  deficitCost: null,
  sourceProductTypeId: null,
  locations: [],
}

const MEXALLON_TARGET: StockpileItemStatus = {
  id: '00000000-0000-4000-8000-000000000036',
  typeId: 36,
  typeName: 'Mexallon',
  targetQuantity: 10,
  remainingTargetQuantity: 10,
  stock: 2,
  percent: 20,
  status: 'critical',
  unitPrice: 50,
  stockValue: 100,
  deficitCost: 400,
  sourceProductTypeId: null,
  locations: [],
}

const STOCKPILE_WITH_A_CRITICAL_TARGET_WITHOUT_PRICE: StockpileStatus = {
  targetCount: 2,
  stages: {
    raw_material: { items: [MEXALLON_TARGET], totalValue: 100, healthPercent: 20 },
    intermediate: { items: [], totalValue: 0, healthPercent: 100 },
    component: { items: [UNPRICED_COMPONENT_TARGET], totalValue: null, healthPercent: 10 },
    final_product: { items: [], totalValue: 0, healthPercent: 100 },
  },
  kpis: {
    pipelineHealth: 15,
    totalInvested: null,
    bottleneck: null,
    estOutput: { ready: 0, total: 0, readyNames: [] },
  },
  shoppingList: [],
  importsWithoutPlan: [],
  failedProducts: [],
  missingPriceTypeIds: [UNPRICED_COMPONENT_TARGET.typeId],
}

async function mountDashboard(locale: 'en' | 'fr') {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n] } })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

function stockpileCriticalAlert(wrapper: VueWrapper, title: string): string {
  const alert = wrapper.findAll('[role="button"]').find(row => row.text().includes(title))
  expect(alert, `alert ${title}`).toBeDefined()
  return alert!.text()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/stockpile-status') return Promise.resolve(STOCKPILE_WITH_A_CRITICAL_TARGET_WITHOUT_PRICE)
    return new Promise(() => {})
  })
})

describe('IndustryDashboard critical stockpile alert of a target without a price', () => {
  it.each([
    ['en'],
    ['fr'],
  ] as const)('shows the deficit cost as unknown, never as 0 or a placeholder, next to a known deficit cost (%s)', async locale => {
    const { wrapper, t } = await mountDashboard(locale)
    const unknownLabel = t('industry.inventionUnknown.label')

    const unpricedAlert = stockpileCriticalAlert(wrapper, t('industry.dashboard.alerts.stockpileCriticalTitle', { name: 'Unpriced Component', percent: 10 }))
    expect(unpricedAlert.toLowerCase()).toContain(unknownLabel.toLowerCase())
    expect(unpricedAlert).not.toContain('---')
    expect(unpricedAlert.toLowerCase()).not.toContain(`${unknownLabel.toLowerCase()} isk`)
    expect(unpricedAlert).not.toMatch(/\b0 ISK/)

    const pricedAlert = stockpileCriticalAlert(wrapper, t('industry.dashboard.alerts.stockpileCriticalTitle', { name: 'Mexallon', percent: 20 }))
    expect(pricedAlert).toContain(KNOWN_DEFICIT_COST_TEXT)
    expect(pricedAlert.toLowerCase()).not.toContain(unknownLabel.toLowerCase())
  })
})
