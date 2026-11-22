import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import BomTable from '@/components/group-industry/BomTable.vue'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import ProjectFlowActions from '@/components/group-industry/ProjectFlowActions.vue'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import ProjectFlowPlanning from '@/components/group-industry/ProjectFlowPlanning.vue'
import ProjectInvention from '@/components/group-industry/ProjectInvention.vue'
import ProjectJournal from '@/components/group-industry/ProjectJournal.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { GROUP_PROJECT_EVENT_COALESCE_MS } from '@/composables/useCoalescedGroupProjectEvents'
import { apiRequest } from '@/services/api'
import type { GroupProjectStatus, GroupSale } from '@/stores/group-industry/types'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { stationQuantity, typeQuantities, JITA_ID, JITA_NAME, STATION_A_ID, TRITANIUM, ZYDRINE } from '../../components/group-industry/projectFlowFixtures'
import { AZBEL_PLACE, JITA_PLACE, placedAction } from '../../todoPlaceFixtures'
import {
  callsTo,
  COCKPIT_RAIL,
  cockpitOf,
  isSectionOpen,
  mountCockpitDetail,
  NEXT_ACTION_CARD,
  nextAction,
  nyxProject,
  PLACE_ACTIONS_PATH,
  PLANNING_PATH,
  PROJECT_ID,
  route,
  SALES_PATH,
  sectionSelector,
  sectionToggleSelector,
  STATION_QUANTITIES_PATH,
  TAB_BAR_BUTTON,
} from './projectCockpitFixtures'

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SECTION_KEYS = ['bom', 'contributions', 'distribution', 'sales', 'chain', 'invention', 'journal', 'members']

const SECTION_COMPONENTS = [
  { key: 'bom', component: BomTable },
  { key: 'contributions', component: ContributionTable },
  { key: 'distribution', component: DistributionTable },
  { key: 'sales', component: SalesTable },
  { key: 'chain', component: ProductionChain },
  { key: 'invention', component: ProjectInvention },
  { key: 'journal', component: ProjectJournal },
  { key: 'members', component: MemberTable },
]

const HEADER = '[data-testid="project-header"]'
const PROJECT_TO_DO_TITLE = 'À faire du projet'

const HEADER_VERBS: Record<TestLocale, { start: string; sell: string; close: string }> = {
  en: { start: 'Start the project', sell: 'Move to selling', close: 'Close the project' },
  fr: { start: 'Démarrer le projet', sell: 'Passer à la vente', close: 'Clôturer le projet' },
}

const syncEvent = ref<GroupProjectEvent | null>(null)
const scrollIntoView = vi.fn()

async function publish(action: string): Promise<void> {
  syncEvent.value = { action, data: null, timestamp: '2026-11-22T09:00:00+00:00' }
  await flushPromises()
}

function nyxSale(quantity: number): GroupSale {
  return { id: `sale-${quantity}`, typeId: 23_913, typeName: 'Nyx', quantity } as GroupSale
}

async function mountTeamNyx(tab?: string, status: GroupProjectStatus = 'selling'): Promise<VueWrapper> {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject(status, 'owner', { membersCount: 3 }),
    cockpit: cockpitOf(status, nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
  })
}

function headerButtonTexts(wrapper: VueWrapper): string[] {
  return wrapper.get(HEADER).findAll('button').map(button => button.text()).filter(text => text !== '')
}

