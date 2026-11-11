import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useAdminStore } from '@/stores/admin'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const USER_ID = '0196a1c0-0000-7000-8000-000000000001'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('admin store user support', () => {
  it('encodes the search term in the query string', async () => {
    vi.mocked(apiRequest).mockResolvedValue([])

    await useAdminStore().searchUsers('Mira T&o')

    expect(apiRequest).toHaveBeenCalledWith('/admin/users?search=Mira%20T%26o')
  })

  it('reads the sheet of a user', async () => {
    const sheet = { userId: USER_ID, createdAt: '2026-01-02T08:00:00+00:00', characters: [], syncs: [] }
    vi.mocked(apiRequest).mockResolvedValue(sheet)

    await expect(useAdminStore().fetchUserSheet(USER_ID)).resolves.toEqual(sheet)
    expect(apiRequest).toHaveBeenCalledWith(`/admin/users/${USER_ID}`)
  })

  it('posts the sync type to the user endpoint', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: true, message: 'Sync triggered: pve' })

    const result = await useAdminStore().triggerUserSync(USER_ID, 'pve')

    expect(result).toEqual({ success: true, message: 'Sync triggered: pve' })
    expect(apiRequest).toHaveBeenCalledWith(`/admin/users/${USER_ID}/sync`, {
      method: 'POST',
      body: JSON.stringify({ type: 'pve' }),
    })
  })

  it('reports a failed trigger as an unsuccessful result', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Forbidden'))

    await expect(useAdminStore().triggerUserSync(USER_ID, 'pve')).resolves.toEqual({ success: false, message: 'Forbidden' })
  })
})
