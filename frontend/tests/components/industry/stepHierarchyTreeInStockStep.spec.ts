import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import StepHierarchyTree from '@/components/industry/StepHierarchyTree.vue'
import type { IndustryProjectStep, ProductionTreeNode } from '@/stores/industry/types'

const IN_STOCK_LABEL = en.industry.step.inStock
const TO_LAUNCH_LABEL = en.industry.stepStatus.toLaunch
const FERNITE_ALLOY = 16656
const FERNITE_CARBIDE = 16673
const SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000

function node(productTypeId: number, productTypeName: string, quantity: number, runs: number, depth: number, materials: ProductionTreeNode['materials']): ProductionTreeNode {
  return {
    blueprintTypeId: productTypeId + SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET,
    productTypeId,
    productTypeName,
    quantity,
    runs,
    depth,
    activityType: 'reaction',
    hasCopy: false,
    materials,
    structureBonus: 0,
    structureName: null,
    productCategory: null,
  }
}

const FERNITE_ALLOY_NODE = node(FERNITE_ALLOY, 'Fernite Alloy', 400, 2, 1, [
  { typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 10, isBuildable: false },
  { typeId: 16639, typeName: 'Scandium', quantity: 200, isBuildable: false },
])

const FERNITE_CARBIDE_TREE = node(FERNITE_CARBIDE, 'Fernite Carbide', 40000, 4, 0, [
  { typeId: FERNITE_ALLOY, typeName: 'Fernite Alloy', quantity: 400, isBuildable: true, blueprint: FERNITE_ALLOY_NODE },
])

function ferniteAlloyStepOf(overrides: Pick<IndustryProjectStep, 'inStockQuantity' | 'purchasedQuantity' | 'inStock'>): IndustryProjectStep {
  return {
    id: 'step-182-fernite-alloy',
    blueprintTypeId: FERNITE_ALLOY + SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET,
    productTypeId: FERNITE_ALLOY,
    productTypeName: 'Fernite Alloy',
    quantity: 400,
    runs: 2,
    depth: 1,
    activityType: 'reaction',
    runsFixed: false,
    sortOrder: 1,
    purchased: false,
    meLevel: 0,
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

function statusAndPurchasedCheckboxesOf(step: IndustryProjectStep) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(StepHierarchyTree, {
    global: { plugins: [i18n] },
    props: { tree: FERNITE_CARBIDE_TREE, steps: [step] },
  })
  const statusBadges = wrapper.findAll('span.px-2.py-1')

  return {
    statusLabels: statusBadges.map(badge => badge.text()),
    statusInGreen: statusBadges.map(badge => badge.classes().includes('text-green-400')),
    purchasedCheckboxes: wrapper.findAll('input[type="checkbox"]').length,
  }
}

enableAutoUnmount(afterEach)

describe('StepHierarchyTree status of a step from its in-stock flag', () => {
  it('shows a step to launch with its purchased checkbox when the server says it is not in stock although its purchases cover its quantity', () => {
    expect(statusAndPurchasedCheckboxesOf(ferniteAlloyStepOf({ inStockQuantity: 0, purchasedQuantity: 400, inStock: false }))).toEqual({
      statusLabels: [TO_LAUNCH_LABEL],
      statusInGreen: [false],
      purchasedCheckboxes: 1,
    })
  })

  it('shows a step in stock without purchased checkbox when the server says so without stock nor purchased quantity', () => {
    expect(statusAndPurchasedCheckboxesOf(ferniteAlloyStepOf({ inStockQuantity: 0, purchasedQuantity: 0, inStock: true }))).toEqual({
      statusLabels: [IN_STOCK_LABEL],
      statusInGreen: [true],
      purchasedCheckboxes: 0,
    })
  })
})