async function toggleSection(wrapper: VueWrapper, key: string): Promise<void> {
  expect(wrapper.find(sectionToggleSelector(key)).exists(), `${key} summary`).toBe(true)
  await wrapper.get(sectionToggleSelector(key)).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  route.query = {}
  syncEvent.value = null
  replace.mockReset()
  replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    route.query = query
  })
  scrollIntoView.mockReset()
  window.HTMLElement.prototype.scrollIntoView = scrollIntoView
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('group project detail as a flow grid', () => {
  it('shows the flow grid and the project to do column and none of the phase rail, the next action card and the tab bar', async () => {
    route.query = {}
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress', 'owner', { membersCount: 3 }),
      cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 })),
    }, 'fr')

    expect(wrapper.findComponent(ProjectFlowGrid).exists()).toBe(true)
    expect(wrapper.findComponent(ProjectFlowActions).exists()).toBe(true)
    expect(wrapper.text()).toContain(PROJECT_TO_DO_TITLE)
    expect(wrapper.find(COCKPIT_RAIL).exists()).toBe(false)
    expect(wrapper.find(NEXT_ACTION_CARD).exists()).toBe(false)
    expect(wrapper.find(TAB_BAR_BUTTON).exists()).toBe(false)
    expect(wrapper.find('[role="tab"]').exists()).toBe(false)
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
  })

  it('keeps the header, the flow grid and the project to do column when the cockpit cannot be loaded', async () => {
    route.query = {}
    const wrapper = await mountCockpitDetail({ project: nyxProject('in_progress', 'owner', { membersCount: 3 }), cockpitFailure: 'Cockpit unavailable' })

    expect(wrapper.find('h1').text()).toBe('Nyx batch')
    expect(wrapper.findComponent(ProjectFlowGrid).exists()).toBe(true)
    expect(wrapper.findComponent(ProjectFlowActions).exists()).toBe(true)
    expect(wrapper.find(COCKPIT_RAIL).exists()).toBe(false)
    expect(wrapper.find(NEXT_ACTION_CARD).exists()).toBe(false)
  })

  it('reads the station quantities, the place actions and the sales of the project when it opens', async () => {
    await mountTeamNyx()

    expect({
      stationQuantities: callsTo(STATION_QUANTITIES_PATH),
      placeActions: callsTo(PLACE_ACTIONS_PATH),
      sales: callsTo(SALES_PATH),
    }).toEqual({ stationQuantities: 1, placeActions: 1, sales: 1 })
  })

  it('shows what the three reads answer, in the grid and in the project to do column', async () => {
    route.query = {}
    const wrapper = await mountCockpitDetail({
      project: nyxProject('selling', 'owner', { membersCount: 3, sellStructureId: JITA_ID, sellStructureName: JITA_NAME }),
      cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
      stationQuantities: [typeQuantities(ZYDRINE, [stationQuantity({ locationId: STATION_A_ID, stationName: 'Azbel', toBring: 40 })])],
      placeActions: [
        placedAction({ id: 'launch-1', code: 'launch_job', place: AZBEL_PLACE, typeId: 11_540, typeName: 'Capital Armor Plates', quantity: 5, here: true }),
        placedAction({ id: 'buy-1', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400, here: false }),
      ],
      sales: [nyxSale(2), nyxSale(1)],
    }, 'en')

    const cards = wrapper.findAll('[data-testid="flow-card"]').map(card => card.text())
    expect(cards.filter(card => card.includes('Zydrine'))).toHaveLength(1)
    expect(cards.filter(card => card.includes('Nyx') && card.includes('3'))).toHaveLength(1)
    expect(cards.filter(card => card.includes('Capital Armor Plates'))).toHaveLength(1)
    expect(cards.filter(card => card.includes('Tritanium') && card.includes('400'))).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="flow-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-testid="flow-action-row"]')).toHaveLength(2)
  })

  it('keeps the status buttons of the header and no longer hides the one the next action card used to carry', async () => {
    const targets: [GroupProjectStatus, GroupProjectStatus, 'start' | 'sell' | 'close'][] = [
      ['draft', 'in_progress', 'start'],
      ['in_progress', 'selling', 'sell'],
      ['selling', 'completed', 'close'],
    ]

    for (const locale of TEST_LOCALES) {
      for (const [status, target, verb] of targets) {
        route.query = {}
        const wrapper = await mountCockpitDetail({
          project: nyxProject(status, 'owner', { private: false, membersCount: 3 }),
          cockpit: cockpitOf(status, nextAction({ code: 'start_project', targetStatus: target })),
        }, locale)

        expect(headerButtonTexts(wrapper), `${locale} ${status}`).toContain(HEADER_VERBS[locale][verb])
      }
    }
  })
})

