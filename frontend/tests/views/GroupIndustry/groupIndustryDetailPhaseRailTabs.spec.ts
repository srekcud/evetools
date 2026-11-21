import { enableAutoUnmount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { GroupProjectStatus, ProjectPhase, ProjectPhaseKey } from '@/stores/group-industry/types'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  phase,
  route,
  supplyRail,
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

const RAIL_OPEN = (key: ProjectPhaseKey): string => `[data-testid="cockpit-rail-open-${key}"]`

const PHASE_TABS: Record<Exclude<ProjectPhaseKey, 'prepare'>, string> = {
  supply: 'bom',
  produce: 'chain',
  sell: 'sales',
  close: 'distribution',
}

function railWithCurrent(current: ProjectPhaseKey): ProjectPhase[] {
  const keys: ProjectPhaseKey[] = ['prepare', 'supply', 'produce', 'sell', 'close']
  const currentIndex = keys.indexOf(current)
  return keys.map((key, index) => phase(key, index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'upcoming', 0, 1))
}

async function mountOn(status: GroupProjectStatus, phases: ProjectPhase[], tab?: string): Promise<VueWrapper> {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject(status),
    cockpit: cockpitOf(status, nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 }), phases),
  })
}

function selectedTab(wrapper: VueWrapper): string | undefined {
  const selected = wrapper.findAll('[data-testid^="project-tab-"]').find(tab => tab.attributes('aria-selected') === 'true')
  return selected?.attributes('data-testid')?.replace('project-tab-', '')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project phase rail navigation', () => {
  it.each(Object.entries(PHASE_TABS))('opens the %s tab %s when its phase is clicked on the rail', async (key, tab) => {
    const wrapper = await mountOn('in_progress', supplyRail(), 'planning')

    await wrapper.get(RAIL_OPEN(key as ProjectPhaseKey)).trigger('click')

    expect({ selected: selectedTab(wrapper), tabSelected: wrapper.get(tabSelector(tab)).attributes('aria-selected') }).toEqual({ selected: tab, tabSelected: 'true' })
  })

  it('offers no link on the prepare phase, which has no tab', async () => {
    const wrapper = await mountOn('in_progress', supplyRail())

    expect(wrapper.find(RAIL_OPEN('prepare')).exists()).toBe(false)
  })

  it('keeps the phase cells readable by the existing test ids and states', async () => {
    const wrapper = await mountOn('in_progress', railWithCurrent('produce'))

    expect(wrapper.findAll('[data-testid^="cockpit-phase-"]').map(cell => cell.attributes('data-state'))).toEqual(['done', 'done', 'current', 'upcoming', 'upcoming'])
  })
})

describe('project opening tab', () => {
  it.each([
    ['supply', 'in_progress', 'bom'],
    ['produce', 'in_progress', 'chain'],
    ['sell', 'selling', 'sales'],
    ['close', 'selling', 'distribution'],
  ] as const)('opens on the tab of the current %s phase without a tab in the address', async (current, status, tab) => {
    const wrapper = await mountOn(status, railWithCurrent(current))

    expect(selectedTab(wrapper)).toBe(tab)
  })

  it('opens on the tab of the current phase when an earlier phase is blocked', async () => {
    const phases = [
      phase('prepare', 'done', 1, 1),
      phase('supply', 'blocked', 1, 2, { code: 'materials_missing', count: 1 }),
      phase('produce', 'current', 0, 2),
      phase('sell', 'upcoming', 0, 2),
      phase('close', 'upcoming', 0, 1),
    ]

    expect(selectedTab(await mountOn('in_progress', phases))).toBe('chain')
  })

  it('opens on the finances tab of a completed project whose five phases are done', async () => {
    const phases = (['prepare', 'supply', 'produce', 'sell', 'close'] as const).map(key => phase(key, 'done', 1, 1))

    expect(selectedTab(await mountOn('completed', phases))).toBe('distribution')
  })

  it('keeps the tab asked for in the address over the current phase', async () => {
    expect(selectedTab(await mountOn('selling', railWithCurrent('sell'), 'planning'))).toBe('planning')
  })

  it('opens on the sourcing tab when the cockpit cannot be loaded', async () => {
    route.query = {}
    const wrapper = await mountCockpitDetail({ project: nyxProject('selling'), cockpitFailure: 'Cockpit unavailable' })

    expect(selectedTab(wrapper)).toBe('bom')
  })
})
