import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { GROUP_PROJECT_EVENT_COALESCE_MS } from '@/composables/useCoalescedGroupProjectEvents'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import {
  callsTo,
  COCKPIT_PATH,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_PATH,
  route,
  sectionToggleSelector,
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

const CONTRIBUTIONS_PATH = `${PROJECT_PATH}/contributions`
const DISTRIBUTION_PATH = `${PROJECT_PATH}/distribution`
const BOM_PATH = `${PROJECT_PATH}/bom`

const syncEvent = ref<GroupProjectEvent | null>(null)

function publish(action: string): void {
  syncEvent.value = { action, data: null, timestamp: '2026-11-21T09:00:00+00:00' }
}

async function mountTeamProject(tab?: string) {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('selling', 'owner', { membersCount: 3 }),
    cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  syncEvent.value = null
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('group project detail refreshed by the realtime events', () => {
  it('refreshes the cockpit, the BOM and the distribution of the ISK band with no section open but not the contributions, which load when their section opens', async () => {
    const wrapper = await mountTeamProject()
    const before = { cockpit: callsTo(COCKPIT_PATH), bom: callsTo(BOM_PATH) }

    publish('contribution_valued')
    await flushPromises()
    const afterEvent = {
      cockpit: callsTo(COCKPIT_PATH) - before.cockpit,
      bom: callsTo(BOM_PATH) - before.bom,
      contributions: callsTo(CONTRIBUTIONS_PATH),
      distribution: callsTo(DISTRIBUTION_PATH),
    }
    expect(wrapper.find(sectionToggleSelector('contributions')).exists()).toBe(true)
    await wrapper.get(sectionToggleSelector('contributions')).trigger('click')
    await flushPromises()

    expect({ afterEvent, contributionsOnceOpened: callsTo(CONTRIBUTIONS_PATH) }).toEqual({
      afterEvent: { cockpit: 1, bom: 1, contributions: 0, distribution: 2 },
      contributionsOnceOpened: 1,
    })
  })

  it('refreshes the contributions of the open contributions section and the distribution of the ISK band', async () => {
    await mountTeamProject('contributions')
    const contributionsAtMount = callsTo(CONTRIBUTIONS_PATH)

    publish('contribution_valued')
    await flushPromises()

    expect({
      contributions: callsTo(CONTRIBUTIONS_PATH) - contributionsAtMount,
      distribution: callsTo(DISTRIBUTION_PATH),
    }).toEqual({ contributions: 1, distribution: 2 })
  })

  it('reads the cockpit once at the first event of a burst and once at the end of the window, however many events follow', async () => {
    await mountTeamProject()
    const cockpitAtMount = callsTo(COCKPIT_PATH)

    for (const action of ['contribution_submitted', 'sale_recorded', 'line_reserved', 'sale_updated', 'status_changed']) {
      publish(action)
      await flushPromises()
    }
    const afterBurst = callsTo(COCKPIT_PATH) - cockpitAtMount
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    await flushPromises()

    expect({ afterBurst, afterWindow: callsTo(COCKPIT_PATH) - cockpitAtMount }).toEqual({ afterBurst: 1, afterWindow: 2 })
  })
})
