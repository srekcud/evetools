import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { PLANNER_ACCOUNT, PLANNER_MARKET, RANKING_PRODUCT_B } from '../planetaryPlannerFixtures'
import { TEMPLATE_B_AND_M, TEMPLATE_CATALOG, TEMPLATE_PRODUCT_M, makeTemplate } from '../planetaryTemplatesFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TEMPLATES_ENDPOINT = '/planetary/templates'
const SAVED_TEMPLATE: PlanTemplate = makeTemplate('tpl-new', 'Coolant mix', [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }])

async function storeWithPlan(templates: PlanTemplate[] = []) {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === TEMPLATES_ENDPOINT && method === 'GET') return templates
    if (endpoint === TEMPLATES_ENDPOINT && method === 'POST') return SAVED_TEMPLATE
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
    return { id: 'planner', ...TEMPLATE_CATALOG }
  }) as never)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  await store.fetchTemplates()
  store.addProduction(RANKING_PRODUCT_B, 120)
  vi.mocked(apiRequest).mockClear()
  return store
}

function postedNames(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => call[0] === TEMPLATES_ENDPOINT && (call[1] as RequestInit | undefined)?.method === 'POST')
    .map(call => JSON.parse(String((call[1] as RequestInit).body)).name)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store template name', () => {
  it('sends the name without the spaces around it: "  X  " is posted as "X"', async () => {
    const store = await storeWithPlan()

    expect(await store.saveTemplate('  X  ')).toBe(true)

    expect(postedNames()).toEqual(['X'])
  })

  it('accepts a name of 100 characters wrapped in spaces and sends the 100 characters alone', async () => {
    const store = await storeWithPlan()

    expect(await store.saveTemplate(`  ${'a'.repeat(100)}  `)).toBe(true)

    expect(postedNames()).toEqual(['a'.repeat(100)])
  })

  it('refuses a name of 101 characters wrapped in spaces and sends nothing', async () => {
    const store = await storeWithPlan()

    expect(await store.saveTemplate(`  ${'a'.repeat(101)}  `)).toBe(false)

    expect(postedNames()).toEqual([])
    expect(store.templates).toEqual([])
  })
})

describe('planetaryPlanner store template and plan edits', () => {
  it('leaves the saved template intact when the targets of the plan are edited after adding it to a plan that already holds B', async () => {
    const store = await storeWithPlan([TEMPLATE_B_AND_M])

    store.addTemplateToPlan('tpl-b-and-m')
    expect(store.setTarget(1, 999)).toBe(true)
    expect(store.setTarget(2, 777)).toBe(true)

    expect(store.productions).toEqual([
      { typeId: RANKING_PRODUCT_B, targetPerDay: 120 },
      { typeId: RANKING_PRODUCT_B, targetPerDay: 999 },
      { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 777 },
    ])
    expect(store.templates[0]).toEqual(TEMPLATE_B_AND_M)
    expect(TEMPLATE_B_AND_M.productions).toEqual([{ typeId: RANKING_PRODUCT_B, targetPerDay: 60 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }])
  })
})
