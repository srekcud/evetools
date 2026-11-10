import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import { apiRequest } from '@/services/api'
import { useIndustryStore } from '@/stores/industry'
import type { IndustryAction } from '@/stores/industry/actions'
import type { StockpileItemStatus, StockpileStage, StockpileStatus } from '@/stores/industry/types'
import { industryAction } from '../../industryActionFixtures'

const routerPush = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ push: routerPush, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ACTIONS_ENDPOINT = '/industry/actions'
const SLOTS_ENDPOINT = '/industry/slots'
const PROJECTS_ENDPOINT = '/industry/projects'
const WORKSHOP_PROJECTS_ENDPOINT = '/industry/workshop-projects'
const STOCKPILE_STATUS_ENDPOINT = '/industry/stockpile-status'

const FUEL_BLOCK_TYPE_ID = 4312
const HOBGOBLIN_TYPE_ID = 2454
const TRITANIUM_TYPE_ID = 34
const HECATE_PROJECT_ID = '5d3c8b21-6a47-4e90-b1f2-8c9d0e7a4b63'

const QUEUE: IndustryAction[] = [
  industryAction({ projectId: HECATE_PROJECT_ID, projectName: 'Hecate in progress', code: 'source_material', tab: 'materials', typeId: 34, typeName: 'Tritanium', quantity: 147 }),
]

function stockpileItem(overrides: Partial<StockpileItemStatus> & Pick<StockpileItemStatus, 'typeId' | 'typeName'>): StockpileItemStatus {
  return {
    id: `target-${overrides.typeId}`,
    targetQuantity: 100,
    remainingTargetQuantity: 100,
    stock: 100,
    percent: 100,
    status: 'met',
    unitPrice: null,
    stockValue: null,
    deficit: 0,
    deficitCost: null,
    sourceProductTypeId: null,
    locations: [],
    suggestedProjectRuns: null,
    ...overrides,
  }
}

function stockpileStatus(items: Partial<Record<StockpileStage, StockpileItemStatus[]>>): StockpileStatus {
  const emptyStage = { items: [], totalValue: null, healthPercent: 100 }
  return {
    targetCount: Object.values(items).flat().length,
    stages: {
      raw_material: { ...emptyStage, items: items.raw_material ?? [] },
      intermediate: { ...emptyStage, items: items.intermediate ?? [] },
      component: { ...emptyStage, items: items.component ?? [] },
      final_product: { ...emptyStage, items: items.final_product ?? [] },
    },
    kpis: { pipelineHealth: 100, totalInvested: null, bottleneck: null, estOutput: { ready: 0, total: 0, readyNames: [] } },
    shoppingList: [],
    importsWithoutPlan: [],
    failedProducts: [],
    missingPriceTypeIds: [],
  }
}

const HOBGOBLIN_LOW = stockpileItem({
  typeId: HOBGOBLIN_TYPE_ID,
  typeName: 'Hobgoblin II',
  remainingTargetQuantity: 100,
  stock: 20,
  percent: 20,
  status: 'critical',
  deficit: 80,
  suggestedProjectRuns: 80,
})
const FUEL_BLOCK_LOW = stockpileItem({
  typeId: FUEL_BLOCK_TYPE_ID,
  typeName: 'Nitrogen Fuel Block',
  remainingTargetQuantity: 2000,
  stock: 100,
  percent: 5,
  status: 'critical',
  deficit: 1900,
  suggestedProjectRuns: 48,
})
const TRITANIUM_LOW_WITHOUT_RECIPE = stockpileItem({
  typeId: TRITANIUM_TYPE_ID,
  typeName: 'Tritanium',
  remainingTargetQuantity: 1000,
  stock: 0,
  percent: 0,
  status: 'critical',
  deficit: 1000,
  suggestedProjectRuns: null,
})
const SCORPION_STOCKED = stockpileItem({ typeId: 640, typeName: 'Scorpion' })

interface Answers {
  actions?: IndustryAction[]
  stockpile?: StockpileStatus | Error
}

