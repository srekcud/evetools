import { defineComponent, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { useShoppingStockAnalysis } from '@/composables/useShoppingStockAnalysis'
import type { IndustryProjectStep, ProductionTreeNode, ShoppingListItem } from '@/stores/industry/types'

const PROJECT_ID = 'project-173'

const ROOT_TYPE_ID = 12005
const ROOT_BLUEPRINT_TYPE_ID = 12006
const ROOT_NAME = 'Ishtar'
const INTERMEDIATE_TYPE_ID = 11694
const INTERMEDIATE_BLUEPRINT_TYPE_ID = 17330
const INTERMEDIATE_NAME = 'Fusion Reactor Unit'
const INTERMEDIATE_QUANTITY = 100
const CHILD_TYPE_ID = 17317
const CHILD_BLUEPRINT_TYPE_ID = 46207
const CHILD_NAME = 'Fermionic Condensates'
const CHILD_QUANTITY = 200
const RAW_MATERIAL_TYPE_ID = 34
const RAW_MATERIAL_NAME = 'Tritanium'
const RAW_MATERIAL_QUANTITY = 500

function step(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType'>): IndustryProjectStep {
  return {
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

const ROOT_STEP = step({
  id: 'step-root',
  blueprintTypeId: ROOT_BLUEPRINT_TYPE_ID,
  productTypeId: ROOT_TYPE_ID,
  productTypeName: ROOT_NAME,
  quantity: 1,
  runs: 1,
  depth: 0,
  activityType: 'manufacturing',
})

const INTERMEDIATE_STEP = step({
  id: 'step-intermediate',
  blueprintTypeId: INTERMEDIATE_BLUEPRINT_TYPE_ID,
  productTypeId: INTERMEDIATE_TYPE_ID,
  productTypeName: INTERMEDIATE_NAME,
  quantity: INTERMEDIATE_QUANTITY,
  runs: 100,
  depth: 1,
  activityType: 'manufacturing',
})

const CHILD_STEP = step({
  id: 'step-child',
  blueprintTypeId: CHILD_BLUEPRINT_TYPE_ID,
  productTypeId: CHILD_TYPE_ID,
  productTypeName: CHILD_NAME,
  quantity: CHILD_QUANTITY,
  runs: 2,
  depth: 2,
  activityType: 'reaction',
})

const SPLIT_GROUP_ID = 'split-intermediate'

const FIRST_SPLIT_INTERMEDIATE_STEP = step({
  id: 'step-intermediate-split-0',
  blueprintTypeId: INTERMEDIATE_BLUEPRINT_TYPE_ID,
  productTypeId: INTERMEDIATE_TYPE_ID,
  productTypeName: INTERMEDIATE_NAME,
  quantity: 60,
  runs: 60,
  depth: 1,
  activityType: 'manufacturing',
  splitGroupId: SPLIT_GROUP_ID,
  splitIndex: 0,
  totalGroupRuns: 100,
})

const SECOND_SPLIT_INTERMEDIATE_STEP = step({
  id: 'step-intermediate-split-1',
  blueprintTypeId: INTERMEDIATE_BLUEPRINT_TYPE_ID,
  productTypeId: INTERMEDIATE_TYPE_ID,
  productTypeName: INTERMEDIATE_NAME,
  quantity: 40,
  runs: 40,
  depth: 1,
  activityType: 'manufacturing',
  splitGroupId: SPLIT_GROUP_ID,
  splitIndex: 1,
  totalGroupRuns: 100,
})

const ROOT_PRODUCT_SUBCOMPONENT_STEP = step({
  id: 'step-root-product-depth-1',
  blueprintTypeId: ROOT_BLUEPRINT_TYPE_ID,
  productTypeId: ROOT_TYPE_ID,
  productTypeName: ROOT_NAME,
  quantity: 3,
  runs: 3,
  depth: 1,
  activityType: 'manufacturing',
})

const INTERMEDIATE_COPY_STEP = step({
  id: 'step-intermediate-copy',
  blueprintTypeId: INTERMEDIATE_BLUEPRINT_TYPE_ID,
  productTypeId: INTERMEDIATE_TYPE_ID,
  productTypeName: INTERMEDIATE_NAME,
  quantity: 5,
  runs: 5,
  depth: 1,
  activityType: 'copy',
})

function treeNode(overrides: Partial<ProductionTreeNode> & Pick<ProductionTreeNode, 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType' | 'materials'>): ProductionTreeNode {
  return {
    hasCopy: false,
    structureBonus: 0,
    structureName: null,
    productCategory: null,
    ...overrides,
  }
}

const TREE = treeNode({
  blueprintTypeId: ROOT_BLUEPRINT_TYPE_ID,
  productTypeId: ROOT_TYPE_ID,
  productTypeName: ROOT_NAME,
  quantity: 1,
  runs: 1,
  depth: 0,
  activityType: 'manufacturing',
  materials: [{
    typeId: INTERMEDIATE_TYPE_ID,
    typeName: INTERMEDIATE_NAME,
    quantity: INTERMEDIATE_QUANTITY,
    isBuildable: true,
    blueprint: treeNode({
      blueprintTypeId: INTERMEDIATE_BLUEPRINT_TYPE_ID,
      productTypeId: INTERMEDIATE_TYPE_ID,
      productTypeName: INTERMEDIATE_NAME,
      quantity: INTERMEDIATE_QUANTITY,
      runs: 100,
      depth: 1,
      activityType: 'manufacturing',
      materials: [
        {
          typeId: CHILD_TYPE_ID,
          typeName: CHILD_NAME,
          quantity: CHILD_QUANTITY,
          isBuildable: true,
          blueprint: treeNode({
            blueprintTypeId: CHILD_BLUEPRINT_TYPE_ID,
            productTypeId: CHILD_TYPE_ID,
            productTypeName: CHILD_NAME,
            quantity: CHILD_QUANTITY,
            runs: 2,
            depth: 2,
            activityType: 'reaction',
            materials: [],
          }),
        },
        { typeId: RAW_MATERIAL_TYPE_ID, typeName: RAW_MATERIAL_NAME, quantity: RAW_MATERIAL_QUANTITY, isBuildable: false },
      ],
    }),
  }],
})

const RAW_MATERIAL_LINE: ShoppingListItem = {
  typeId: RAW_MATERIAL_TYPE_ID,
  typeName: RAW_MATERIAL_NAME,
  quantity: RAW_MATERIAL_QUANTITY,
  volume: 0.01,
  totalVolume: 5,
  jitaUnitPrice: 4.5,
  jitaTotal: 2250,
  importCost: 6000,
  jitaWithImport: 8250,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: 'jita',
  bestPrice: 8250,
  savings: null,
  purchasedQuantity: 0,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

function mountStockAnalysis(initialSteps: IndustryProjectStep[]) {
  const steps = ref<IndustryProjectStep[]>(initialSteps.map(s => ({ ...s })))
  const toggleStepInStock = vi.fn(async (_projectId: string, stepId: string, inStockQuantity: number) => {
    steps.value = steps.value.map(s => (s.id === stepId ? { ...s, inStockQuantity, inStock: inStockQuantity >= s.quantity } : s))
  })
  const fetchProject = vi.fn(async () => {})
  const loadShoppingList = vi.fn(async () => {})
  let analysis!: ReturnType<typeof useShoppingStockAnalysis>
  const Host = defineComponent({
    setup() {
      analysis = useShoppingStockAnalysis(
        ref(PROJECT_ID),
        ref([RAW_MATERIAL_LINE]),
        ref(TREE),
        steps,
        toggleStepInStock,
        fetchProject,
        loadShoppingList,
      )
      return () => null
    },
  })
  mount(Host, { global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] } })

  async function paste(text: string) {
    analysis.pastedStock.value = text
    await analysis.analyzeStock()
    await flushPromises()
  }

  function inStockQuantityOf(stepId: string): number | undefined {
    return steps.value.find(s => s.id === stepId)?.inStockQuantity
  }

  return { analysis, toggleStepInStock, fetchProject, paste, inStockQuantityOf }
}

beforeEach(() => {
  localStorage.clear()
})

describe('useShoppingStockAnalysis', () => {
  it('sets only the intermediate step stock when an intermediate is pasted', async () => {
    const { analysis, toggleStepInStock, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${INTERMEDIATE_NAME}\t40`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, INTERMEDIATE_STEP.id, 40]])
    expect(analysis.parsedStock.value).toEqual([{ name: INTERMEDIATE_NAME, quantity: 40 }])
  })

  it('sends no update when the same intermediate stock is pasted twice', async () => {
    const { toggleStepInStock, paste, inStockQuantityOf } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])
    await paste(`${INTERMEDIATE_NAME}\t40`)
    toggleStepInStock.mockClear()

    await paste(`${INTERMEDIATE_NAME}\t40`)

    expect(toggleStepInStock.mock.calls).toEqual([])
    expect(inStockQuantityOf(INTERMEDIATE_STEP.id)).toBe(40)
  })

  it('replaces the intermediate step stock with a lower pasted quantity', async () => {
    const { toggleStepInStock, paste, inStockQuantityOf } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])
    await paste(`${INTERMEDIATE_NAME}\t40`)
    toggleStepInStock.mockClear()

    await paste(`${INTERMEDIATE_NAME}\t30`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, INTERMEDIATE_STEP.id, 30]])
    expect(inStockQuantityOf(INTERMEDIATE_STEP.id)).toBe(30)
  })

  it('fills the split group steps in split index order with the pasted intermediate quantity', async () => {
    const { toggleStepInStock, paste, inStockQuantityOf } = mountStockAnalysis([
      ROOT_STEP,
      SECOND_SPLIT_INTERMEDIATE_STEP,
      FIRST_SPLIT_INTERMEDIATE_STEP,
      CHILD_STEP,
    ])

    await paste(`${INTERMEDIATE_NAME}\t70`)

    expect(toggleStepInStock.mock.calls).toEqual([
      [PROJECT_ID, FIRST_SPLIT_INTERMEDIATE_STEP.id, 60],
      [PROJECT_ID, SECOND_SPLIT_INTERMEDIATE_STEP.id, 10],
    ])
    expect(inStockQuantityOf(FIRST_SPLIT_INTERMEDIATE_STEP.id)).toBe(60)
    expect(inStockQuantityOf(SECOND_SPLIT_INTERMEDIATE_STEP.id)).toBe(10)
  })

  it('caps the intermediate step stock at the step quantity without touching its materials', async () => {
    const { analysis, toggleStepInStock, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${INTERMEDIATE_NAME}\t150`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, INTERMEDIATE_STEP.id, INTERMEDIATE_QUANTITY]])
    expect(analysis.parsedStock.value).toEqual([{ name: INTERMEDIATE_NAME, quantity: 150 }])
  })

  it('sends no step update when the root product is pasted', async () => {
    const { analysis, toggleStepInStock, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${ROOT_NAME}\t1`)

    expect(toggleStepInStock.mock.calls).toEqual([])
    expect(analysis.parsedStock.value).toEqual([{ name: ROOT_NAME, quantity: 1 }])
  })

  it('reloads the project once after an intermediate step stock changes', async () => {
    const { fetchProject, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${INTERMEDIATE_NAME}\t40`)

    expect(fetchProject.mock.calls).toEqual([[PROJECT_ID]])
  })

  it('does not reload the project when the same intermediate stock is pasted again', async () => {
    const { fetchProject, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])
    await paste(`${INTERMEDIATE_NAME}\t40`)
    fetchProject.mockClear()

    await paste(`${INTERMEDIATE_NAME}\t40`)

    expect(fetchProject.mock.calls).toEqual([])
  })

  it('does not reload the project when the root product is pasted', async () => {
    const { fetchProject, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${ROOT_NAME}\t1`)

    expect(fetchProject.mock.calls).toEqual([])
  })

  it('lowers the split group steps when a smaller intermediate quantity is pasted', async () => {
    const { toggleStepInStock, paste } = mountStockAnalysis([
      ROOT_STEP,
      { ...FIRST_SPLIT_INTERMEDIATE_STEP, inStockQuantity: 60, inStock: true },
      { ...SECOND_SPLIT_INTERMEDIATE_STEP, inStockQuantity: 10 },
      CHILD_STEP,
    ])

    await paste(`${INTERMEDIATE_NAME}\t50`)

    expect(toggleStepInStock.mock.calls).toEqual([
      [PROJECT_ID, FIRST_SPLIT_INTERMEDIATE_STEP.id, 50],
      [PROJECT_ID, SECOND_SPLIT_INTERMEDIATE_STEP.id, 0],
    ])
  })

  it('updates only the split group step whose stock changes', async () => {
    const { toggleStepInStock, paste } = mountStockAnalysis([
      ROOT_STEP,
      { ...FIRST_SPLIT_INTERMEDIATE_STEP, inStockQuantity: 60, inStock: true },
      SECOND_SPLIT_INTERMEDIATE_STEP,
      CHILD_STEP,
    ])

    await paste(`${INTERMEDIATE_NAME}\t70`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, SECOND_SPLIT_INTERMEDIATE_STEP.id, 10]])
  })

  it('sends no step update for a deeper step producing the root product', async () => {
    const { toggleStepInStock, paste } = mountStockAnalysis([ROOT_STEP, ROOT_PRODUCT_SUBCOMPONENT_STEP, INTERMEDIATE_STEP])

    await paste(`${ROOT_NAME}\t2`)

    expect(toggleStepInStock.mock.calls).toEqual([])
  })

  it('ignores a copy step of the pasted intermediate', async () => {
    const { toggleStepInStock, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_COPY_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${INTERMEDIATE_NAME}\t40`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, INTERMEDIATE_STEP.id, 40]])
  })

  it('matches the pasted intermediate name regardless of case', async () => {
    const { toggleStepInStock, paste } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])

    await paste(`${INTERMEDIATE_NAME.toLowerCase()}\t40`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, INTERMEDIATE_STEP.id, 40]])
  })

  it('replaces the stock of an intermediate step already fully in stock', async () => {
    const { toggleStepInStock, paste, inStockQuantityOf } = mountStockAnalysis([ROOT_STEP, INTERMEDIATE_STEP, CHILD_STEP])
    await paste(`${INTERMEDIATE_NAME}\t${INTERMEDIATE_QUANTITY}`)
    toggleStepInStock.mockClear()

    await paste(`${INTERMEDIATE_NAME}\t30`)

    expect(toggleStepInStock.mock.calls).toEqual([[PROJECT_ID, INTERMEDIATE_STEP.id, 30]])
    expect(inStockQuantityOf(INTERMEDIATE_STEP.id)).toBe(30)
  })
})
