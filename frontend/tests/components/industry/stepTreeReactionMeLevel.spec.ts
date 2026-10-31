import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StepTree from '@/components/industry/StepTree.vue'
import type { IndustryProjectStep } from '@/stores/industry/types'

const ME_LABEL = en.industry.createProject.me
const TE_LABEL = en.industry.createProject.te

function stepOf(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType' | 'meLevel' | 'teLevel'>): IndustryProjectStep {
  return {
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
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

function ferniteCarbideReactionStep(overrides: Partial<IndustryProjectStep> = {}): IndustryProjectStep {
  return stepOf({
    id: 'step-190-fernite-carbide',
    blueprintTypeId: 46166,
    productTypeId: 16673,
    productTypeName: 'Fernite Carbide',
    quantity: 200000,
    runs: 20,
    depth: 0,
    activityType: 'reaction',
    meLevel: 4,
    teLevel: 12,
    ...overrides,
  })
}

function hydrogenFuelBlockManufacturingStep(overrides: Partial<IndustryProjectStep> = {}): IndustryProjectStep {
  return stepOf({
    id: 'step-190-hydrogen-fuel-block',
    blueprintTypeId: 4316,
    productTypeId: 4246,
    productTypeName: 'Hydrogen Fuel Block',
    quantity: 150,
    runs: 4,
    depth: 1,
    activityType: 'manufacturing',
    meLevel: 8,
    teLevel: 14,
    ...overrides,
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

function valuesOfInputsLabelled(wrapper: VueWrapper, label: string): string[] {
  return wrapper.findAll(`input[aria-label="${label}"]`).map(input => (input.element as HTMLInputElement).value).sort()
}

async function textOfTheExpandedStepRow(wrapper: VueWrapper): Promise<string> {
  await expandEveryProduct(wrapper)
  const stepRows = wrapper.findAll('.step-row').filter(row => !row.find('button[aria-expanded]').exists())
  expect(stepRows).toHaveLength(1)
  return stepRows[0].text()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepTree material efficiency of a reaction step', () => {
  it('shows the ME input of the manufacturing step only and the TE input of both steps', async () => {
    const wrapper = mountStepTree([ferniteCarbideReactionStep(), hydrogenFuelBlockManufacturingStep()], false)

    await expandEveryProduct(wrapper)

    expect(valuesOfInputsLabelled(wrapper, ME_LABEL)).toEqual(['8'])
    expect(valuesOfInputsLabelled(wrapper, TE_LABEL)).toEqual(['12', '14'])
  })

  it('shows no ME label but the TE label of a reaction step in a read-only tree', async () => {
    const wrapper = mountStepTree([ferniteCarbideReactionStep()], true)

    const stepRowText = await textOfTheExpandedStepRow(wrapper)

    expect(stepRowText).not.toMatch(/ME\d/)
    expect(stepRowText).toContain('TE12')
  })

  it('shows no ME label but the TE label of a reaction step linked to an ESI job', async () => {
    const wrapper = mountStepTree([ferniteCarbideReactionStep({ esiJobId: 7190001 })], false)

    const stepRowText = await textOfTheExpandedStepRow(wrapper)

    expect(stepRowText).not.toMatch(/ME\d/)
    expect(stepRowText).toContain('TE12')
  })

  it('keeps the ME and TE labels of a manufacturing step in a read-only tree', async () => {
    const wrapper = mountStepTree([hydrogenFuelBlockManufacturingStep()], true)

    const stepRowText = await textOfTheExpandedStepRow(wrapper)

    expect(stepRowText).toContain('ME8')
    expect(stepRowText).toContain('TE14')
  })
})
