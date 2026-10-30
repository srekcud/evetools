import { enableAutoUnmount, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StepTree from '@/components/industry/StepTree.vue'
import type { IndustryProjectStep } from '@/stores/industry/types'

const UNKNOWN_VALUE = `[title="${en.common.unknownValue}"]`
const RECOMMENDED_CHARACTER_BADGE = `[title="${en.industry.stepTree.recommendedCharacter}"]`

function stepOf(overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return {
    id: 'step-282',
    blueprintTypeId: 28201,
    productTypeId: 28202,
    productTypeName: 'Unknown Skills Hull',
    quantity: 18,
    runs: 18,
    depth: 0,
    activityType: 'manufacturing',
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
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

function jobOfASplitGroup(splitIndex: number, overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return stepOf({
    id: `step-282-job-${splitIndex}`,
    blueprintTypeId: 28211,
    productTypeId: 28212,
    productTypeName: 'Unknown Skills Battleship',
    quantity: 9,
    runs: 9,
    splitGroupId: 'split-group-282',
    splitIndex,
    totalGroupRuns: 18,
    ...overrides,
  })
}

function mountStepTree(steps: IndustryProjectStep[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(StepTree, { props: { steps }, global: { plugins: [i18n] } })
}

async function expandedStepRow(wrapper: VueWrapper) {
  await wrapper.get('button[aria-expanded="false"]').trigger('click')
  const stepRows = wrapper.findAll('.step-row').filter(row => !row.find('button[aria-expanded]').exists())
  expect(stepRows).toHaveLength(1)
  return stepRows[0]
}

function unknownValuesOf(element: Pick<DOMWrapper<Element>, 'findAll'>) {
  return element.findAll(UNKNOWN_VALUE).map(unknownValue => unknownValue.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepTree with an unknown time per run', () => {
  it('shows the duration line of a step to build with an unknown time per run as an unknown value without per run duration', async () => {
    const wrapper = mountStepTree([stepOf({ timePerRun: null, runs: 18 })])

    const stepRow = await expandedStepRow(wrapper)

    expect(unknownValuesOf(stepRow)).toEqual(['—'])
    expect(stepRow.text()).not.toContain('/run')
  })

  it('shows no duration line for a purchased step with an unknown time per run', async () => {
    const wrapper = mountStepTree([stepOf({ id: 'step-282-purchased', blueprintTypeId: 28221, productTypeId: 28222, productTypeName: 'Purchased Plate', quantity: 7, runs: 7, purchased: true, timePerRun: null })])

    const stepRow = await expandedStepRow(wrapper)

    expect(unknownValuesOf(stepRow)).toEqual([])
    expect(stepRow.text()).not.toContain('/run')
  })

  it('keeps the duration and the per run duration of a step to build with a known time per run', async () => {
    const wrapper = mountStepTree([stepOf({ id: 'step-282-known', blueprintTypeId: 28231, productTypeId: 28232, productTypeName: 'Known Time Frigate', quantity: 2, runs: 2, timePerRun: 9000 })])

    const stepRow = await expandedStepRow(wrapper)

    expect(unknownValuesOf(stepRow)).toEqual([])
    expect(stepRow.text()).toContain('5h 0m')
    expect(stepRow.text()).toContain('(2h 30m/run)')
  })

  it('shows the total of a split group whose jobs to build have an unknown time per run as an unknown value', () => {
    const wrapper = mountStepTree([
      jobOfASplitGroup(0, { timePerRun: null }),
      jobOfASplitGroup(1, { timePerRun: null }),
    ])

    expect(unknownValuesOf(wrapper.get('button[aria-expanded]'))).toEqual(['—'])
  })

  it('shows no unknown total for a split group whose jobs are all purchased', () => {
    const wrapper = mountStepTree([
      jobOfASplitGroup(0, { timePerRun: null, purchased: true }),
      jobOfASplitGroup(1, { timePerRun: null, purchased: true }),
    ])

    expect(unknownValuesOf(wrapper.get('button[aria-expanded]'))).toEqual([])
  })

  it('shows the total of a split group as an unknown value when one job to build has a known time per run and the other an unknown one', () => {
    const wrapper = mountStepTree([
      jobOfASplitGroup(0, { timePerRun: 9000 }),
      jobOfASplitGroup(1, { timePerRun: null }),
    ])

    const splitGroupHeader = wrapper.get('button[aria-expanded]')
    expect(unknownValuesOf(splitGroupHeader)).toEqual(['—'])
    expect(splitGroupHeader.text()).not.toContain('22h 30m')
  })

  it('shows no duration line for a step in stock with an unknown time per run', async () => {
    const wrapper = mountStepTree([stepOf({ id: 'step-282-in-stock', blueprintTypeId: 28241, productTypeId: 28242, productTypeName: 'Stocked Plate', quantity: 5, runs: 5, inStock: true, inStockQuantity: 5, timePerRun: null })])

    const stepRow = await expandedStepRow(wrapper)

    expect(unknownValuesOf(stepRow)).toEqual([])
    expect(stepRow.text()).not.toContain('/run')
  })

  it('takes the duration of a step from its runs rather than from its quantity', async () => {
    const wrapper = mountStepTree([stepOf({ id: 'step-282-batch', blueprintTypeId: 28251, productTypeId: 28252, productTypeName: 'Batch Fuel Block', quantity: 160, runs: 4, timePerRun: 3600 })])

    const stepRow = await expandedStepRow(wrapper)

    expect(stepRow.text()).toContain('4h 0m')
    expect(stepRow.text()).toContain('(1h 0m/run)')
    expect(stepRow.text()).not.toContain('6j 16h')
  })

  it('shows no recommended character badge on a step to build with an unknown time per run', async () => {
    const wrapper = mountStepTree([stepOf({ timePerRun: null, recommendedCharacterName: null })])

    const stepRow = await expandedStepRow(wrapper)

    expect(stepRow.findAll(RECOMMENDED_CHARACTER_BADGE)).toHaveLength(0)
  })

  it('shows the recommended character badge on a step to build with a known time per run', async () => {
    const wrapper = mountStepTree([stepOf({ id: 'step-282-recommended', blueprintTypeId: 28261, productTypeId: 28262, productTypeName: 'Recommended Cruiser', quantity: 3, runs: 3, timePerRun: 7200, recommendedCharacterName: 'Main' })])

    const stepRow = await expandedStepRow(wrapper)

    expect(stepRow.findAll(RECOMMENDED_CHARACTER_BADGE).map(badge => badge.text())).toEqual(['Main'])
  })
})
