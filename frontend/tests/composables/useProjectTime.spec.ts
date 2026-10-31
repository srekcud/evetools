import { ref } from 'vue'
import { formatDuration, useProjectTime } from '@/composables/useProjectTime'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

function stepOf(overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return {
    id: 'step-282',
    blueprintTypeId: 28301,
    productTypeId: 28302,
    productTypeName: 'Root Product',
    quantity: 2,
    runs: 2,
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

function projectOf(steps: IndustryProjectStep[]): IndustryProject {
  return {
    id: 'project-282',
    productTypeName: 'Root Product',
    productTypeId: 28302,
    displayName: null,
    runs: 2,
    meLevel: 10,
    teLevel: 0,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-30T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
  }
}

const ROOT_STEP_OF_9000_SECONDS_PER_RUN = stepOf({ id: 'step-282-root', timePerRun: 9000, runs: 2 })

function componentStepWithUnknownTimePerRun(overrides: Partial<IndustryProjectStep> = {}): IndustryProjectStep {
  return stepOf({
    id: 'step-282-component',
    blueprintTypeId: 28311,
    productTypeId: 28312,
    productTypeName: 'Component Without Known Skills',
    quantity: 4,
    runs: 4,
    depth: 1,
    timePerRun: null,
    ...overrides,
  })
}

function estimatedProjectTimeOf(steps: IndustryProjectStep[]) {
  const { estimatedProjectTime, isEstimatedProjectTimeUnknown } = useProjectTime(ref(projectOf(steps)))
  return { estimatedProjectTime: estimatedProjectTime.value, isEstimatedProjectTimeUnknown: isEstimatedProjectTimeUnknown.value }
}

describe('estimated project time with an unknown time per run', () => {
  it('is unknown when a step to build has an unknown time per run', () => {
    expect(estimatedProjectTimeOf([ROOT_STEP_OF_9000_SECONDS_PER_RUN, componentStepWithUnknownTimePerRun()])).toEqual({
      estimatedProjectTime: null,
      isEstimatedProjectTimeUnknown: true,
    })
  })

  it('is unknown when the unknown time per run is at the same depth as a longer known step', () => {
    const longerComponentStep = stepOf({
      id: 'step-282-longer-component',
      blueprintTypeId: 28321,
      productTypeId: 28322,
      productTypeName: 'Longer Component',
      quantity: 3,
      runs: 3,
      depth: 1,
      timePerRun: 6000,
    })

    expect(
      estimatedProjectTimeOf([ROOT_STEP_OF_9000_SECONDS_PER_RUN, longerComponentStep, componentStepWithUnknownTimePerRun()]),
    ).toEqual({ estimatedProjectTime: null, isEstimatedProjectTimeUnknown: true })
  })

  it('is unknown when a manufacturing step has an unknown time per run next to its copy step with a known one', () => {
    const manufacturingStep = stepOf({ id: 'step-282-manufacturing', blueprintTypeId: 28331, productTypeId: 28332, quantity: 13, runs: 13, timePerRun: null })
    const copyStep = stepOf({ id: 'step-282-copy', blueprintTypeId: 28331, productTypeId: 28331, quantity: 13, runs: 13, activityType: 'copy', timePerRun: 14100 })

    expect(estimatedProjectTimeOf([manufacturingStep, copyStep])).toEqual({
      estimatedProjectTime: null,
      isEstimatedProjectTimeUnknown: true,
    })
  })

  it('is the known total when the step with an unknown time per run is in stock', () => {
    const result = estimatedProjectTimeOf([ROOT_STEP_OF_9000_SECONDS_PER_RUN, componentStepWithUnknownTimePerRun({ inStock: true })])

    expect(result).toEqual({ estimatedProjectTime: 18000, isEstimatedProjectTimeUnknown: false })
    expect(formatDuration(result.estimatedProjectTime)).toBe('5h 0m')
  })

  it('is the known total when the step with an unknown time per run is purchased', () => {
    expect(
      estimatedProjectTimeOf([ROOT_STEP_OF_9000_SECONDS_PER_RUN, componentStepWithUnknownTimePerRun({ purchased: true })]),
    ).toEqual({ estimatedProjectTime: 18000, isEstimatedProjectTimeUnknown: false })
  })

  it('is neither known nor unknown when every step is purchased', () => {
    expect(
      estimatedProjectTimeOf([
        stepOf({ id: 'step-282-root', timePerRun: 9000, runs: 2, purchased: true }),
        componentStepWithUnknownTimePerRun({ purchased: true }),
      ]),
    ).toEqual({ estimatedProjectTime: null, isEstimatedProjectTimeUnknown: false })
  })

  it('sums the durations of the runs of each depth rather than of their quantities', () => {
    const rootStepOfFourRunsOfTenUnits = stepOf({ id: 'step-282-root-batch', quantity: 40, runs: 4, timePerRun: 3600 })
    const componentStepOfThreeRunsOfAHundredUnits = stepOf({
      id: 'step-282-component-batch',
      blueprintTypeId: 28341,
      productTypeId: 28342,
      productTypeName: 'Batch Component',
      quantity: 300,
      runs: 3,
      depth: 1,
      timePerRun: 1000,
    })

    expect(estimatedProjectTimeOf([rootStepOfFourRunsOfTenUnits, componentStepOfThreeRunsOfAHundredUnits])).toEqual({
      estimatedProjectTime: 17400,
      isEstimatedProjectTimeUnknown: false,
    })
  })
})