describe('group project detail sections of the former tabs', () => {
  it('shows one section per former tab, all closed, with none of their content rendered, when the URL has no tab', async () => {
    const wrapper = await mountTeamNyx()

    expect(SECTION_KEYS.map(key => [key, wrapper.find(sectionSelector(key)).exists()])).toEqual(SECTION_KEYS.map(key => [key, true]))
    expect(SECTION_KEYS.filter(key => isSectionOpen(wrapper, key))).toEqual([])
    expect(SECTION_COMPONENTS.filter(({ component }) => wrapper.findComponent(component).exists()).map(({ key }) => key)).toEqual([])
  })

  it('opens only the sales section, with its content, for a sales tab in the URL', async () => {
    const wrapper = await mountTeamNyx('sales')

    expect(SECTION_KEYS.filter(key => isSectionOpen(wrapper, key))).toEqual(['sales'])
    expect(SECTION_COMPONENTS.filter(({ component }) => wrapper.findComponent(component).exists()).map(({ key }) => key)).toEqual(['sales'])
  })

  it('scrolls the section requested by the URL into view', async () => {
    const wrapper = await mountTeamNyx('chain')

    expect(wrapper.find(sectionSelector('chain')).exists()).toBe(true)
    expect(scrollIntoView.mock.contexts).toContain(wrapper.get(sectionSelector('chain')).element)
  })

  it('opens a section from its summary, puts its tab in the URL and shows its content', async () => {
    const wrapper = await mountTeamNyx()

    await toggleSection(wrapper, 'sales')

    expect(isSectionOpen(wrapper, 'sales')).toBe(true)
    expect(wrapper.findComponent(SalesTable).exists()).toBe(true)
    expect(replace).toHaveBeenCalledTimes(1)
    expect(replace.mock.lastCall?.[0]).toMatchObject({ query: { tab: 'sales' } })
  })

  it('keeps a single section open, the URL carrying a single tab, when a second section is opened', async () => {
    const wrapper = await mountTeamNyx('sales')

    await toggleSection(wrapper, 'chain')

    expect(SECTION_KEYS.filter(key => isSectionOpen(wrapper, key))).toEqual(['chain'])
    expect(replace.mock.lastCall?.[0]).toMatchObject({ query: { tab: 'chain' } })
  })

  it('closes an open section from its summary, takes its tab out of the URL and removes its content', async () => {
    const wrapper = await mountTeamNyx('sales')
    expect(wrapper.findComponent(SalesTable).exists()).toBe(true)

    await toggleSection(wrapper, 'sales')

    expect(isSectionOpen(wrapper, 'sales')).toBe(false)
    expect(wrapper.findComponent(SalesTable).exists()).toBe(false)
    expect(replace).toHaveBeenCalledTimes(1)
    expect(Object.keys((replace.mock.lastCall?.[0] as { query: Record<string, string> }).query)).not.toContain('tab')
  })
})

