import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useBlacklistStore } from '@/stores/industry/blacklist'
import type { BlacklistConfig } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BLACKLIST_PATH = '/industry/blacklist'
const CAPITAL_COMPONENTS_GROUP_ID = 873

const KEEPING_INVENTION: BlacklistConfig = {
  categories: [
    { key: 'capital_components', label: 'Capital components', groupIds: [CAPITAL_COMPONENTS_GROUP_ID], blacklisted: true, partiallyBlacklisted: false },
  ],
  groupIds: [CAPITAL_COMPONENTS_GROUP_ID],
  items: [],
  excludeInvention: false,
}

const EXCLUDING_INVENTION: BlacklistConfig = { ...KEEPING_INVENTION, excludeInvention: true }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('blacklist store invention exclusion (issue 806)', () => {
  it('puts only the excludeInvention value and holds the blacklist answered by the API', async () => {
    const store = useBlacklistStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(KEEPING_INVENTION)
    await store.fetchBlacklist()
    vi.mocked(apiRequest).mockResolvedValueOnce(EXCLUDING_INVENTION)

    await store.updateExcludeInvention(true)

    expect(vi.mocked(apiRequest).mock.calls[1]).toEqual([BLACKLIST_PATH, { method: 'PUT', body: JSON.stringify({ excludeInvention: true }) }])
    expect(store.blacklist).toEqual(EXCLUDING_INVENTION)
  })

  it('rethrows the failure of the change and keeps the blacklist as it was', async () => {
    const store = useBlacklistStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(KEEPING_INVENTION)
    await store.fetchBlacklist()
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 503', 503))

    await expect(store.updateExcludeInvention(true)).rejects.toThrow('API error: 503')
    expect(store.blacklist).toEqual(KEEPING_INVENTION)
  })
})
