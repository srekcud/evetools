import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useAdminStore } from '@/stores/admin'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const COST_INDICES_TYPE = 'cost-indices'
const TRIGGER_SYNC_ENDPOINT = '/admin/actions/sync'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('admin store triggerSync', () => {
  it('posts the sync type to the generic trigger endpoint', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: true, message: 'Sync triggered: cost-indices' })

    const result = await useAdminStore().triggerSync(COST_INDICES_TYPE)

    expect(result).toEqual({ success: true, message: 'Sync triggered: cost-indices' })
    expect(apiRequest).toHaveBeenCalledWith(TRIGGER_SYNC_ENDPOINT, {
      method: 'POST',
      body: JSON.stringify({ type: COST_INDICES_TYPE }),
    })
  })

  it('reports a failed request as an unsuccessful result', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Network down'))

    const result = await useAdminStore().triggerSync(COST_INDICES_TYPE)

    expect(result).toEqual({ success: false, message: 'Network down' })
  })
})
