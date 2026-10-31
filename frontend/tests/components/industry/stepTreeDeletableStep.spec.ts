import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StepTree from '@/components/industry/StepTree.vue'
import type { IndustryProjectStep } from '@/stores/industry/types'

const DELETE_STEP_LABEL = en.industry.stepTree.deleteStep
const CONFIRM_DELETE_LABEL = en.common.actions.delete

function stepOf(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType' | 'deletable'>): IndustryProjectStep {
  return {
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 0,
    teLevel: 0,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
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
    purchasedQuantity: 0,
    inStock: false,
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

function ferniteAlloyStepHoldingStock(): IndustryProjectStep {
  return stepOf({
    id: 'step-202-fernite-alloy',
    blueprintTypeId: 46168,
    productTypeId: 16656,
    productTypeName: 'Fernite Alloy',
    quantity: 400,
    runs: 2,
    depth: 1,
    activityType: 'reaction',
    inStockQuantity: 100,
    deletable: false,
  })
}

function hydrogenFuelBlockStep(overrides: Partial<IndustryProjectStep> = {}): IndustryProjectStep {
  return stepOf({
    id: 'step-202-hydrogen-fuel-block',
    blueprintTypeId: 4316,
    productTypeId: 4246,
    productTypeName: 'Hydrogen Fuel Block',
    quantity: 30,
    runs: 1,
    depth: 2,
    activityType: 'manufacturing',
    meLevel: 10,
    deletable: true,
    ...overrides,
  })
}

function oxygenFuelBlockStepLinkedToAnEsiJob(): IndustryProjectStep {
  return stepOf({
    id: 'step-202-oxygen-fuel-block',
    blueprintTypeId: 4313,
    productTypeId: 4312,
    productTypeName: 'Oxygen Fuel Block',
    quantity: 5,
    runs: 1,
    depth: 2,
    activityType: 'manufacturing',
    meLevel: 8,
    esiJobId: 7202401,
    deletable: false,
  })
}

function mountStepTree(steps: IndustryProjectStep[], readonly: boolean) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(StepTree, { props: { steps, readonly }, global: { plugins: [i18n] } })
}

async function expandEveryProduct(wrapper: VueWrapper) {
  for (const expandButton of wrapper.findAll('button[aria-expanded="false"]')) {
    await expandButton.trigger('click')
  }
}

async function confirmTheDeletionOfTheOnlyDeleteButton(wrapper: VueWrapper) {
  await wrapper.get(`button[aria-label="${DELETE_STEP_LABEL}"]`).trigger('click')
  const confirmButton = Array.from(document.body.querySelectorAll('button')).find(button => button.textContent?.trim() === CONFIRM_DELETE_LABEL)
  confirmButton?.click()
  await wrapper.vm.$nextTick()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepTree delete button of a step', () => {
  it('shows the delete button of the deletable step only', async () => {
    const wrapper = mountStepTree([ferniteAlloyStepHoldingStock(), hydrogenFuelBlockStep()], false)

    await expandEveryProduct(wrapper)

    expect(wrapper.findAll(`button[aria-label="${DELETE_STEP_LABEL}"]`)).toHaveLength(1)
    await confirmTheDeletionOfTheOnlyDeleteButton(wrapper)
    expect(wrapper.emitted('delete-step')).toEqual([['step-202-hydrogen-fuel-block']])
  })

  it('shows no delete button in a read-only tree nor on a step linked to an ESI job', async () => {
    const readonlyWrapper = mountStepTree([hydrogenFuelBlockStep()], true)
    const editableWrapper = mountStepTree([oxygenFuelBlockStepLinkedToAnEsiJob()], false)

    await expandEveryProduct(readonlyWrapper)
    await expandEveryProduct(editableWrapper)

    expect([
      readonlyWrapper.findAll(`button[aria-label="${DELETE_STEP_LABEL}"]`).length,
      editableWrapper.findAll(`button[aria-label="${DELETE_STEP_LABEL}"]`).length,
    ]).toEqual([0, 0])
  })
})
