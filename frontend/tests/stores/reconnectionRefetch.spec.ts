import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { apiRequest } from '@/services/api'
import { useIndustryActionsStore } from '@/stores/industry/actions'
import { useIndustryWorkshopProjectsStore } from '@/stores/industry/workshopProjects'
import { useNotificationsStore } from '@/stores/notifications'
import { useSyncStore } from '@/stores/sync'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function endpointsRequested(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(call => call[0] as string)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue([])
})

describe('stores fed by Mercure events, after a reconnection', () => {
  it('reloads the industry action queue', async () => {
    useIndustryActionsStore()
    const syncStore = useSyncStore()

    syncStore.reconnectedAt = 1000
    await nextTick()

    expect(endpointsRequested()).toEqual(['/industry/actions'])
  })

  it('reloads the workshop projects', async () => {
    useIndustryWorkshopProjectsStore()
    const syncStore = useSyncStore()

    syncStore.reconnectedAt = 1000
    await nextTick()

    expect(endpointsRequested()).toEqual(['/industry/workshop-projects'])
  })

  it('reloads the first page of notifications and the unread count', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint.includes('unread-count') ? { count: 4 } : []))
    const notificationsStore = useNotificationsStore()
    const syncStore = useSyncStore()

    syncStore.reconnectedAt = 1000
    await vi.waitFor(() => expect(notificationsStore.unreadCount).toBe(4))

    expect(endpointsRequested().sort()).toEqual(['/me/notifications/unread-count', '/me/notifications?page=1'])
  })

  it('fetches nothing without a reconnection', async () => {
    useIndustryActionsStore()
    useIndustryWorkshopProjectsStore()
    useNotificationsStore()
    useSyncStore()
    await nextTick()

    expect(endpointsRequested()).toEqual([])
  })
})
