import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { BROADCAST_NODE, COOLANT, PLANNER_CATALOG, TOXIC_METALS, WATER } from '../planetaryPlannerFixtures'
import {
  COOLANT_LINE_TEMPLATE,
  RING_TEMPLATE,
  TEMPLATES_ENDPOINT,
  accountWithFactories,
  patchedBodies,
  serveFollowEndpoints,
  type FollowScenario,
} from '../planetaryFollowHarness'
import { TEMPLATE_VANISHED, makeTemplate } from '../planetaryTemplatesFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FOLLOW_ERROR_KEY = 'pi.planner.errors.templateFollow'
const SAVE_ERROR_KEY = 'pi.planner.errors.templateSave'
const ACCOUNT_FACTORIES = { P1: 20, P2: 9, P3: 3, P4: 2 }

const SAVED_TEMPLATE = makeTemplate('tpl-new', 'My coolant line', [{ typeId: COOLANT, targetPerDay: 1_200 }], {}, undefined, '2026-11-26T09:00:00+00:00')

type Store = ReturnType<typeof usePlanetaryPlannerStore>

async function loadedStore(scenario: FollowScenario = {}): Promise<Store> {
  serveFollowEndpoints({ templates: [RING_TEMPLATE, COOLANT_LINE_TEMPLATE], account: accountWithFactories(ACCOUNT_FACTORIES), ...scenario })
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  await store.fetchAccount()
  await store.fetchTemplates()
  vi.mocked(apiRequest).mockClear()
  return store
}

function followedById(store: Store): Record<string, boolean> {
  return Object.fromEntries(store.templates.map(template => [template.id, template.followed]))
}

function postedNames(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => call[0] === TEMPLATES_ENDPOINT && (call[1] as RequestInit | undefined)?.method === 'POST')
    .map(call => JSON.parse(String((call[1] as RequestInit).body)).name)
}

function requestsInOrder(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(call => `${(call[1] as RequestInit | undefined)?.method ?? 'GET'} ${call[0]}`)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
  i18n.global.locale.value = 'en'
})

