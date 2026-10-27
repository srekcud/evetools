import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import CostEstimationTab from '@/components/industry/CostEstimationTab.vue'
import { apiRequest } from '@/services/api'
import type { CopyCosts, CostEstimation } from '@/stores/industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-226'
const VEXOR_TYPE_ID = 626

const VEXOR_COST_ESTIMATION: CostEstimation = {
  id: PROJECT_ID,
  materialCost: 9_340_000,
  jobInstallCost: 182_000,
  bpoCost: 0,
  totalCost: 9_522_000,
  perUnit: 3_174_000,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 1_350_000, unitPrice: 4.8, totalPrice: 6_480_000 },
    { typeId: 36, typeName: 'Mexallon', quantity: 52_000, unitPrice: 55, totalPrice: 2_860_000 },
  ],
  jobInstallSteps: [
    {
      stepId: 'step-vexor',
      productTypeId: VEXOR_TYPE_ID,
      productName: 'Vexor',
      solarSystemId: 30_002_813,
      systemName: 'Tama',
      costIndex: 0.0412,
      runs: 3,
      installCost: 182_000,
      unknownReason: null,
      missingAdjustedPriceTypeIds: [],
    },
  ],
  failedProducts: [],
}

const VEXOR_COPY_COSTS: CopyCosts = {
  id: PROJECT_ID,
  copies: [
    { blueprintTypeId: 1_000_626, blueprintName: 'Vexor Blueprint', productTypeName: 'Vexor', runs: 3, cost: 21_500, depth: 0 },
  ],
  totalCopyCost: 21_500,
}

async function mountLoadedCostEstimation() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url.endsWith('/cost-estimation')) return VEXOR_COST_ESTIMATION
    return VEXOR_COPY_COSTS
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(CostEstimationTab, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as { activate: () => Promise<void> }).activate()
  await flushPromises()
  return wrapper
}

describe('CostEstimationTab on a narrow screen', () => {
  it('puts the material cost, job install cost and copy cost tables in horizontally scrollable containers', async () => {
    const wrapper = await mountLoadedCostEstimation()

    expect(tablesInScrollContainer(wrapper)).toEqual([true, true, true])
  })
})
