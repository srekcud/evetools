import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupStructuresStore } from '@/stores/group-industry/structures'
import {
  FERNITE_COMPOSITE_UNKNOWN_PRICE,
  HOBGOBLIN_II_WITHOUT_STRUCTURE,
  ISHTAR_CHEAPEST_RETAINED,
  ISHTAR_FAVORITE_SYSTEM_RETAINED,
  projectStructures,
} from '../structureRows'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_BATCH_ID = 'project-ishtar-batch'
const HECATE_BATCH_ID = 'project-hecate-batch'

function structuresPath(projectId: string): string {
  return `/industry/projects/${projectId}/structures`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group structures store', () => {
  it('reads the groups, the project policy and the transport rate while loading is true only during the request', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupStructuresStore()

    void store.fetchStructures(ISHTAR_BATCH_ID)
    const whileLoading = { loading: store.loading, groups: store.groups }
    pending.resolve(projectStructures(
      ISHTAR_BATCH_ID,
      [ISHTAR_FAVORITE_SYSTEM_RETAINED, FERNITE_COMPOSITE_UNKNOWN_PRICE],
      { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 125_000.5 },
    ))
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(structuresPath(ISHTAR_BATCH_ID))
    expect(whileLoading).toEqual({ loading: true, groups: [] })
    expect({
      loading: store.loading,
      error: store.error,
      groups: store.groups,
      acceptsOutsideFavoriteSystem: store.acceptsOutsideFavoriteSystem,
      minimumOutsideSaving: store.minimumOutsideSaving,
      transportIskPerCubicMeterJump: store.transportIskPerCubicMeterJump,
    }).toEqual({
      loading: false,
      error: null,
      groups: [ISHTAR_FAVORITE_SYSTEM_RETAINED, FERNITE_COMPOSITE_UNKNOWN_PRICE],
      acceptsOutsideFavoriteSystem: true,
      minimumOutsideSaving: 125_000.5,
      transportIskPerCubicMeterJump: 150,
    })
  })

  it('exposes the API error instead of empty groups when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Structures unavailable: project not found'))
    const store = useGroupStructuresStore()

    await store.fetchStructures(ISHTAR_BATCH_ID)

    expect({ loading: store.loading, error: store.error, groups: store.groups }).toEqual({
      loading: false,
      error: 'Structures unavailable: project not found',
      groups: [],
    })
  })

  it('ignores the late answer of a project that is no longer the one being read', async () => {
    const ishtarAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(ishtarAnswer.promise)
    vi.mocked(apiRequest).mockResolvedValueOnce(projectStructures(HECATE_BATCH_ID, [HOBGOBLIN_II_WITHOUT_STRUCTURE]))
    const store = useGroupStructuresStore()

    void store.fetchStructures(ISHTAR_BATCH_ID)
    await store.fetchStructures(HECATE_BATCH_ID)
    ishtarAnswer.resolve(projectStructures(ISHTAR_BATCH_ID, [ISHTAR_CHEAPEST_RETAINED]))
    await flushPromises()

    expect({ loading: store.loading, error: store.error, groups: store.groups }).toEqual({
      loading: false,
      error: null,
      groups: [HOBGOBLIN_II_WITHOUT_STRUCTURE],
    })
  })
})
