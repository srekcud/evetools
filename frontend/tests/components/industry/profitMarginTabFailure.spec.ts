import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginResult, UserSettings } from '@/stores/industry/types'
import { apiOutcomesByPath, awaiting, deferred, rejectWith, respondWith, type ApiOutcome } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_TYPE_ID = 12005
const VEXOR_TYPE_ID = 626
const PRICE_DATA_UNAVAILABLE = 'Price data unavailable (fictif)'
const ISHTAR_ONLY_MATERIAL = 'Zydrine'

const USER_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  brokerFeeRate: 0.025,
  salesTaxRate: 0.045,
  exportCostPerM3: 1200,
}

const ISHTAR_MARGIN: ProfitMarginResult = {
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  isT2: false,
  runs: 1,
  outputQuantity: 1,
  outputPerRun: 1,
  materialCost: 196_400_000,
  materials: [
    { typeId: 11_399, typeName: 'Morphite', quantity: 1_240, unitPrice: 9_350, totalPrice: 11_594_000 },
    { typeId: 39, typeName: ISHTAR_ONLY_MATERIAL, quantity: 5_600, unitPrice: 1_180, totalPrice: 6_608_000 },
  ],
  jobInstallCost: 2_870_000,
  jobInstallSteps: [],
  inventionCost: 0,
  totalCost: 199_270_000,
  costPerUnit: 199_270_000,
  invention: null,
  sellPrices: {
    jitaSell: 268_000_000,
    structureSell: 262_500_000,
    structureBuy: 241_000_000,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.03,
  salesTaxRate: 0.036,
  margins: {
    jitaSell: { revenue: 268_000_000, fees: 17_688_000, profit: 51_042_000, margin: 19.05 },
  },
  dailyVolume: 37,
  missingData: [],
  warnings: [],
}

const outcomes = apiOutcomesByPath()

function respondToMarginOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`/industry/profit-margin/${typeId}`, outcome)
}

async function mountShowingTheIshtarMargin(): Promise<VueWrapper> {
  respondToMarginOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_MARGIN))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
    props: { initialTypeId: ISHTAR_TYPE_ID },
  })
  await flushPromises()
  expect(wrapper.text()).toContain(ISHTAR_ONLY_MATERIAL)
  return wrapper
}

async function analyze(wrapper: VueWrapper, typeId: number): Promise<void> {
  await wrapper.setProps({ initialTypeId: typeId })
  await flushPromises()
}

function marginErrorText(wrapper: VueWrapper): string | null {
  const marginError = wrapper.find('[data-testid="margin-error"]')
  return marginError.exists() ? marginError.text() : null
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/industry/settings') return USER_SETTINGS
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('ProfitMarginTab analysis failure (issue 189)', () => {
  it('shows the failure of the Vexor analysis in the tab instead of the Ishtar margin', async () => {
    const wrapper = await mountShowingTheIshtarMargin()
    respondToMarginOf(VEXOR_TYPE_ID, rejectWith(new Error(PRICE_DATA_UNAVAILABLE)))

    await analyze(wrapper, VEXOR_TYPE_ID)

    const marginError = wrapper.find('[data-testid="margin-error"]')
    expect({
      marginError: marginError.exists() ? marginError.text() : null,
      marginErrorIsRed: marginError.exists() ? marginError.classes('text-red-400') : null,
      ishtarMaterialShown: wrapper.text().includes(ISHTAR_ONLY_MATERIAL),
      lastUpdatedShown: wrapper.text().includes(en.industry.costEstimation.lastUpdated),
      emptyStateShown: wrapper.text().includes(en.industry.margins.selectItem),
      marginResult: useProjectsStore().marginResult,
      pageError: useProjectsStore().error,
    }).toEqual({
      marginError: PRICE_DATA_UNAVAILABLE,
      marginErrorIsRed: true,
      ishtarMaterialShown: false,
      lastUpdatedShown: false,
      emptyStateShown: false,
      marginResult: null,
      pageError: null,
    })
  })

  it('removes the failure and shows the Ishtar margin when the next analysis succeeds', async () => {
    const wrapper = await mountShowingTheIshtarMargin()
    respondToMarginOf(VEXOR_TYPE_ID, rejectWith(new Error(PRICE_DATA_UNAVAILABLE)))
    await analyze(wrapper, VEXOR_TYPE_ID)
    respondToMarginOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_MARGIN))

    await analyze(wrapper, ISHTAR_TYPE_ID)

    expect({ marginError: marginErrorText(wrapper), ishtarMaterialShown: wrapper.text().includes(ISHTAR_ONLY_MATERIAL) })
      .toEqual({ marginError: null, ishtarMaterialShown: true })
  })

  it('keeps the Ishtar margin under the loading line while the Vexor analysis is loading', async () => {
    const wrapper = await mountShowingTheIshtarMargin()
    respondToMarginOf(VEXOR_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))

    await analyze(wrapper, VEXOR_TYPE_ID)

    expect({
      ishtarMaterialShown: wrapper.text().includes(ISHTAR_ONLY_MATERIAL),
      loadingShown: wrapper.text().includes(en.common.status.loading),
      marginError: marginErrorText(wrapper),
    }).toEqual({ ishtarMaterialShown: true, loadingShown: true, marginError: null })
  })
})