describe('planetaryPlanner store following', () => {
  it('follows a template with a patch of followed true on its own endpoint and stops following the one followed before', async () => {
    const store = await loadedStore()

    const followed = await store.followTemplate('tpl-coolant-line')

    expect(followed).toBe(true)
    expect(patchedBodies()).toEqual([{ endpoint: '/planetary/templates/tpl-coolant-line', body: { followed: true } }])
    expect(followedById(store)).toEqual({ 'tpl-ring': false, 'tpl-coolant-line': true })
    expect(store.followedTemplate?.id).toBe('tpl-coolant-line')
  })

  it('stops following a template with a patch of followed false and leaves no followed template', async () => {
    const store = await loadedStore()

    const unfollowed = await store.unfollowTemplate('tpl-ring')

    expect(unfollowed).toBe(true)
    expect(patchedBodies()).toEqual([{ endpoint: '/planetary/templates/tpl-ring', body: { followed: false } }])
    expect(followedById(store)).toEqual({ 'tpl-ring': false, 'tpl-coolant-line': false })
    expect(store.followedTemplate).toBeNull()
  })

  it('keeps the whole list and reports an explicit translated error when the patch fails', async () => {
    const store = await loadedStore({ patchError: new Error('template already gone') })

    const followed = await store.followTemplate('tpl-coolant-line')

    expect(followed).toBe(false)
    expect(followedById(store)).toEqual({ 'tpl-ring': true, 'tpl-coolant-line': false })
    expect(store.followedTemplate?.id).toBe('tpl-ring')
    expect(store.error).toBe(i18n.global.t(FOLLOW_ERROR_KEY))
    expect(store.error).not.toBe(FOLLOW_ERROR_KEY)
  })

  it('saves the current plan under the given name then follows the saved template, which comes first and alone followed', async () => {
    const store = await loadedStore({ saved: SAVED_TEMPLATE })
    store.addProduction(COOLANT, 1_200)

    const followed = await store.followCurrentPlan('My coolant line')

    expect(followed).toBe(true)
    expect(requestsInOrder()).toEqual([`POST ${TEMPLATES_ENDPOINT}`, `PATCH ${TEMPLATES_ENDPOINT}/tpl-new`])
    expect(postedNames()).toEqual(['My coolant line'])
    expect(patchedBodies()).toEqual([{ endpoint: '/planetary/templates/tpl-new', body: { followed: true } }])
    expect(store.templates.map(template => template.id)).toEqual(['tpl-new', 'tpl-ring', 'tpl-coolant-line'])
    expect(followedById(store)).toEqual({ 'tpl-new': true, 'tpl-ring': false, 'tpl-coolant-line': false })
  })

  it('names the plan Plan du 26/11/2026 in French and Plan of 26/11/2026 in English when the name is empty or blank, padding 5 January 2027 as 05/01/2027', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 10, 26, 10, 30))
    const store = await loadedStore({ saved: SAVED_TEMPLATE })
    store.addProduction(COOLANT, 1_200)

    i18n.global.locale.value = 'fr'
    await store.followCurrentPlan()
    i18n.global.locale.value = 'en'
    await store.followCurrentPlan('   ')
    vi.setSystemTime(new Date(2027, 0, 5, 8, 0))
    await store.followCurrentPlan()

    expect(postedNames()).toEqual(['Plan du 26/11/2026', 'Plan of 26/11/2026', 'Plan of 05/01/2027'])
  })

  it('sends nothing and follows nothing when the current plan has no production', async () => {
    const store = await loadedStore({ saved: SAVED_TEMPLATE })

    const followed = await store.followCurrentPlan('Empty plan')

    expect(followed).toBe(false)
    expect(requestsInOrder()).toEqual([])
    expect(followedById(store)).toEqual({ 'tpl-ring': true, 'tpl-coolant-line': false })
  })

  it('does not follow anything and reports the save error when the plan cannot be saved', async () => {
    const store = await loadedStore({ saveError: new Error('limit reached') })
    store.addProduction(COOLANT, 1_200)

    const followed = await store.followCurrentPlan('My coolant line')

    expect(followed).toBe(false)
    expect(patchedBodies()).toEqual([])
    expect(store.error).toBe(i18n.global.t(SAVE_ERROR_KEY))
    expect(followedById(store)).toEqual({ 'tpl-ring': true, 'tpl-coolant-line': false })
  })

  it('keeps the previously followed template followed and reports the follow error when the plan is saved but cannot be followed', async () => {
    const store = await loadedStore({ saved: SAVED_TEMPLATE, patchError: new Error('server unavailable') })
    store.addProduction(COOLANT, 1_200)

    const followed = await store.followCurrentPlan('My coolant line')

    expect(followed).toBe(false)
    expect(store.error).toBe(i18n.global.t(FOLLOW_ERROR_KEY))
    expect(store.followedTemplate?.id).toBe('tpl-ring')
  })
})

describe('planetaryPlanner store follow context', () => {
  it('loads the catalog, the account and the templates, and nothing else, without the planner being opened', async () => {
    serveFollowEndpoints({ templates: [RING_TEMPLATE, COOLANT_LINE_TEMPLATE], account: accountWithFactories(ACCOUNT_FACTORIES) })
    const store = usePlanetaryPlannerStore()

    await store.loadFollowContext()

    expect([...vi.mocked(apiRequest).mock.calls.map(call => call[0])].sort()).toEqual(['/planetary/planner', '/planetary/planner/account', TEMPLATES_ENDPOINT])
    expect(store.catalog?.recipes).toHaveLength(PLANNER_CATALOG.recipes.length)
    expect(store.account?.factories).toEqual(ACCOUNT_FACTORIES)
    expect(store.templates.map(template => template.id)).toEqual(['tpl-ring', 'tpl-coolant-line'])
    expect(store.followedTemplate?.id).toBe('tpl-ring')
  })

  it('resolves and still loads the other two when the account cannot be read, with the explicit account error', async () => {
    serveFollowEndpoints({ templates: [RING_TEMPLATE], account: new Error('account unavailable') })
    const store = usePlanetaryPlannerStore()

    await store.loadFollowContext()

    expect(store.catalog).not.toBeNull()
    expect(store.account).toBeNull()
    expect(store.templates.map(template => template.id)).toEqual(['tpl-ring'])
    expect(store.error).toBe(i18n.global.t('pi.planner.errors.account'))
  })
})

