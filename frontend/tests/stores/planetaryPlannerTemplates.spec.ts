import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { PLANNER_ACCOUNT, PLANNER_MARKET, RANKING_INPUT_A, RANKING_PRODUCT_B } from '../planetaryPlannerFixtures'
import {
  DEFAULT_TEMPLATE_SETTINGS,
  TEMPLATE_B_AND_M,
  TEMPLATE_B_LINE,
  TEMPLATE_CATALOG,
  TEMPLATE_PRODUCT_M,
  TEMPLATE_VANISHED,
  makeTemplate,
} from '../planetaryTemplatesFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const READ_ERROR_KEY = 'pi.planner.errors.templatesRead'
const SAVE_ERROR_KEY = 'pi.planner.errors.templateSave'
const DELETE_ERROR_KEY = 'pi.planner.errors.templateDelete'
const TEMPLATES_ENDPOINT = '/planetary/templates'
const AQUEOUS_LIKE_RESOURCE = 91_001

const SAVED_TEMPLATE: PlanTemplate = makeTemplate(
  'tpl-new',
  'Coolant mix',
  [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }],
  { [RANKING_INPUT_A]: 'buy', [TEMPLATE_PRODUCT_M]: 'produce' },
  { priceSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 },
  '2026-11-26T09:00:00+00:00',
)

type Store = ReturnType<typeof usePlanetaryPlannerStore>

interface Scenario {
  templates?: PlanTemplate[] | Error
  save?: PlanTemplate | Error
  remove?: Error
}

function serve(scenario: Scenario = {}) {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === TEMPLATES_ENDPOINT && method === 'GET') {
      if (scenario.templates instanceof Error) throw scenario.templates
      return scenario.templates === undefined ? [] : scenario.templates
    }
    if (endpoint === TEMPLATES_ENDPOINT && method === 'POST') {
      if (scenario.save instanceof Error) throw scenario.save
      return scenario.save
    }
    if (endpoint.startsWith(`${TEMPLATES_ENDPOINT}/`) && method === 'DELETE') {
      if (scenario.remove) throw scenario.remove
      return null
    }
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
    return { id: 'planner', ...TEMPLATE_CATALOG }
  }) as never)
}

async function loadedStore(scenario: Scenario = {}, withCatalog = true): Promise<Store> {
  serve(scenario)
  const store = usePlanetaryPlannerStore()
  if (withCatalog) await store.fetchCatalog()
  await store.fetchTemplates()
  vi.mocked(apiRequest).mockClear()
  return store
}

function planOf(store: Store) {
  return JSON.parse(JSON.stringify({
    productions: store.productions,
    choices: store.choices,
    settings: store.settings,
    selectedIndex: store.selectedIndex,
    extractionYieldOverrides: store.extractionYieldOverrides,
  }))
}

