import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupResearchStore } from '@/stores/group-industry/research'
import { HOBGOBLIN_II_WORTH, ISHTAR_WORTH, OGRE_II_UNKNOWN_COST, projectResearch, WARRIOR_II_NOT_WORTH } from '../researchRows'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_BATCH_ID = 'project-ishtar-batch'
const HECATE_BATCH_ID = 'project-hecate-batch'

function researchPath(projectId: string): string {
  return `/industry/projects/${projectId}/research`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group research store', () => {
  it('reads the research rows of the project while loading is true only during the request', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupResearchStore()

    void store.fetchResearch(ISHTAR_BATCH_ID)
    const whileLoading = { loading: store.loading, rows: store.rows }
    pending.resolve(projectResearch(ISHTAR_BATCH_ID, [HOBGOBLIN_II_WORTH, OGRE_II_UNKNOWN_COST, WARRIOR_II_NOT_WORTH]))
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(researchPath(ISHTAR_BATCH_ID))
    expect(whileLoading).toEqual({ loading: true, rows: [] })
    expect({ loading: store.loading, error: store.error, rows: store.rows }).toEqual({
      loading: false,
      error: null,
      rows: [HOBGOBLIN_II_WORTH, OGRE_II_UNKNOWN_COST, WARRIOR_II_NOT_WORTH],
    })
  })

  it('exposes the API error instead of empty rows when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Research unavailable: project not found'))
    const store = useGroupResearchStore()

    await store.fetchResearch(ISHTAR_BATCH_ID)

    expect({ loading: store.loading, error: store.error, rows: store.rows }).toEqual({
      loading: false,
      error: 'Research unavailable: project not found',
      rows: [],
    })
  })

  it('empties the rows of the previous project while the rows of the next project are read', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(projectResearch(ISHTAR_BATCH_ID, [ISHTAR_WORTH]))
    const hecateAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(hecateAnswer.promise)
    const store = useGroupResearchStore()

    await store.fetchResearch(ISHTAR_BATCH_ID)
    void store.fetchResearch(HECATE_BATCH_ID)
    const whileReadingNextProject = store.rows
    hecateAnswer.resolve(projectResearch(HECATE_BATCH_ID, [HOBGOBLIN_II_WORTH]))
    await flushPromises()

    expect({ whileReadingNextProject, rows: store.rows }).toEqual({
      whileReadingNextProject: [],
      rows: [HOBGOBLIN_II_WORTH],
    })
  })

  it('ignores the late answer of a project that is no longer the one being read', async () => {
    const ishtarAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(ishtarAnswer.promise)
    vi.mocked(apiRequest).mockResolvedValueOnce(projectResearch(HECATE_BATCH_ID, [OGRE_II_UNKNOWN_COST]))
    const store = useGroupResearchStore()

    void store.fetchResearch(ISHTAR_BATCH_ID)
    await store.fetchResearch(HECATE_BATCH_ID)
    ishtarAnswer.resolve(projectResearch(ISHTAR_BATCH_ID, [ISHTAR_WORTH]))
    await flushPromises()

    expect({ loading: store.loading, error: store.error, rows: store.rows }).toEqual({
      loading: false,
      error: null,
      rows: [OGRE_II_UNKNOWN_COST],
    })
  })
})
