import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WIDGET_TYPE_ID = 3001
const WIDGET_TARGET_QUANTITY = 1
const WIDGET_STOCK = 0
const WIDGET_FAILURE_REASON = `No blueprint or reaction found for product type ID ${WIDGET_TYPE_ID}`
const IMPORT_WARNINGS_SELECTOR = '[data-testid="stockpile-import-warnings"]'

const widgetItem = {
  id: '00000000-0000-4000-8000-000000003001',
  typeId: WIDGET_TYPE_ID,
  typeName: 'Widget',
  targetQuantity: WIDGET_TARGET_QUANTITY,
  remainingTargetQuantity: WIDGET_TARGET_QUANTITY,
  stock: WIDGET_STOCK,
  percent: 0,
  status: 'critical',
  unitPrice: 1000,
  stockValue: 0,
  deficitCost: 1000,
  stage: 'final_product',
  sourceProductTypeId: WIDGET_TYPE_ID,
  locations: [],
}

const emptyStage = { items: [], totalValue: 0, healthPercent: 100 }

function stockpileStatus(importsWithoutPlan: number[], failedProducts: { typeId: number; reason: string }[]) {
  return {
    targetCount: 1,
    stages: {
      raw_material: emptyStage,
      intermediate: emptyStage,
      component: emptyStage,
      final_product: { items: [widgetItem], totalValue: 0, healthPercent: 0 },
    },
    kpis: {
      pipelineHealth: 0,
      totalInvested: 0,
      bottleneck: null,
      estOutput: { ready: 0, total: 1, readyNames: [] },
    },
    shoppingList: [],
    importsWithoutPlan,
    failedProducts,
  }
}

async function mountDashboard(importsWithoutPlan: number[], failedProducts: { typeId: number; reason: string }[]) {
  vi.mocked(apiRequest).mockResolvedValue(stockpileStatus(importsWithoutPlan, failedProducts))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(StockpileDashboard, {
    global: {
      plugins: [i18n],
      stubs: { StockpileColumn: true, StockpileImportPanel: true, StockpileShoppingList: true, ConfirmModal: true },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('StockpileDashboard import warnings', () => {
  it('names the products imported before their import was recorded, to invite a reimport', async () => {
    const warnings = (await mountDashboard([WIDGET_TYPE_ID], [])).find(IMPORT_WARNINGS_SELECTOR)

    expect(warnings.exists()).toBe(true)
    expect(warnings.text()).toContain('Widget')
  })

  it('names the products whose tree cannot be rebuilt, with their reason', async () => {
    const warnings = (await mountDashboard([], [{ typeId: WIDGET_TYPE_ID, reason: WIDGET_FAILURE_REASON }])).find(IMPORT_WARNINGS_SELECTOR)

    expect(warnings.exists()).toBe(true)
    expect(warnings.text()).toContain('Widget')
    expect(warnings.text()).toContain(WIDGET_FAILURE_REASON)
  })

  it('shows no import warning when every import was recorded and rebuilt', async () => {
    expect((await mountDashboard([], [])).find(IMPORT_WARNINGS_SELECTOR).exists()).toBe(false)
  })
})
