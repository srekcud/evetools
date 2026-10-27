import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginResult } from '@/stores/industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HAIL_L_TYPE_ID = 12_779

const HAIL_L_MARGIN_RESULT: ProfitMarginResult = {
  typeId: HAIL_L_TYPE_ID,
  typeName: 'Hail L',
  isT2: false,
  runs: 5,
  outputQuantity: 25_000,
  outputPerRun: 5_000,
  materialCost: 812_500,
  materials: [
    { typeId: 11_399, typeName: 'Morphite', quantity: 75, unitPrice: 9_100, totalPrice: 682_500 },
    { typeId: 34, typeName: 'Tritanium', quantity: 26_000, unitPrice: 5, totalPrice: 130_000 },
  ],
  jobInstallCost: 14_250,
  jobInstallSteps: [
    {
      productTypeId: HAIL_L_TYPE_ID,
      productName: 'Hail L',
      activityType: 'manufacturing',
      runs: 5,
      installCost: 14_250,
      missingData: [],
    },
  ],
  inventionCost: 0,
  totalCost: 826_750,
  costPerUnit: 33.07,
  invention: null,
  sellPrices: {
    jitaSell: 41.5,
    structureSell: 43.2,
    structureBuy: 36.9,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.03,
  salesTaxRate: 0.045,
  margins: {
    jitaSell: { revenue: 1_037_500, fees: 77_812, profit: 132_938, margin: 12.81 },
    structureSell: { revenue: 1_080_000, fees: 81_000, profit: 172_250, margin: 15.95 },
    structureBuy: { revenue: 922_500, fees: 41_512, profit: 54_238, margin: 5.88 },
    contractSell: null,
  },
  dailyVolume: 410_000,
  missingData: [],
}

function mountProfitMargin() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValue(null)
  useProjectsStore().marginResult = HAIL_L_MARGIN_RESULT
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

describe('ProfitMarginTab on a narrow screen', () => {
  it('puts the venue, material cost and job install cost tables in horizontally scrollable containers', () => {
    const wrapper = mountProfitMargin()

    expect(tablesInScrollContainer(wrapper)).toEqual([true, true, true])
  })
})
