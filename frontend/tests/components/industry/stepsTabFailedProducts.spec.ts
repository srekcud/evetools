import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepsTab from '@/components/industry/StepsTab.vue'
import StepHierarchyTree from '@/components/industry/StepHierarchyTree.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, IndustryProjectStep, ProductionTreeNode, ShoppingListFailedProduct } from '@/stores/industry/types'

const PROJECT_ID = 'project-193'
const FERNITE_CARBIDE_TYPE_ID = 16673
const FERNITE_CARBIDE_NAME = 'Fernite Carbide'
const FERNITE_CARBIDE_FORMULA_TYPE_ID = 1016673
const FERNITE_ALLOY_TYPE_ID = 16656
const FERNITE_ALLOY_NAME = 'Fernite Alloy'
const FERNITE_ALLOY_FORMULA_TYPE_ID = 1016656
const SCANDIUM_MISSING_REASON = 'Types missing from the SDE: 16639'
const FERNITE_ALLOY_MISSING_REASON = 'Types missing from the SDE: 16656'
const WARNING = '[data-testid="failed-products-warning"]'
const EN_WARNING_MESSAGE = 'The production tree of these products could not be built.'
const FR_WARNING_MESSAGE = "L'arbre de production de ces produits n'a pas pu etre construit."
const TREE_UNAVAILABLE = 'Tree unavailable'

const FERNITE_ALLOY_FAILED: ShoppingListFailedProduct[] = [{ typeId: FERNITE_ALLOY_TYPE_ID, reason: SCANDIUM_MISSING_REASON }]

function step(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType'>): IndustryProjectStep {
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

function projectSteps(ferniteAlloyName: string | null = FERNITE_ALLOY_NAME): IndustryProjectStep[] {
  return [
    step({
      id: 'step-fernite-carbide',
      blueprintTypeId: FERNITE_CARBIDE_FORMULA_TYPE_ID,
      productTypeId: FERNITE_CARBIDE_TYPE_ID,
      productTypeName: FERNITE_CARBIDE_NAME,
      quantity: 10000,
      runs: 1,
      depth: 0,
      activityType: 'reaction',
    }),
    step({
      id: 'step-fernite-alloy',
      blueprintTypeId: FERNITE_ALLOY_FORMULA_TYPE_ID,
      productTypeId: FERNITE_ALLOY_TYPE_ID,
      productTypeName: ferniteAlloyName,
      quantity: 200,
      runs: 1,
      depth: 1,
      activityType: 'reaction',
    }),
  ]
}

function ferniteCarbideTree(): ProductionTreeNode {
  return {
    blueprintTypeId: FERNITE_CARBIDE_FORMULA_TYPE_ID,
    productTypeId: FERNITE_CARBIDE_TYPE_ID,
    productTypeName: FERNITE_CARBIDE_NAME,
    quantity: 10000,
    runs: 1,
    depth: 0,
    activityType: 'reaction',
    hasCopy: false,
    materials: [{ typeId: FERNITE_ALLOY_TYPE_ID, typeName: FERNITE_ALLOY_NAME, quantity: 100, isBuildable: false }],
    structureBonus: 0,
    structureName: null,
    productCategory: null,
  }
}

function project(overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: FERNITE_CARBIDE_NAME,
    productTypeId: FERNITE_CARBIDE_TYPE_ID,
    displayName: null,
    runs: 1,
    meLevel: 0,
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
    createdAt: '2026-10-29T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps: projectSteps(),
    tree: null,
    failedProducts: [],
    ...overrides,
  }
}

function mountStepsTab(loadedProject: IndustryProject, locale: 'en' | 'fr' = 'en') {
  useProjectsStore().currentProject = loadedProject
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(StepsTab, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n], stubs: { StepHierarchyTree: true } },
  })
}

async function showHierarchy(wrapper: VueWrapper) {
  const treeButton = wrapper.findAll('button').find(button => button.text() === 'Tree')
  if (!treeButton) throw new Error('No tree view button')
  await treeButton.trigger('click')
}

function warningRows(wrapper: VueWrapper): string[] {
  return wrapper.findAll(WARNING).flatMap(warning => warning.findAll('li').map(row => row.text()))
}

