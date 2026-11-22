import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { GROUP_PROJECT_EVENT_COALESCE_MS } from '@/composables/useCoalescedGroupProjectEvents'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import {
  callsTo,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_PATH,
  route,
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

const DISTRIBUTION_PATH = `${PROJECT_PATH}/distribution`
const SETTLEMENT_PATH = `${PROJECT_PATH}/settlement`
const SURPLUS_PATH = `${PROJECT_PATH}/surplus`

const syncEvent = ref<GroupProjectEvent | null>(null)

function publish(action: string): void {
  syncEvent.value = { action, data: null, timestamp: '2026-11-22T09:00:00+00:00' }
}

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.useRealTimers()
})

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  route.query = {}
  syncEvent.value = null
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

describe('detail page, settlement block data of a selling project', () => {
  it('reads the distribution, the settlement and the surplus at mount and refreshes them on a sale or a surplus storage with no section open', async () => {
    await mountCockpitDetail({
      project: nyxProject('selling', 'owner', { membersCount: 3 }),
      cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
    })
    const atMount = { distribution: callsTo(DISTRIBUTION_PATH), settlement: callsTo(SETTLEMENT_PATH), surplus: callsTo(SURPLUS_PATH) }

    publish('sale_recorded')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    publish('surplus_stored')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    await flushPromises()

    expect(atMount).toEqual({ distribution: 1, settlement: 1, surplus: 1 })
    expect({
      distribution: callsTo(DISTRIBUTION_PATH) - atMount.distribution,
      settlement: callsTo(SETTLEMENT_PATH) - atMount.settlement,
      surplus: callsTo(SURPLUS_PATH) - atMount.surplus,
    }).toEqual({ distribution: 2, settlement: 2, surplus: 1 })
  })
})
