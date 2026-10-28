import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'
import { apiRequest } from '@/services/api'
import type { StockpileItemStatus, StockpileStatus } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MEXALLON_TYPE_ID = 36
const UNPRICED_COMPONENT_TYPE_ID = 57486

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
  deficitCost: 300,
  sourceProductTypeId: null,
  locations: [],
}

const UNPRICED_COMPONENT_TARGET: StockpileItemStatus = {
  id: '00000000-0000-4000-8000-000000057486',
  typeId: UNPRICED_COMPONENT_TYPE_ID,
  typeName: 'Unpriced Component',
  targetQuantity: 100,
  remainingTargetQuantity: 100,
  stock: 40,
  percent: 40,
  status: 'critical',
  unitPrice: null,
  stockValue: null,
  deficitCost: null,
  sourceProductTypeId: null,
  locations: [],
}

const STOCKPILE_WITH_A_TARGET_WITHOUT_PRICE: StockpileStatus = {
  targetCount: 2,
  stages: {
    raw_material: { items: [MEXALLON_TARGET], totalValue: 200, healthPercent: 40 },
    intermediate: { items: [], totalValue: 0, healthPercent: 100 },
    component: { items: [UNPRICED_COMPONENT_TARGET], totalValue: null, healthPercent: 40 },
    final_product: { items: [], totalValue: 0, healthPercent: 100 },
  },
  kpis: {
    pipelineHealth: 40,
    totalInvested: null,
    bottleneck: null,
    estOutput: { ready: 0, total: 0, readyNames: [] },
  },
  shoppingList: [
    { typeId: MEXALLON_TYPE_ID, typeName: 'Mexallon', stage: 'raw_material', stock: 4, targetQuantity: 10, deficit: 6, deficitCost: 300, percent: 40 },
    { typeId: UNPRICED_COMPONENT_TYPE_ID, typeName: 'Unpriced Component', stage: 'component', stock: 40, targetQuantity: 100, deficit: 60, deficitCost: null, percent: 40 },
  ],
  importsWithoutPlan: [],
  failedProducts: [],
  missingPriceTypeIds: [UNPRICED_COMPONENT_TYPE_ID],
}

async function mountDashboard(locale: 'en' | 'fr') {
  vi.mocked(apiRequest).mockResolvedValue(STOCKPILE_WITH_A_TARGET_WITHOUT_PRICE)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StockpileDashboard, {
    global: { plugins: [i18n], stubs: { ProductSearch: true, ConfirmModal: true } },
  })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

function kpiCard(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const labelElement = wrapper.findAll('div').find(element => element.text() === label)
  expect(labelElement, `KPI ${label}`).toBeDefined()
  return new DOMWrapper(labelElement!.element.parentElement!)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('StockpileDashboard with a target without a price', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the total invested as unknown, never as the value of the priced targets (%s)', async (locale, unknownValueTitle) => {
    const { wrapper, t } = await mountDashboard(locale)

    const totalInvested = kpiCard(wrapper, t('industry.stockpile.kpi.totalInvested'))
    expect(totalInvested.get(`[title="${unknownValueTitle}"]`).text()).toBe('—')
    expect(totalInvested.text()).not.toMatch(/\d/)
  })

  it.each([
    ['en'],
    ['fr'],
  ] as const)('names the targets without a price (%s)', async locale => {
    const { wrapper, t } = await mountDashboard(locale)

    expect(wrapper.text()).toContain(t('industry.inventionUnknown.missingPriceTypes', { types: 'Unpriced Component' }))
  })
})
