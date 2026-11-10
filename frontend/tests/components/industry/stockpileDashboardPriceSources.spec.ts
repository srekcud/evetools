import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'
import { apiRequest } from '@/services/api'
import type { StockpileItemStatus, StockpilePriceSources, StockpileStatus } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MEXALLON_TYPE_ID = 36

const MEXALLON_TARGET: StockpileItemStatus = {
  id: '00000000-0000-4000-8000-000000000036',
  typeId: MEXALLON_TYPE_ID,
  typeName: 'Mexallon',
  targetQuantity: 10,
  remainingTargetQuantity: 10,
  stock: 4,
  percent: 40,
  status: 'critical',
  unitPrice: 50,
  stockValue: 200,
  deficit: 6,
  deficitCost: 300,
  sourceProductTypeId: null,
  locations: [],
  suggestedProjectRuns: null,
}

const PRICED_STOCKPILE: StockpileStatus = {
  targetCount: 1,
  stages: {
    raw_material: { items: [MEXALLON_TARGET], totalValue: 200, healthPercent: 40 },
    intermediate: { items: [], totalValue: 0, healthPercent: 100 },
    component: { items: [], totalValue: 0, healthPercent: 100 },
    final_product: { items: [], totalValue: 0, healthPercent: 100 },
  },
  kpis: {
    pipelineHealth: 40,
    totalInvested: 200,
    bottleneck: null,
    estOutput: { ready: 0, total: 0, readyNames: [] },
  },
  shoppingList: [
    { typeId: MEXALLON_TYPE_ID, typeName: 'Mexallon', stage: 'raw_material', stock: 4, targetQuantity: 10, deficit: 6, deficitCost: 300, percent: 40 },
  ],
  importsWithoutPlan: [],
  failedProducts: [],
  missingPriceTypeIds: [],
}

const PARTIAL_DEFICIT_PRICE_SOURCES: StockpilePriceSources = {
  stockValue: { market: 'jita', side: 'sell', method: 'weighted', coveragePercent: null },
  deficitCost: { market: 'jita', side: 'sell', method: 'weighted', coveragePercent: 75 },
}

const UNKNOWN_PRICE_SOURCES: StockpilePriceSources = { stockValue: null, deficitCost: null }

const STOCKPILE_WITH_UNKNOWN_AMOUNTS: StockpileStatus = {
  ...PRICED_STOCKPILE,
  stages: {
    ...PRICED_STOCKPILE.stages,
    raw_material: {
      items: [{ ...MEXALLON_TARGET, unitPrice: null, stockValue: null, deficitCost: null }],
      totalValue: null,
      healthPercent: 40,
    },
  },
  kpis: { ...PRICED_STOCKPILE.kpis, totalInvested: null },
  shoppingList: [{ ...PRICED_STOCKPILE.shoppingList[0]!, deficitCost: null }],
  missingPriceTypeIds: [MEXALLON_TYPE_ID],
}

async function mountDashboard(status: StockpileStatus) {
  vi.mocked(apiRequest).mockResolvedValue(status)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(StockpileDashboard, {
    global: { plugins: [i18n], stubs: { ProductSearch: true, ConfirmModal: true } },
  })
  await flushPromises()
  return wrapper
}

function totalInvestedCard(wrapper: VueWrapper): DOMWrapper<Element> {
  const labelElement = wrapper.findAll('div').find(element => element.text() === 'Total Invested')
  expect(labelElement, 'KPI Total Invested').toBeDefined()
  return new DOMWrapper(labelElement!.element.parentElement!)
}

function estimatedCostHeader(wrapper: VueWrapper): string {
  const header = wrapper.findAll('th').find(candidate => candidate.text().startsWith('Est. Cost'))
  expect(header, 'column Est. Cost').toBeDefined()
  return header!.text()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('StockpileDashboard price sources', () => {
  it('labels the stock value and the deficit cost with their price source and the partial coverage of the deficit', async () => {
    const wrapper = await mountDashboard({ ...PRICED_STOCKPILE, priceSources: PARTIAL_DEFICIT_PRICE_SOURCES })

    const totalInvested = totalInvestedCard(wrapper).text()
    expect(totalInvested).toContain('Jita · weighted')
    expect(totalInvested).not.toContain('covered')
    const estimatedCost = estimatedCostHeader(wrapper)
    expect(estimatedCost).toContain('Jita · weighted')
    expect(estimatedCost).toContain('75% covered')
  })

  it('shows an unknown stock value as a dash with no label, never as zero', async () => {
    const wrapper = await mountDashboard({ ...STOCKPILE_WITH_UNKNOWN_AMOUNTS, priceSources: UNKNOWN_PRICE_SOURCES })

    const totalInvested = totalInvestedCard(wrapper)
    expect(totalInvested.get('[title="Unknown value"]').text()).toBe('—')
    expect(totalInvested.text()).not.toContain('·')
    expect(estimatedCostHeader(wrapper)).not.toContain('·')
  })

  it('shows the amounts without label nor error when the response carries no price sources', async () => {
    const wrapper = await mountDashboard(PRICED_STOCKPILE)

    expect(totalInvestedCard(wrapper).text()).toContain('200')
    expect(totalInvestedCard(wrapper).text()).not.toContain('·')
    expect(estimatedCostHeader(wrapper)).not.toContain('·')
  })
})