function templateCalls(method: string) {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0].startsWith(TEMPLATES_ENDPOINT) && ((call[1] as RequestInit | undefined)?.method ?? 'GET') === method)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store templates', () => {
  it('reads the templates endpoint once and keeps the list in the order of the response', async () => {
    serve({ templates: [TEMPLATE_B_AND_M, TEMPLATE_B_LINE] })
    const store = usePlanetaryPlannerStore()

    await store.fetchTemplates()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual([TEMPLATES_ENDPOINT])
    expect(store.templates.map(template => template.id)).toEqual(['tpl-b-and-m', 'tpl-b-line'])
    expect(store.templates[0]).toEqual(TEMPLATE_B_AND_M)
    expect(store.error).toBeNull()
  })

  it('reports an explicit error with its translation when the read fails, keeping the plan untouched', async () => {
    const store = await loadedStore()
    store.addProduction(RANKING_PRODUCT_B, 120)
    const before = planOf(store)
    serve({ templates: new Error('templates unavailable') })

    await store.fetchTemplates()

    expect(store.error).toBe(i18n.global.t(READ_ERROR_KEY))
    expect(store.error).not.toBe(READ_ERROR_KEY)
    expect(store.templates).toEqual([])
    expect(planOf(store)).toEqual(before)
  })

  it('posts the name, the productions, the explicit choices and the settings of the current plan and puts the answer first in the list', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_LINE], save: SAVED_TEMPLATE })
    store.addProduction(RANKING_PRODUCT_B, 120)
    store.addProduction(TEMPLATE_PRODUCT_M, 18)
    store.setChoice(RANKING_INPUT_A, 'buy')
    store.setChoice(TEMPLATE_PRODUCT_M, 'produce')
    store.setPriceSource('jita-buy')
    store.setTaxPercent(7.5)
    store.setDaysBetweenRounds(6)
    const before = planOf(store)

    const saved = await store.saveTemplate('Coolant mix')

    expect(saved).toBe(true)
    expect(templateCalls('POST')).toHaveLength(1)
    const [, options] = templateCalls('POST')[0]
    expect(JSON.parse(String((options as RequestInit).body))).toEqual({
      name: 'Coolant mix',
      productions: [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }],
      choices: { [RANKING_INPUT_A]: 'buy', [TEMPLATE_PRODUCT_M]: 'produce' },
      settings: { priceSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 },
    })
    expect(store.templates.map(template => template.id)).toEqual(['tpl-new', 'tpl-b-line'])
    expect(store.templates[0]).toEqual(SAVED_TEMPLATE)
    expect(planOf(store)).toEqual(before)
  })

  it('refuses to save a plan without production, a blank name or a name of 101 characters, and sends nothing', async () => {
    const store = await loadedStore({ save: SAVED_TEMPLATE })

    expect(await store.saveTemplate('Empty plan')).toBe(false)

    store.addProduction(RANKING_PRODUCT_B, 120)
    for (const name of ['', '   ', 'a'.repeat(101)]) {
      expect(await store.saveTemplate(name)).toBe(false)
    }
    expect(templateCalls('POST')).toHaveLength(0)
    expect(store.templates).toEqual([])
  })

  it('saves a name of exactly 100 characters', async () => {
    const store = await loadedStore({ save: SAVED_TEMPLATE })
    store.addProduction(RANKING_PRODUCT_B, 120)

    expect(await store.saveTemplate('a'.repeat(100))).toBe(true)

    expect(JSON.parse(String((templateCalls('POST')[0][1] as RequestInit).body)).name).toBe('a'.repeat(100))
  })

  it('reports an explicit error when the save fails and changes neither the list nor the plan', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_LINE], save: new Error('limit reached') })
    store.addProduction(RANKING_PRODUCT_B, 120)
    store.setChoice(RANKING_INPUT_A, 'buy')
    const before = planOf(store)

    const saved = await store.saveTemplate('Coolant mix')

    expect(saved).toBe(false)
    expect(store.error).toBe(i18n.global.t(SAVE_ERROR_KEY))
    expect(store.error).not.toBe(SAVE_ERROR_KEY)
    expect(store.templates.map(template => template.id)).toEqual(['tpl-b-line'])
    expect(planOf(store)).toEqual(before)
  })

  it('deletes a template through its own endpoint and keeps the others', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M, TEMPLATE_B_LINE] })

    const deleted = await store.deleteTemplate('tpl-b-and-m')

    expect(deleted).toBe(true)
    expect(templateCalls('DELETE').map(call => call[0])).toEqual([`${TEMPLATES_ENDPOINT}/tpl-b-and-m`])
    expect(store.templates.map(template => template.id)).toEqual(['tpl-b-line'])
  })

  it('reports an explicit error when the deletion fails and keeps the whole list and the plan', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M, TEMPLATE_B_LINE], remove: new Error('template already gone') })
    store.addProduction(RANKING_PRODUCT_B, 120)
    const before = planOf(store)

    const deleted = await store.deleteTemplate('tpl-b-and-m')

    expect(deleted).toBe(false)
    expect(store.error).toBe(i18n.global.t(DELETE_ERROR_KEY))
    expect(store.error).not.toBe(DELETE_ERROR_KEY)
    expect(store.templates.map(template => template.id)).toEqual(['tpl-b-and-m', 'tpl-b-line'])
    expect(planOf(store)).toEqual(before)
  })

  it('loads a template by replacing the productions, the explicit choices and the settings, selecting the first production and keeping the typed extraction yields', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    store.addProduction(TEMPLATE_PRODUCT_M, 6)
    store.setChoice(RANKING_INPUT_A, 'buy')
    store.setTaxPercent(9)
    store.setExtractionYield(AQUEOUS_LIKE_RESOURCE, 4_000)
    expect(store.selectedIndex).toBe(1)

    store.loadTemplateIntoPlan('tpl-b-and-m')

    expect(planOf(store)).toEqual({
      productions: [{ typeId: RANKING_PRODUCT_B, targetPerDay: 60 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }],
      choices: { [RANKING_PRODUCT_B]: 'produce', [TEMPLATE_PRODUCT_M]: 'buy' },
      settings: { priceSource: 'jita-buy', taxPercent: 7.5, daysBetweenRounds: 6 },
      selectedIndex: 0,
      extractionYieldOverrides: { [AQUEOUS_LIKE_RESOURCE]: 4_000 },
    })
  })

  it('leaves the saved template untouched when the player edits the loaded plan', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M] })

    store.loadTemplateIntoPlan('tpl-b-and-m')
    store.setTarget(0, 999)
    store.setTaxPercent(50)
    store.setChoice(TEMPLATE_PRODUCT_M, 'produce')

    expect(store.templates[0]).toEqual(TEMPLATE_B_AND_M)
    expect(TEMPLATE_B_AND_M.productions[0].targetPerDay).toBe(60)
  })

  it('adds the productions of a template after those of the plan, completes the missing choices only and keeps the settings of the plan', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M] })
    store.addProduction(RANKING_PRODUCT_B, 120)
    store.setChoice(RANKING_PRODUCT_B, 'buy')

    store.addTemplateToPlan('tpl-b-and-m')

    expect(store.productions).toEqual([
      { typeId: RANKING_PRODUCT_B, targetPerDay: 120 },
      { typeId: RANKING_PRODUCT_B, targetPerDay: 60 },
      { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 },
    ])
    expect(store.choices).toEqual({ [RANKING_PRODUCT_B]: 'buy', [TEMPLATE_PRODUCT_M]: 'buy' })
    expect(store.settings).toEqual(DEFAULT_TEMPLATE_SETTINGS)
    expect(store.templates[0]).toEqual(TEMPLATE_B_AND_M)
  })

  it('behaves as a load when the plan is empty: both productions, the choices of the template and the first production selected', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M] })

    store.addTemplateToPlan('tpl-b-and-m')

    expect(store.productions).toEqual([{ typeId: RANKING_PRODUCT_B, targetPerDay: 60 }, { typeId: TEMPLATE_PRODUCT_M, targetPerDay: 18 }])
    expect(store.choices).toEqual({ [RANKING_PRODUCT_B]: 'produce', [TEMPLATE_PRODUCT_M]: 'buy' })
    expect(store.selectedIndex).toBe(0)
  })

  it('does nothing without a loaded catalog', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_B_AND_M] }, false)
    const before = planOf(store)

    store.loadTemplateIntoPlan('tpl-b-and-m')
    store.addTemplateToPlan('tpl-b-and-m')

    expect(store.catalog).toBeNull()
    expect(planOf(store)).toEqual(before)
    expect(before.selectedIndex).toBeNull()
  })

  it('does nothing for a template whose product left the catalog or for an unknown id', async () => {
    const store = await loadedStore({ templates: [TEMPLATE_VANISHED, TEMPLATE_B_LINE] })
    store.addProduction(TEMPLATE_PRODUCT_M, 18)
    store.setChoice(RANKING_INPUT_A, 'buy')
    const before = planOf(store)

    store.loadTemplateIntoPlan('tpl-vanished')
    store.addTemplateToPlan('tpl-vanished')
    store.loadTemplateIntoPlan('tpl-unknown')
    store.addTemplateToPlan('tpl-unknown')

    expect(planOf(store)).toEqual(before)
    expect(store.plan).not.toBeNull()
  })
})