describe('group project detail flow data refreshed by the realtime events', () => {
  it('reads the station quantities and the place actions again on a project event, without opening a section', async () => {
    const wrapper = await mountTeamNyx()
    const atMount = { stationQuantities: callsTo(STATION_QUANTITIES_PATH), placeActions: callsTo(PLACE_ACTIONS_PATH) }

    await publish('contribution_valued')

    expect({
      stationQuantities: callsTo(STATION_QUANTITIES_PATH) - atMount.stationQuantities,
      placeActions: callsTo(PLACE_ACTIONS_PATH) - atMount.placeActions,
    }).toEqual({ stationQuantities: 1, placeActions: 1 })
    expect(SECTION_KEYS.filter(key => isSectionOpen(wrapper, key))).toEqual([])
    expect(replace).not.toHaveBeenCalled()
  })

  it('reads the station quantities and the place actions once at the first event of a burst and once at the end of the window', async () => {
    await mountTeamNyx()
    const atMount = { stationQuantities: callsTo(STATION_QUANTITIES_PATH), placeActions: callsTo(PLACE_ACTIONS_PATH) }

    for (const action of ['contribution_submitted', 'line_reserved', 'member_joined']) await publish(action)
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    await flushPromises()

    expect({
      stationQuantities: callsTo(STATION_QUANTITIES_PATH) - atMount.stationQuantities,
      placeActions: callsTo(PLACE_ACTIONS_PATH) - atMount.placeActions,
    }).toEqual({ stationQuantities: 2, placeActions: 2 })
  })

  it('reads the sales again on a sale event so the sold column follows, with the sales section closed', async () => {
    await mountTeamNyx()
    const salesAtMount = callsTo(SALES_PATH)

    await publish('sale_recorded')

    expect(callsTo(SALES_PATH) - salesAtMount).toBe(1)
  })

  it('reads the station quantities and the place actions again on the progress of the project sync', async () => {
    await mountTeamNyx()
    const atMount = { stationQuantities: callsTo(STATION_QUANTITIES_PATH), placeActions: callsTo(PLACE_ACTIONS_PATH) }

    useSyncStore().syncStatus['industry-project'] = {
      syncType: 'industry-project',
      status: 'notification' as never,
      progress: null,
      message: 'Nyx job delivered',
      data: { projectId: PROJECT_ID },
      timestamp: '2026-11-22T10:00:00+00:00',
    }
    await flushPromises()

    expect({
      stationQuantities: callsTo(STATION_QUANTITIES_PATH) - atMount.stationQuantities,
      placeActions: callsTo(PLACE_ACTIONS_PATH) - atMount.placeActions,
    }).toEqual({ stationQuantities: 1, placeActions: 1 })
  })

  it('keeps the pending contribution count and the member count on the summaries of the contributions and members sections', async () => {
    route.query = {}
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress', 'owner', { membersCount: 3, pendingContributionCount: 4 }),
      cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 })),
    })

    expect(wrapper.get(sectionToggleSelector('contributions')).text()).toContain('4')
    expect(wrapper.get(sectionToggleSelector('members')).text()).toContain('3')
  })
})

describe('group project detail planning view of the flow', () => {
  it('swaps the flow grid for the planning from the toggle, keeps the project to do column, and reads the planning only once it is shown', async () => {
    route.query = {}
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress', 'owner', { membersCount: 3 }),
      cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 })),
    })
    expect(wrapper.find('[data-testid="flow-view-planning"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="flow-view-flux"]').exists()).toBe(true)
    const beforeToggle = {
      grid: wrapper.find('[data-testid="project-flow-grid"]').exists(),
      planning: wrapper.findComponent(ProjectFlowPlanning).exists(),
      planningReads: callsTo(PLANNING_PATH),
    }

    await wrapper.find('[data-testid="flow-view-planning"]').trigger('click')
    await flushPromises()
    const planningView = {
      grid: wrapper.find('[data-testid="project-flow-grid"]').exists(),
      planning: wrapper.find('[data-testid="flow-planning"]').exists(),
      actions: wrapper.findComponent(ProjectFlowActions).exists(),
      planningReads: callsTo(PLANNING_PATH),
    }

    await wrapper.find('[data-testid="flow-view-flux"]').trigger('click')
    await flushPromises()

    expect({
      beforeToggle,
      planningView,
      backToFlux: {
        grid: wrapper.find('[data-testid="project-flow-grid"]').exists(),
        planning: wrapper.find('[data-testid="flow-planning"]').exists(),
        actions: wrapper.findComponent(ProjectFlowActions).exists(),
      },
    }).toEqual({
      beforeToggle: { grid: true, planning: false, planningReads: 0 },
      planningView: { grid: false, planning: true, actions: true, planningReads: 1 },
      backToFlux: { grid: true, planning: false, actions: true },
    })
  })
})
