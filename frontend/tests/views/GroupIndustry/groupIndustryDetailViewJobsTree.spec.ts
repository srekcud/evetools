import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { HAMMERHEAD_I_ID, PRODUCTION_CHAIN } from '../../productionChainNodes'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  nyxProject,
  PROJECT_PATH,
  route,
  tabSelector,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FLOW = '[data-testid="chain-flow"]'
const TREE = '[data-testid="chain-tree"]'
const SELECTED_ROW = '[data-testid^="chain-row-"][aria-selected="true"]'

const LAUNCH_HAMMERHEAD = nextAction({ code: 'launch_job', tab: 'materials', bomLineId: HAMMERHEAD_I_ID, typeId: 1_001, typeName: 'Hammerhead I', quantity: 4 })
const AWAIT_JOBS = nextAction({ code: 'await_jobs', tab: 'materials', endsAt: '2026-11-23T10:30:00+00:00' })
const RECORD_SALE = nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })

async function mountWithChain(action: ReturnType<typeof nextAction>, status: 'in_progress' | 'selling' = 'in_progress'): Promise<VueWrapper> {
  const wrapper = await mountCockpitDetail({
    project: nyxProject(status),
    cockpit: cockpitOf(status, action),
  })
  const answerOthers = vi.mocked(apiRequest).getMockImplementation()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === `${PROJECT_PATH}/chain`) return { id: 'chain-nyx-batch', nodes: PRODUCTION_CHAIN }
    if (endpoint === `${PROJECT_PATH}/reactions`) return { id: 'reactions-nyx-batch', steps: [] }
    return answerOthers?.(endpoint, options)
  })
  return wrapper
}

function shownView(wrapper: VueWrapper): 'flow' | 'tree' | 'none' {
  if (wrapper.find(TREE).exists()) return 'tree'
  return wrapper.find(FLOW).exists() ? 'flow' : 'none'
}

function selectedRows(wrapper: VueWrapper): (string | undefined)[] {
  return wrapper.findAll(SELECTED_ROW).map(selected => selected.attributes('data-testid'))
}

async function click(wrapper: VueWrapper, selector: string): Promise<void> {
  await wrapper.get(selector).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
  Element.prototype.scrollIntoView = vi.fn()
})

describe('project cockpit View jobs action', () => {
  it('opens the job tree on the step the launch action targets', async () => {
    const wrapper = await mountWithChain(LAUNCH_HAMMERHEAD)

    await click(wrapper, NEXT_ACTION_BUTTON)

    expect({
      chainTabSelected: wrapper.get(tabSelector('chain')).attributes('aria-selected'),
      view: shownView(wrapper),
      selected: selectedRows(wrapper),
    }).toEqual({ chainTabSelected: 'true', view: 'tree', selected: [`chain-row-${HAMMERHEAD_I_ID}`] })
  })

  it('opens the job tree without a selected step when the action waits on jobs and targets no step', async () => {
    const wrapper = await mountWithChain(AWAIT_JOBS)

    await click(wrapper, NEXT_ACTION_BUTTON)

    expect({ view: shownView(wrapper), selected: selectedRows(wrapper) }).toEqual({ view: 'tree', selected: [] })
  })

  it('does not open the targeted step again when the player comes back to the production tab later', async () => {
    const wrapper = await mountWithChain(LAUNCH_HAMMERHEAD)
    await click(wrapper, NEXT_ACTION_BUTTON)
    await click(wrapper, '[data-testid="chain-view-flow"]')

    await click(wrapper, tabSelector('sales'))
    await click(wrapper, tabSelector('chain'))

    expect({ view: shownView(wrapper), selected: selectedRows(wrapper) }).toEqual({ view: 'flow', selected: [] })
  })

  it('leaves the production overview alone for an action that points to another tab', async () => {
    const wrapper = await mountWithChain(RECORD_SALE, 'selling')
    await click(wrapper, NEXT_ACTION_BUTTON)

    await click(wrapper, tabSelector('chain'))

    expect(shownView(wrapper)).toBe('flow')
  })
})
