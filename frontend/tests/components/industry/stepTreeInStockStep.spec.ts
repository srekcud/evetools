import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StepTree from '@/components/industry/StepTree.vue'
import type { IndustryProjectStep } from '@/stores/industry/types'

const IN_STOCK_LABEL = en.industry.step.inStock
const TO_LAUNCH_LABEL = en.industry.stepStatus.toLaunch
const PURCHASED_LABEL = en.industry.step.purchased

function stepOf(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'purchasedQuantity' | 'inStock'>): IndustryProjectStep {
  return {
    depth: 1,
    activityType: 'manufacturing',
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
    teLevel: 20,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
    deletable: true,
    timePerRun: null,
    recommendedCharacterName: null,
    splitGroupId: null,
    splitIndex: 0,
    totalGroupRuns: null,
    facilityInfoType: null,
    actualFacilityName: null,
    bestStructureName: null,
    bestMaterialBonus: null,
    jobMatches: [],
    jobsCost: null,
    purchases: [],
    purchasesCost: null,
    esiJobId: null,
    esiJobCost: null,
    esiJobStatus: null,
    esiJobEndDate: null,
    esiJobRuns: null,
    esiJobCharacterName: null,
    esiJobsCount: null,
    esiJobsTotalRuns: null,
    esiJobsActiveRuns: null,
    esiJobsDeliveredRuns: null,
    manualJobData: false,
    recommendedStructureName: null,
    structureBonus: null,
    similarJobs: [],
    ...overrides,
  }
}

function nitrogenFuelBlockStepInStock(): IndustryProjectStep {
  return stepOf({
    id: 'step-182-nitrogen-fuel-block',
    blueprintTypeId: 4314,
    productTypeId: 4051,
    productTypeName: 'Nitrogen Fuel Block',
    quantity: 120,
    runs: 3,
    purchasedQuantity: 0,
    inStock: true,
  })
}

function kestrelStepPurchasedButNotInStock(): IndustryProjectStep {
  return stepOf({
    id: 'step-182-kestrel',
    blueprintTypeId: 947,
    productTypeId: 602,
    productTypeName: 'Kestrel',
    quantity: 6,
    runs: 6,
    purchasedQuantity: 6,
    inStock: false,
  })
}

function mountStepTree(steps: IndustryProjectStep[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(StepTree, { props: { steps, readonly: false }, global: { plugins: [i18n] } })
}

async function expandEveryProduct(wrapper: VueWrapper) {
  for (const expandButton of wrapper.findAll('button[aria-expanded="false"]')) {
    await expandButton.trigger('click')
  }
}

function statusAndPurchasedCheckboxesOf(wrapper: VueWrapper) {
  return {
    inStockLabels: wrapper.findAll('span').filter(span => span.text() === IN_STOCK_LABEL).length,
    toLaunchLabels: wrapper.findAll('span').filter(span => span.text() === TO_LAUNCH_LABEL).length,
    purchasedCheckboxes: wrapper.findAll('label').filter(label => label.find('input[type="checkbox"]').exists() && label.text() === PURCHASED_LABEL).length,
  }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepTree status of a step from its in-stock flag', () => {
  it('shows a step in stock with the purchased checkbox of its product only', async () => {
    const wrapper = mountStepTree([nitrogenFuelBlockStepInStock()])

    await expandEveryProduct(wrapper)

    expect(statusAndPurchasedCheckboxesOf(wrapper)).toEqual({ inStockLabels: 1, toLaunchLabels: 0, purchasedCheckboxes: 1 })
  })

  it('shows the purchased checkboxes of its product and of a step not in stock even when its purchases cover its quantity', async () => {
    const wrapper = mountStepTree([kestrelStepPurchasedButNotInStock()])

    await expandEveryProduct(wrapper)

    expect(statusAndPurchasedCheckboxesOf(wrapper)).toEqual({ inStockLabels: 0, toLaunchLabels: 1, purchasedCheckboxes: 2 })
  })
})