describe('planetaryPlanner store follow comparisons', () => {
  it('compares the current plan with the colonies: 18 P1 against 20 free 2, 12 P2 against 9 missing 3, 3 P3 and 2 P4 ok', async () => {
    const store = await loadedStore()
    store.addProduction(BROADCAST_NODE, 36)
    store.setChoice(WATER, 'produce')
    store.setChoice(TOXIC_METALS, 'produce')

    expect(store.factoryComparison).toEqual([
      { tier: 'P1', planned: 18, real: 20, gap: 2, state: 'free' },
      { tier: 'P2', planned: 12, real: 9, gap: -3, state: 'missing' },
      { tier: 'P3', planned: 3, real: 3, gap: 0, state: 'ok' },
      { tier: 'P4', planned: 2, real: 2, gap: 0, state: 'ok' },
    ])
  })

  it('compares the followed template, not the current plan, with the colonies: 10 P2 against 9 missing 1 and the colonies of the other tiers free', async () => {
    const store = await loadedStore({ templates: [{ ...RING_TEMPLATE, followed: false }, { ...COOLANT_LINE_TEMPLATE, followed: true }] })
    store.addProduction(BROADCAST_NODE, 36)

    expect(store.followedFactoryComparison).toEqual([
      { tier: 'P1', planned: 0, real: 20, gap: 20, state: 'free' },
      { tier: 'P2', planned: 10, real: 9, gap: -1, state: 'missing' },
      { tier: 'P3', planned: 0, real: 3, gap: 3, state: 'free' },
      { tier: 'P4', planned: 0, real: 2, gap: 2, state: 'free' },
    ])
  })

  it('has unknown states on every tier while the account is not loaded, and the targets of the followed template meanwhile: 32,400,000 of sales and 29,901,312 of profit a day', async () => {
    const store = await loadedStore({ account: new Error('account unavailable') })

    expect(store.followedFactoryComparison.map(gap => [gap.tier, gap.planned, gap.real, gap.gap, gap.state])).toEqual([
      ['P1', 18, null, null, 'unknown'],
      ['P2', 12, null, null, 'unknown'],
      ['P3', 3, null, null, 'unknown'],
      ['P4', 2, null, null, 'unknown'],
    ])
    expect(store.followedTargetsOfPlan?.factoriesByTier).toEqual({ P1: 18, P2: 12, P3: 3, P4: 2 })
    expect(store.followedTargetsOfPlan?.salesPerDay).toBe(32_400_000)
    expect(store.followedTargetsOfPlan?.profitPerDay).toBeCloseTo(29_901_312, 4)
    expect(store.followedTargetsOfPlan?.incomplete).toBe(false)
  })

  it('has no targets and no comparison without a followed template', async () => {
    const store = await loadedStore({ templates: [{ ...RING_TEMPLATE, followed: false }] })

    expect(store.followedTemplate).toBeNull()
    expect(store.followedTargetsOfPlan).toBeNull()
    expect(store.followedFactoryComparison).toEqual([])
  })

  it('keeps the followed template but has no targets and no comparison when its product left the catalog', async () => {
    const store = await loadedStore({ templates: [{ ...TEMPLATE_VANISHED, followed: true }] })

    expect(store.followedTemplate?.id).toBe('tpl-vanished')
    expect(store.followedTargetsOfPlan).toBeNull()
    expect(store.followedFactoryComparison).toEqual([])
  })
})
