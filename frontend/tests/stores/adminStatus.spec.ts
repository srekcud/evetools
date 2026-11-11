import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useAdminStore, type AdminStatus } from '@/stores/admin'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DEGRADED_STATUS: AdminStatus = {
  level: 'degraded',
  rules: [{ key: 'failed_messages', severity: 'degraded', value: 3, threshold: 0, section: 'maintenance' }],
}

describe('admin store status', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockReset()
  })

  it('reads the global status from the status endpoint', async () => {
    vi.mocked(apiRequest).mockResolvedValue(DEGRADED_STATUS)
    const store = useAdminStore()

    await store.fetchStatus()

    expect(apiRequest).toHaveBeenCalledWith('/admin/status')
    expect(store.status).toEqual(DEGRADED_STATUS)
  })

  it('leaves the status unknown when the endpoint fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('boom'))
    const store = useAdminStore()

    await store.fetchStatus()

    expect(store.status).toBeNull()
  })

  it('refreshes the status with the other admin data', async () => {
    vi.mocked(apiRequest).mockResolvedValue(DEGRADED_STATUS)
    const store = useAdminStore()

    await store.fetchAll()

    expect(apiRequest).toHaveBeenCalledWith('/admin/status')
  })
})
