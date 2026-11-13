import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectTemplatesStore } from '@/stores/group-industry/templates'
import { HAMMERHEAD_LINE_FROM_ALICE, SABRE_LINE_OF_BOB } from '../projectTemplateFixtures'
import { present } from '../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TEMPLATES_PATH = '/industry/project-templates'
const HAMMERHEAD_BATCH_ID = 'project-hammerhead-batch'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('project templates store', () => {
  it('reads the visible templates of the user in the order of the server', async () => {
    vi.mocked(apiRequest).mockResolvedValue([SABRE_LINE_OF_BOB, HAMMERHEAD_LINE_FROM_ALICE])
    const store = useProjectTemplatesStore()

    await store.fetchTemplates()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(TEMPLATES_PATH)
    expect({ loading: store.loading, error: store.error, templates: store.templates }).toEqual({
      loading: false,
      error: null,
      templates: [SABRE_LINE_OF_BOB, HAMMERHEAD_LINE_FROM_ALICE],
    })
  })

  it('posts the project, the name and the sharing and puts the created template first in the list', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([HAMMERHEAD_LINE_FROM_ALICE])
    const store = useProjectTemplatesStore()
    await store.fetchTemplates()
    vi.mocked(apiRequest).mockResolvedValueOnce(SABRE_LINE_OF_BOB)

    const created = await store.saveTemplate({ projectId: HAMMERHEAD_BATCH_ID, name: 'Sabre line', sharedWithCorporation: false })

    expect(created).toEqual(SABRE_LINE_OF_BOB)
    const [endpoint, options] = present(vi.mocked(apiRequest).mock.calls[1])
    expect(endpoint).toBe(TEMPLATES_PATH)
    expect(present(options).method).toBe('POST')
    expect(JSON.parse(String(present(options).body))).toEqual({ projectId: HAMMERHEAD_BATCH_ID, name: 'Sabre line', sharedWithCorporation: false })
    expect(store.templates).toEqual([SABRE_LINE_OF_BOB, HAMMERHEAD_LINE_FROM_ALICE])
  })

  it('exposes the API error instead of a silently empty list when the templates cannot be read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Templates unavailable'))
    const store = useProjectTemplatesStore()

    await store.fetchTemplates()

    expect({ loading: store.loading, error: store.error, templates: store.templates }).toEqual({
      loading: false,
      error: 'Templates unavailable',
      templates: [],
    })
  })
})
