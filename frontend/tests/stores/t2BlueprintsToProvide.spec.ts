import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useGroupT2BlueprintsToProvideStore } from '@/stores/group-industry/t2BlueprintsToProvide'
import type { T2BlueprintsToProvide } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-wing'
const TO_PROVIDE_PATH = `/industry/projects/${PROJECT_ID}/t2-blueprints-to-provide`

const TO_PROVIDE: T2BlueprintsToProvide = {
  id: PROJECT_ID,
  excludesInvention: true,
  lines: [
    { productTypeId: 2_185, typeName: 'Hammerhead II', runs: 40 },
    { productTypeId: 12_005, typeName: 'Ishtar', runs: 15 },
  ],
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group T2 blueprints to provide store (issue 806)', () => {
  it('reads the T2 blueprint copies to provide of the project and exposes them without error', async () => {
    vi.mocked(apiRequest).mockResolvedValue(TO_PROVIDE)
    const store = useGroupT2BlueprintsToProvideStore()

    await store.fetchToProvide(PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(TO_PROVIDE_PATH)
    expect({ toProvide: store.toProvide, loading: store.loading, error: store.error }).toEqual({ toProvide: TO_PROVIDE, loading: false, error: null })
  })

  it('holds no list and the message of the failure when the read fails', async () => {
    const store = useGroupT2BlueprintsToProvideStore()
    vi.mocked(apiRequest).mockResolvedValueOnce(TO_PROVIDE)
    await store.fetchToProvide(PROJECT_ID)
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('API error: 503', 503))

    await store.fetchToProvide(PROJECT_ID)

    expect({ toProvide: store.toProvide, loading: store.loading, error: store.error }).toEqual({ toProvide: null, loading: false, error: 'API error: 503' })
  })
})
