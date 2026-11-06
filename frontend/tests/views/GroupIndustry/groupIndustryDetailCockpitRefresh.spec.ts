import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import {
  callsTo,
  COCKPIT_PATH,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_ID,
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

const syncEvent = ref<GroupProjectEvent | null>(null)

async function publish(action: string): Promise<void> {
  syncEvent.value = { action, data: null, timestamp: '2026-11-06T09:00:00+00:00' }
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  syncEvent.value = null
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

describe('project cockpit refreshed by the realtime events', () => {
  it('loads the cockpit again on a project event and on a linked job progress, but not when a member joins', async () => {
    await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
    })
    const afterMount = callsTo(COCKPIT_PATH)

    await publish('sale_recorded')
    const afterSaleRecorded = callsTo(COCKPIT_PATH)

    await publish('member_joined')
    const afterMemberJoined = callsTo(COCKPIT_PATH)

    useSyncStore().syncStatus['industry-project'] = {
      syncType: 'industry-project',
      status: 'notification' as never,
      progress: null,
      message: 'Nyx job delivered',
      data: { projectId: PROJECT_ID },
      timestamp: '2026-11-06T10:00:00+00:00',
    }
    await flushPromises()

    expect([afterMount, afterSaleRecorded, afterMemberJoined, callsTo(COCKPIT_PATH)]).toEqual([1, 2, 2, 3])
  })
})
