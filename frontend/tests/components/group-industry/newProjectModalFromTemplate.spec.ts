import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import { HAMMERHEAD_LINE_FROM_ALICE } from '../../projectTemplateFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TEMPLATES_ENDPOINT = '/industry/project-templates'
const PROJECTS_ENDPOINT = '/industry/projects'

describe('NewProjectModal from a template', () => {
  it('creates the project with the items, efficiencies, runs, blacklist and options of the used template', async () => {
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockImplementation(async endpoint => (
      endpoint === TEMPLATES_ENDPOINT ? [HAMMERHEAD_LINE_FROM_ALICE] : { id: 'group-project-created' }
    ))
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(NewProjectModal, {
      global: { plugins: [i18n], stubs: { teleport: true } },
      props: { show: true },
    })

    await wrapper.get('[data-testid="new-project-template-tab"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="project-template-use"]').trigger('click')
    const createButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project')
    expect(createButton, 'create button').toBeDefined()
    await createButton!.trigger('click')
    await flushPromises()

    const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
    expect(creationCall, 'project creation request').toBeDefined()
    expect(JSON.parse(String(creationCall![1]!.body))).toMatchObject({
      items: HAMMERHEAD_LINE_FROM_ALICE.items,
      blacklistGroupIds: [18],
      blacklistTypeIds: [34],
      acceptsOutsideFavoriteSystem: true,
      minimumOutsideSaving: 5_000_000,
    })
  })
})
