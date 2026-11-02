import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StockpileImportPanel from '@/components/industry/StockpileImportPanel.vue'
import { apiRequest } from '@/services/api'
import type { SearchResult, StockpileImportPreview, StockpileStatus } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PREVIEW_URL = '/industry/stockpile-targets/preview'
const IMPORT_URL = '/industry/stockpile-targets/import'
const NITROGEN_FUEL_BLOCK: SearchResult = { typeId: 4051, typeName: 'Nitrogen Fuel Block', isT2: false }
const COOLANT_TYPE_ID = 9832
const RUNS = 7
const ME = 8

const NITROGEN_FUEL_BLOCK_PREVIEW: StockpileImportPreview = {
  stages: {
    raw_material: [{ typeId: COOLANT_TYPE_ID, typeName: 'Coolant', quantity: 63, unitPrice: 150 }],
    intermediate: [],
    component: [],
    final_product: [{ typeId: NITROGEN_FUEL_BLOCK.typeId, typeName: 'Nitrogen Fuel Block', quantity: 280, unitPrice: 600.5 }],
  },
  totalItems: 2,
  estimatedCost: 9450,
  missingPriceTypeIds: [],
}

const EMPTY_STOCKPILE_STATUS: StockpileStatus = {
  targetCount: 0,
  stages: {
    raw_material: { items: [], totalValue: 0, healthPercent: 100 },
    intermediate: { items: [], totalValue: 0, healthPercent: 100 },
    component: { items: [], totalValue: 0, healthPercent: 100 },
    final_product: { items: [], totalValue: 0, healthPercent: 100 },
  },
  kpis: {
    pipelineHealth: 100,
    totalInvested: 0,
    bottleneck: null,
    estOutput: { ready: 0, total: 0, readyNames: [] },
  },
  shoppingList: [],
  importsWithoutPlan: [],
  failedProducts: [],
  missingPriceTypeIds: [],
}

function respondByUrl(url: string): Promise<unknown> {
  if (url === PREVIEW_URL) return Promise.resolve(NITROGEN_FUEL_BLOCK_PREVIEW)
  if (url === '/industry/stockpile-targets') return Promise.resolve([])
  if (url === '/industry/stockpile-status') return Promise.resolve(EMPTY_STOCKPILE_STATUS)
  return Promise.resolve(null)
}

function mountPanel(locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StockpileImportPanel, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
  return { wrapper, t: i18n.global.t }
}

function sentBody(url: string): unknown {
  const call = vi.mocked(apiRequest).mock.calls.find(([calledUrl]) => calledUrl === url)
  expect(call, `request to ${url}`).toBeDefined()
  return JSON.parse(String(call?.[1]?.body))
}

async function previewNitrogenFuelBlock(wrapper: ReturnType<typeof mountPanel>['wrapper'], calculateLabel: string) {
  wrapper.findComponent({ name: 'ProductSearch' }).vm.$emit('select', NITROGEN_FUEL_BLOCK)
  await flushPromises()
  const numberInputs = wrapper.findAll('input[type="number"]')
  await numberInputs[0].setValue(RUNS)
  await numberInputs[1].setValue(ME)
  const calculateButton = wrapper.findAll('button').find(button => button.text() === calculateLabel)
  expect(calculateButton, 'calculate button').toBeDefined()
  await calculateButton?.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(respondByUrl)
})

describe('StockpileImportPanel blueprint parameters', () => {
  it.each([['en'], ['fr']] as const)('offers the product, the runs and the ME, without any TE field (%s)', locale => {
    const { wrapper, t } = mountPanel(locale)

    expect(wrapper.findAll('label').map(label => label.text())).toEqual([
      t('industry.stockpile.import.product'),
      t('industry.stockpile.import.runs'),
      t('industry.stockpile.import.me'),
    ])
    expect(wrapper.findAll('input[type="number"]')).toHaveLength(2)
  })

  it('sends only the type, the runs and the ME to the preview', async () => {
    const { wrapper, t } = mountPanel()

    await previewNitrogenFuelBlock(wrapper, t('industry.stockpile.import.calculate'))

    expect(sentBody(PREVIEW_URL)).toEqual({ typeId: NITROGEN_FUEL_BLOCK.typeId, runs: RUNS, me: ME })
  })

  it.each([
    ['industry.stockpile.import.addToStockpile', 'replace'],
    ['industry.stockpile.import.mergeWithExisting', 'merge'],
  ] as const)('sends only the type, the runs, the ME and the mode to the import (%s)', async (buttonKey, mode) => {
    const { wrapper, t } = mountPanel()
    await previewNitrogenFuelBlock(wrapper, t('industry.stockpile.import.calculate'))

    const importButton = wrapper.findAll('button').find(button => button.text() === t(buttonKey))
    expect(importButton, 'import button').toBeDefined()
    await importButton?.trigger('click')
    await flushPromises()

    expect(sentBody(IMPORT_URL)).toEqual({ typeId: NITROGEN_FUEL_BLOCK.typeId, runs: RUNS, me: ME, mode })
  })
})
