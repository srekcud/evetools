import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupBuyOrBuildStore } from '@/stores/group-industry/buyOrBuild'
import { projectBuyOrBuild, FERNITE_COMPOSITE_BOUGHT, HOBGOBLIN_II_BUILT, OGRE_II_WITHOUT_ROUTE } from '../buyOrBuildRows'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_BATCH_ID = 'project-ishtar-batch'
const HECATE_BATCH_ID = 'project-hecate-batch'

function buyOrBuildPath(projectId: string): string {
  return `/industry/projects/${projectId}/buy-or-build`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group buy or build store', () => {
  it('reads the rows and the import rate of the project while loading is true only during the request', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupBuyOrBuildStore()

    void store.fetchBuyOrBuild(ISHTAR_BATCH_ID)
    const whileLoading = { loading: store.loading, rows: store.rows }
    pending.resolve(projectBuyOrBuild(ISHTAR_BATCH_ID, [HOBGOBLIN_II_BUILT, FERNITE_COMPOSITE_BOUGHT]))
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(buyOrBuildPath(ISHTAR_BATCH_ID))
    expect(whileLoading).toEqual({ loading: true, rows: [] })
    expect({
      loading: store.loading,
      error: store.error,
      rows: store.rows,
      importIskPerCubicMeterJump: store.importIskPerCubicMeterJump,
    }).toEqual({
      loading: false,
      error: null,
      rows: [HOBGOBLIN_II_BUILT, FERNITE_COMPOSITE_BOUGHT],
      importIskPerCubicMeterJump: 150,
    })
  })

  it('exposes the API error instead of empty rows when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Buy or build unavailable: project not found'))
    const store = useGroupBuyOrBuildStore()

    await store.fetchBuyOrBuild(ISHTAR_BATCH_ID)

    expect({ loading: store.loading, error: store.error, rows: store.rows }).toEqual({
      loading: false,
      error: 'Buy or build unavailable: project not found',
      rows: [],
    })
  })

  it('ignores the late answer of a project that is no longer the one being read', async () => {
    const ishtarAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(ishtarAnswer.promise)
    vi.mocked(apiRequest).mockResolvedValueOnce(projectBuyOrBuild(HECATE_BATCH_ID, [OGRE_II_WITHOUT_ROUTE]))
    const store = useGroupBuyOrBuildStore()

    void store.fetchBuyOrBuild(ISHTAR_BATCH_ID)
    await store.fetchBuyOrBuild(HECATE_BATCH_ID)
    ishtarAnswer.resolve(projectBuyOrBuild(ISHTAR_BATCH_ID, [HOBGOBLIN_II_BUILT]))
    await flushPromises()

    expect({ loading: store.loading, error: store.error, rows: store.rows }).toEqual({
      loading: false,
      error: null,
      rows: [OGRE_II_WITHOUT_ROUTE],
    })
  })
})
