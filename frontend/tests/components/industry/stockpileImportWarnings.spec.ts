import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'
import { apiRequest } from '@/services/api'
import type { StockpileFailedProduct, StockpileItemStatus, StockpileStageStatus, StockpileStatus } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WIDGET_TYPE_ID = 3001
const WIDGET_TARGET_QUANTITY = 1
const WIDGET_STOCK = 0
const GADGET_TYPE_ID = 3004
const NO_BLUEPRINT_LABEL_EN = 'No blueprint or reaction'
const PLAN_FAILED_LABEL_EN = 'The production plan could not be built'
const NO_BLUEPRINT_LABEL_FR = 'Aucun blueprint ni réaction'
const PLAN_FAILED_LABEL_FR = 'Le plan de production n\'a pas pu être construit'
const UNKNOWN_GADGET_TEXT = `Unknown product #${GADGET_TYPE_ID}`
const IMPORT_WARNINGS_SELECTOR = '[data-testid="stockpile-import-warnings"]'

const widgetItem: StockpileItemStatus = {
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
  sourceProductTypeId: WIDGET_TYPE_ID,
  locations: [],
}

const emptyStage: StockpileStageStatus = { items: [], totalValue: 0, healthPercent: 100 }

function stockpileStatus(importsWithoutPlan: number[], failedProducts: StockpileFailedProduct[]): StockpileStatus {
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
    missingPriceTypeIds: [],
  }
}

async function mountDashboard(importsWithoutPlan: number[], failedProducts: StockpileFailedProduct[], locale: 'en' | 'fr' = 'en') {
  vi.mocked(apiRequest).mockResolvedValue(stockpileStatus(importsWithoutPlan, failedProducts))
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StockpileDashboard, {
    global: {
      plugins: [i18n],
      stubs: { StockpileColumn: true, StockpileImportPanel: true, StockpileShoppingList: true, ConfirmModal: true },
    },
  })
  await flushPromises()
  return wrapper
}

function failedProductLines(wrapper: Awaited<ReturnType<typeof mountDashboard>>): string[] {
  return wrapper.find(IMPORT_WARNINGS_SELECTOR).findAll('li').map(line => line.text())
}

function failureReasonCodesOf(messages: object): string[] {
  const importWarnings = (messages as { industry: { stockpile: { importWarnings: Record<string, unknown> } } }).industry.stockpile.importWarnings
  const reasonLabels = importWarnings.reason
  return typeof reasonLabels === 'object' && reasonLabels !== null ? Object.keys(reasonLabels).sort() : []
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

  it.each([
    ['en', 'no_blueprint', NO_BLUEPRINT_LABEL_EN],
    ['en', 'plan_failed', PLAN_FAILED_LABEL_EN],
    ['fr', 'no_blueprint', NO_BLUEPRINT_LABEL_FR],
    ['fr', 'plan_failed', PLAN_FAILED_LABEL_FR],
  ] as const)('names the product that cannot be rebuilt with the %s label of its %s reason instead of the code', async (locale, reason, label) => {
    const wrapper = await mountDashboard([], [{ typeId: WIDGET_TYPE_ID, reason }], locale)

    expect(failedProductLines(wrapper)).toEqual([`Widget: ${label}`])
  })

  it.each([
    ['en', PLAN_FAILED_LABEL_EN],
    ['fr', PLAN_FAILED_LABEL_FR],
  ] as const)('shows the plan failed label for an unknown reason code (%s)', async (locale, planFailedLabel) => {
    const wrapper = await mountDashboard([], [{ typeId: WIDGET_TYPE_ID, reason: 'x' }], locale)

    expect(failedProductLines(wrapper)).toEqual([`Widget: ${planFailedLabel}`])
  })

  it('defines a label for every reason code in English and French', () => {
    expect(failureReasonCodesOf(en)).toEqual(['no_blueprint', 'plan_failed'])
    expect(failureReasonCodesOf(fr)).toEqual(['no_blueprint', 'plan_failed'])
  })

  it('shows no import warning when every import was recorded and rebuilt', async () => {
    expect((await mountDashboard([], [])).find(IMPORT_WARNINGS_SELECTOR).exists()).toBe(false)
  })

  it('names a product missing from the final product targets by its type ID', async () => {
    const warnings = (await mountDashboard([GADGET_TYPE_ID], [])).find(IMPORT_WARNINGS_SELECTOR)

    expect(warnings.text()).toContain(UNKNOWN_GADGET_TEXT)
  })

  it('shows the products without plan and the products that cannot be rebuilt together', async () => {
    const warnings = (await mountDashboard([WIDGET_TYPE_ID], [{ typeId: GADGET_TYPE_ID, reason: 'plan_failed' }])).find(IMPORT_WARNINGS_SELECTOR)
    const text = warnings.text()

    expect(text).toContain(en.industry.stockpile.importWarnings.withoutPlan)
    expect(text).toContain('Widget')
    expect(text).toContain(en.industry.stockpile.importWarnings.failedProducts)
    expect(text).toContain(`${UNKNOWN_GADGET_TEXT}: ${PLAN_FAILED_LABEL_EN}`)
  })
})
