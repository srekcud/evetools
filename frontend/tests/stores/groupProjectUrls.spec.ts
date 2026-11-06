import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const JOIN_CODE = 'K7M2QX'

const PROJECTS_URL = '/industry/projects'
const AVAILABLE_PROJECTS_URL = '/industry/projects/available'
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const JOIN_URL = `/industry/projects/join/${JOIN_CODE}`

const ISHTAR_BATCH = { id: PROJECT_ID, name: 'Ishtar batch', status: 'draft', private: true } as unknown as GroupProject
const TRITANIUM_LINE = { id: 'line-tritanium', typeId: 34, typeName: 'Tritanium', isJob: false } as unknown as BomItem

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group project store API paths', () => {
  it('loads my projects and the available projects from the unified project routes', async () => {
    vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === PROJECTS_URL ? [ISHTAR_BATCH] : [])) as never)
    const store = useGroupProjectStore()

    await store.fetchMyProjects()
    await store.fetchAvailableProjects()

    expect(vi.mocked(apiRequest).mock.calls.map(([url]) => url)).toEqual([PROJECTS_URL, AVAILABLE_PROJECTS_URL])
    expect(store.myProjects).toEqual([ISHTAR_BATCH])
    expect(store.error).toBeNull()
  })

  it('loads the BOM of a project from its unified project route', async () => {
    vi.mocked(apiRequest).mockResolvedValue([TRITANIUM_LINE])
    const store = useGroupProjectStore()

    await store.fetchBom(PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledWith(BOM_URL)
    expect(store.bomItems).toEqual([TRITANIUM_LINE])
  })

  it('joins a project by its short link code on the unified project route', async () => {
    vi.mocked(apiRequest).mockResolvedValue(ISHTAR_BATCH)
    const store = useGroupProjectStore()

    const joined = await store.joinProject(JOIN_CODE)

    expect(apiRequest).toHaveBeenCalledWith(JOIN_URL, { method: 'POST', body: '{}' })
    expect(joined).toEqual(ISHTAR_BATCH)
  })
})
