import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { COOLANT } from '../planetaryPlannerFixtures'
import {
  COOLANT_LINE_TEMPLATE,
  RING_TEMPLATE,
  TEMPLATES_ENDPOINT,
  accountWithFactories,
  patchedBodies,
  serveFollowEndpoints,
  type FollowScenario,
} from '../planetaryFollowHarness'
import { makeTemplate } from '../planetaryTemplatesFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SAVED_TEMPLATE = makeTemplate('tpl-new', 'My coolant line', [{ typeId: COOLANT, targetPerDay: 1_200 }], {}, undefined, '2026-11-26T09:00:00+00:00')

type Store = ReturnType<typeof usePlanetaryPlannerStore>

async function loadedStore(scenario: FollowScenario = {}): Promise<Store> {
  serveFollowEndpoints({ templates: [RING_TEMPLATE, COOLANT_LINE_TEMPLATE], account: accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 }), ...scenario })
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

function callsTo(endpoint: string, method: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === endpoint && ((call[1] as RequestInit | undefined)?.method ?? 'GET') === method).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryPlanner store following edge cases', () => {
  it('leaves the followed template followed when the player stops following another template', async () => {
    const store = await loadedStore()

    const unfollowed = await store.unfollowTemplate('tpl-coolant-line')

    expect(unfollowed).toBe(true)
    expect(patchedBodies()).toEqual([{ endpoint: '/planetary/templates/tpl-coolant-line', body: { followed: false } }])
    expect(followedById(store)).toEqual({ 'tpl-ring': true, 'tpl-coolant-line': false })
    expect(store.followedTemplate?.id).toBe('tpl-ring')
  })

  it('leaves no followed template, no target and no comparison when the followed template is deleted', async () => {
    const store = await loadedStore()

    const deleted = await store.deleteTemplate('tpl-ring')

    expect(deleted).toBe(true)
    expect(callsTo('/planetary/templates/tpl-ring', 'DELETE')).toBe(1)
    expect(store.templates.map(template => template.id)).toEqual(['tpl-coolant-line'])
    expect(store.followedTemplate).toBeNull()
    expect(store.followedTargetsOfPlan).toBeNull()
    expect(store.followedFactoryComparison).toEqual([])
  })

  it('Currently keeps the saved template in the list, not followed, with the previous one still followed when the follow fails after the save (Issue1406)', async () => {
    const store = await loadedStore({ saved: SAVED_TEMPLATE, patchError: new Error('server unavailable') })
    store.addProduction(COOLANT, 1_200)

    const followed = await store.followCurrentPlan('My coolant line')

    expect(followed).toBe(false)
    expect(store.templates.map(template => template.id)).toEqual(['tpl-new', 'tpl-ring', 'tpl-coolant-line'])
    expect(followedById(store)).toEqual({ 'tpl-new': false, 'tpl-ring': true, 'tpl-coolant-line': false })
  })
})

describe('planetaryPlanner store follow context requests', () => {
  it('requests the catalog, the account and the templates exactly once each', async () => {
    serveFollowEndpoints({ templates: [RING_TEMPLATE], account: accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 }) })
    const store = usePlanetaryPlannerStore()

    await store.loadFollowContext()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledTimes(3)
    expect(callsTo('/planetary/planner', 'GET')).toBe(1)
    expect(callsTo('/planetary/planner/account', 'GET')).toBe(1)
    expect(callsTo(TEMPLATES_ENDPOINT, 'GET')).toBe(1)
  })
})