function nameThenReasonOf(row: string, name: string, reason: string): boolean {
  const nameIndex = row.indexOf(name)
  return nameIndex !== -1 && row.indexOf(reason, nameIndex + name.length) !== -1
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepsTab failed products', () => {
  it('warns about a failed product above the flat view and keeps the steps', () => {
    const wrapper = mountStepsTab(project({ failedProducts: FERNITE_ALLOY_FAILED }))

    expect(wrapper.findAll(WARNING)).toHaveLength(1)
    const warning = wrapper.find(WARNING).text()
    expect(warning).toContain(EN_WARNING_MESSAGE)
    expect(warning).toContain(FERNITE_ALLOY_NAME)
    expect(warning).toContain(SCANDIUM_MISSING_REASON)
    expect(wrapper.findAll('.step-row:not(.ml-6)')).toHaveLength(2)
  })

  it('warns about a failed product above the unavailable tree in the hierarchy view', async () => {
    const wrapper = mountStepsTab(project({ failedProducts: FERNITE_ALLOY_FAILED }))

    await showHierarchy(wrapper)

    expect(wrapper.findAll(WARNING)).toHaveLength(1)
    expect(warningRows(wrapper).map(row => nameThenReasonOf(row, FERNITE_ALLOY_NAME, SCANDIUM_MISSING_REASON))).toEqual([true])
    expect(wrapper.text()).toContain(TREE_UNAVAILABLE)
  })

  it('lists every failed product in the order of the API and labels a product missing from the SDE as an unknown name', async () => {
    const wrapper = mountStepsTab(project({
      steps: projectSteps(null),
      failedProducts: [
        { typeId: FERNITE_ALLOY_TYPE_ID, reason: FERNITE_ALLOY_MISSING_REASON },
        { typeId: FERNITE_CARBIDE_TYPE_ID, reason: FERNITE_ALLOY_MISSING_REASON },
      ],
    }))

    await showHierarchy(wrapper)

    const rows = warningRows(wrapper)
    expect(rows).toHaveLength(2)
    expect([
      nameThenReasonOf(rows[0] ?? '', `Unknown name (#${FERNITE_ALLOY_TYPE_ID})`, FERNITE_ALLOY_MISSING_REASON),
      nameThenReasonOf(rows[1] ?? '', FERNITE_CARBIDE_NAME, FERNITE_ALLOY_MISSING_REASON),
    ]).toEqual([true, true])
  })

  it('warns about a failed product outside the shown tree and keeps the tree', async () => {
    const wrapper = mountStepsTab(project({ tree: ferniteCarbideTree(), failedProducts: FERNITE_ALLOY_FAILED }))

    await showHierarchy(wrapper)

    expect(wrapper.findComponent(StepHierarchyTree).exists()).toBe(true)
    expect(warningRows(wrapper).map(row => nameThenReasonOf(row, FERNITE_ALLOY_NAME, SCANDIUM_MISSING_REASON))).toEqual([true])
    expect(wrapper.text()).not.toContain(TREE_UNAVAILABLE)
  })

  it('shows the tree without warning when no product failed', async () => {
    const wrapper = mountStepsTab(project({ tree: ferniteCarbideTree(), failedProducts: [] }))

    await showHierarchy(wrapper)

    expect(wrapper.findComponent(StepHierarchyTree).exists()).toBe(true)
    expect(wrapper.find(WARNING).exists()).toBe(false)
    expect(wrapper.text()).not.toContain(TREE_UNAVAILABLE)
  })

  it('shows the unavailable tree without warning for a project without failed product', async () => {
    const wrapper = mountStepsTab(project({ steps: [], tree: null, failedProducts: [] }))

    await showHierarchy(wrapper)

    expect(wrapper.find(WARNING).exists()).toBe(false)
    expect(wrapper.text()).toContain(TREE_UNAVAILABLE)
  })

  it('shows no warning when the failed products are not computed', async () => {
    const wrapper = mountStepsTab(project({ tree: null, failedProducts: null }))

    expect(wrapper.find(WARNING).exists()).toBe(false)
    await showHierarchy(wrapper)
    expect(wrapper.find(WARNING).exists()).toBe(false)
    expect(wrapper.text()).toContain(TREE_UNAVAILABLE)
  })

  it('warns about a failed product in french', () => {
    const wrapper = mountStepsTab(project({ failedProducts: FERNITE_ALLOY_FAILED }), 'fr')

    expect(wrapper.findAll(WARNING).map(warning => warning.text().includes(FR_WARNING_MESSAGE))).toEqual([true])
  })
})
