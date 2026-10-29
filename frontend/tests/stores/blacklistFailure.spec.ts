import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useBlacklistStore } from '@/stores/industry/blacklist'
import type { BlacklistConfig } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FUEL_BLOCKS_GROUP_ID = 1136
const CAPITAL_COMPONENTS_GROUP_ID = 873
const NITROGEN_FUEL_BLOCK_TYPE_ID = 4051

const STORED_BLACKLIST: BlacklistConfig = {
  categories: [
    { key: 'fuel_blocks', label: 'Fuel blocks', groupIds: [FUEL_BLOCKS_GROUP_ID], blacklisted: false },
    { key: 'capital_components', label: 'Capital components', groupIds: [CAPITAL_COMPONENTS_GROUP_ID], blacklisted: true },
  ],
  items: [{ typeId: NITROGEN_FUEL_BLOCK_TYPE_ID, typeName: 'Nitrogen Fuel Block' }],
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('blacklist store failure (issue 189)', () => {
  it('rethrows the failure of the blacklist load and holds no blacklist', async () => {
    const store = useBlacklistStore()
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 503', 503))

    await expect(store.fetchBlacklist()).rejects.toThrow('API error: 503')
    expect(store.blacklist).toBeNull()
  })

  it('rethrows the failure of a blacklist change and keeps the blacklist as it was before the change', async () => {
    const store = useBlacklistStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(STORED_BLACKLIST)
    await store.fetchBlacklist()
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 503', 503))

    await expect(store.updateBlacklist([CAPITAL_COMPONENTS_GROUP_ID, FUEL_BLOCKS_GROUP_ID], [NITROGEN_FUEL_BLOCK_TYPE_ID]))
      .rejects.toThrow('API error: 503')
    expect(store.blacklist).toEqual(STORED_BLACKLIST)
  })

  it('exposes no shared error field, the screens show their own failures', () => {
    expect(useBlacklistStore()).not.toHaveProperty('error')
  })
})