function answerWith({ actions = QUEUE, stockpile = stockpileStatus({}) }: Answers): void {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === ACTIONS_ENDPOINT) return actions
    if (endpoint === SLOTS_ENDPOINT) return { characters: [], timeline: [] }
    if (endpoint === PROJECTS_ENDPOINT) return []
    if (endpoint === WORKSHOP_PROJECTS_ENDPOINT) return []
    if (endpoint === STOCKPILE_STATUS_ENDPOINT) {
      if (stockpile instanceof Error) throw stockpile
      return stockpile
    }
    return new Promise(() => {})
  }) as never)
}

async function mountWorkshop(answers: Answers = {}): Promise<VueWrapper> {
  answerWith(answers)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustryWorkshop, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function proposalRows(wrapper: VueWrapper) {
  return wrapper.get('[data-testid="workshop-action-queue"]').findAll('[data-testid="workshop-stockpile-row"]')
}

function projectActionRows(wrapper: VueWrapper) {
  return wrapper.get('[data-testid="workshop-action-queue"]').findAll('[data-testid="workshop-action-row"]')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  routerPush.mockClear()
  vi.mocked(apiRequest).mockReset()
})

describe('IndustryWorkshop low Stockpile target proposals', () => {
  it('proposes a project for each target that carries a suggested run count, lowest stock first, and none for stocked or unproducible targets', async () => {
    const wrapper = await mountWorkshop({
      stockpile: stockpileStatus({
        final_product: [HOBGOBLIN_LOW, SCORPION_STOCKED],
        component: [FUEL_BLOCK_LOW],
        raw_material: [TRITANIUM_LOW_WITHOUT_RECIPE],
      }),
    })
    const rows = proposalRows(wrapper)

    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Nitrogen Fuel Block')
    expect(rows[0].text()).toContain('48')
    expect(rows[1].text()).toContain('Hobgoblin II')
    expect(rows[1].text()).toContain('80')
    expect(rows[1].text()).toContain('20')
    expect(rows[1].text()).toContain('100')
    expect(proposalRows(wrapper).map(row => row.text()).join(' ')).not.toContain('Tritanium')
    expect(proposalRows(wrapper).map(row => row.text()).join(' ')).not.toContain('Scorpion')
  })

  it('opens the project creation pre-filled with the target type and the suggested runs', async () => {
    const wrapper = await mountWorkshop({ stockpile: stockpileStatus({ final_product: [HOBGOBLIN_LOW] }) })

    await proposalRows(wrapper)[0].get('[data-testid="workshop-stockpile-button"]').trigger('click')

    expect(useIndustryStore().navigationIntent).toEqual({
      target: 'projects',
      prefill: { typeId: HOBGOBLIN_TYPE_ID, typeName: 'Hobgoblin II', runs: 80 },
    })
  })

  it('counts the proposals with the project actions and keeps the project action rows untouched', async () => {
    const wrapper = await mountWorkshop({ stockpile: stockpileStatus({ final_product: [HOBGOBLIN_LOW] }) })

    expect(projectActionRows(wrapper)).toHaveLength(1)
    expect(wrapper.get('[data-testid="workshop-action-count"]').text()).toMatch(/\b2\b/)
  })

  it('does not say nothing is left to do when only a Stockpile proposal is pending', async () => {
    const wrapper = await mountWorkshop({ actions: [], stockpile: stockpileStatus({ final_product: [HOBGOBLIN_LOW] }) })

    expect(wrapper.find('[data-testid="workshop-empty-queue"]').exists()).toBe(false)
    expect(proposalRows(wrapper)).toHaveLength(1)
  })

  it('keeps the empty queue state when neither a project action nor a proposal is pending', async () => {
    const wrapper = await mountWorkshop({ actions: [], stockpile: stockpileStatus({ final_product: [SCORPION_STOCKED] }) })

    expect(wrapper.find('[data-testid="workshop-empty-queue"]').exists()).toBe(true)
    expect(proposalRows(wrapper)).toHaveLength(0)
  })

  it('shows an explicit error when the Stockpile status cannot be loaded instead of an empty proposal list', async () => {
    const wrapper = await mountWorkshop({ stockpile: new Error('Stockpile unavailable') })

    expect(wrapper.get('[data-testid="workshop-stockpile-error"]').text()).toContain('Stockpile unavailable')
    expect(proposalRows(wrapper)).toHaveLength(0)
    expect(projectActionRows(wrapper)).toHaveLength(1)
  })
})
