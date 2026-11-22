import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import ProjectFlowActions from '@/components/group-industry/ProjectFlowActions.vue'
import ProjectFlowGrid from '@/components/group-industry/ProjectFlowGrid.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { stationQuantity, typeQuantities, JITA_ID, JITA_NAME, STATION_A_ID, TRITANIUM, ZYDRINE } from '../../components/group-industry/projectFlowFixtures'
import { AZBEL_PLACE, JITA_PLACE, placedAction } from '../../todoPlaceFixtures'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_ID,
  route,
  sectionSelector,
  sectionToggleSelector,
} from './projectCockpitFixtures'
import { flowCollapsedStorageKey } from '@/views/GroupIndustry/flowCollapse'

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

const FLOW_TOGGLE = '[data-testid="flow-section-toggle"]'
const FLOW_SUMMARY_STATE = '[data-testid="flow-summary-state"]'
const FLOW_VIEW_SWITCH = '[data-testid="flow-view-planning"]'

const syncEvent = ref<GroupProjectEvent | null>(null)
const scrollIntoView = vi.fn()

async function mountFlowProject(): Promise<VueWrapper> {
  route.query = {}
  return mountCockpitDetail({
    project: nyxProject('selling', 'owner', { membersCount: 3, sellStructureId: JITA_ID, sellStructureName: JITA_NAME }),
    cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
    stationQuantities: [typeQuantities(ZYDRINE, [stationQuantity({ locationId: STATION_A_ID, stationName: 'Azbel', toBring: 40 })])],
    placeActions: [
      placedAction({ id: 'launch-1', code: 'launch_job', place: AZBEL_PLACE, typeId: 11_540, typeName: 'Capital Armor Plates', quantity: 5, here: true }),
      placedAction({ id: 'buy-1', code: 'source_material', place: JITA_PLACE, ...TRITANIUM, quantity: 400, here: false }),
    ],
  }, 'en')
}

function summaryOf(wrapper: VueWrapper): [string | undefined, string | undefined][] {
  return wrapper.findAll(FLOW_SUMMARY_STATE).map(entry => [entry.attributes('data-state'), entry.attributes('data-count')])
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  localStorage.clear()
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

describe('collapsible flow section of the project page', () => {
  it('shows the flow expanded by default, with a toggle in its header and no summary', async () => {
    const wrapper = await mountFlowProject()

    expect({
      toggle: wrapper.find(FLOW_TOGGLE).exists(),
      expanded: wrapper.find(FLOW_TOGGLE).attributes('aria-expanded'),
      grid: wrapper.findComponent(ProjectFlowGrid).exists(),
      actions: wrapper.findComponent(ProjectFlowActions).exists(),
      summary: wrapper.findAll(FLOW_SUMMARY_STATE).length,
    }).toEqual({ toggle: true, expanded: 'true', grid: true, actions: true, summary: 0 })
  })

  it('collapses the flow to a one-line summary of the cards per state and expands it again', async () => {
    const wrapper = await mountFlowProject()
    const cardCount = wrapper.findAll('[data-testid="flow-card"]').length

    await wrapper.get(FLOW_TOGGLE).trigger('click')

    const collapsed = {
      expanded: wrapper.get(FLOW_TOGGLE).attributes('aria-expanded'),
      grid: wrapper.findComponent(ProjectFlowGrid).exists(),
      viewSwitch: wrapper.find(FLOW_VIEW_SWITCH).exists(),
      actions: wrapper.findComponent(ProjectFlowActions).exists(),
      summaryTotal: summaryOf(wrapper).reduce((total, [, count]) => total + Number(count), 0),
      summaryStates: summaryOf(wrapper).length,
    }
    await wrapper.get(FLOW_TOGGLE).trigger('click')

    expect(collapsed).toEqual({ expanded: 'false', grid: false, viewSwitch: false, actions: false, summaryTotal: cardCount, summaryStates: expect.any(Number) })
    expect(collapsed.summaryStates).toBeGreaterThan(0)
    expect({
      expanded: wrapper.get(FLOW_TOGGLE).attributes('aria-expanded'),
      grid: wrapper.findComponent(ProjectFlowGrid).exists(),
      summary: wrapper.findAll(FLOW_SUMMARY_STATE).length,
    }).toEqual({ expanded: 'true', grid: true, summary: 0 })
  })

  it('remembers the collapsed state of the project for the viewer, and the sections below stay reachable', async () => {
    const first = await mountFlowProject()
    await first.get(FLOW_TOGGLE).trigger('click')
    expect(localStorage.getItem(flowCollapsedStorageKey(PROJECT_ID))).toBe('closed')

    const second = await mountFlowProject()

    expect({
      expanded: second.get(FLOW_TOGGLE).attributes('aria-expanded'),
      grid: second.findComponent(ProjectFlowGrid).exists(),
      sections: second.find(sectionSelector('bom')).exists(),
    }).toEqual({ expanded: 'false', grid: false, sections: true })
  })

  it('stays expanded for another project that was never collapsed', async () => {
    localStorage.setItem(flowCollapsedStorageKey('project-other'), 'closed')

    const wrapper = await mountFlowProject()

    expect(wrapper.get(FLOW_TOGGLE).attributes('aria-expanded')).toBe('true')
  })

  it('scrolls a section into view when its header opens it with the flow expanded, and not when it closes it', async () => {
    const wrapper = await mountFlowProject()
    scrollIntoView.mockClear()

    await wrapper.get(sectionToggleSelector('sales')).trigger('click')
    await flushPromises()
    const afterOpening = scrollIntoView.mock.contexts.slice()
    scrollIntoView.mockClear()

    await wrapper.get(sectionToggleSelector('sales')).trigger('click')
    await flushPromises()

    expect(afterOpening).toContain(wrapper.get(sectionSelector('sales')).element)
    expect(scrollIntoView).not.toHaveBeenCalled()
  })
})
