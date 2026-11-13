import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import { HAMMERHEAD_LINE_FROM_ALICE } from '../../projectTemplateFixtures'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TEMPLATES_ENDPOINT = '/industry/project-templates'
const REFERENCES_ENDPOINT = '/industry/project-references'
const PROJECTS_ENDPOINT = '/industry/projects'

const SABRE_PREFILL = { typeId: 22456, typeName: 'Sabre' }

async function mountModal() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === TEMPLATES_ENDPOINT) return [HAMMERHEAD_LINE_FROM_ALICE]
    if (endpoint === REFERENCES_ENDPOINT) return { id: 'project-references', rows: [] }
    return { id: 'group-project-created' }
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

async function useTheTemplateOfAlice(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  await wrapper.get('[data-testid="new-project-template-tab"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="project-template-use"]').trigger('click')
}

async function submitAndReadCreationBody(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  const createButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project')
  expect(createButton, 'create button').toBeDefined()
  await present(createButton).trigger('click')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(present(present(creationCall)[1]).body)) as Record<string, unknown>
}

describe('NewProjectModal from a template', () => {
  it('creates the project with the items, efficiencies, runs, blacklist and options of the used template', async () => {
    const wrapper = await mountModal()

    await useTheTemplateOfAlice(wrapper)
    const body = await submitAndReadCreationBody(wrapper)

    expect(body).toMatchObject({
      items: HAMMERHEAD_LINE_FROM_ALICE.items,
      blacklistGroupIds: [18],
      blacklistTypeIds: [34],
      acceptsOutsideFavoriteSystem: true,
      minimumOutsideSaving: 5_000_000,
    })
  })

  it('sends the id of the used template as templateId when the project is created', async () => {
    const wrapper = await mountModal()

    await useTheTemplateOfAlice(wrapper)
    const body = await submitAndReadCreationBody(wrapper)

    expect(body.templateId).toBe('template-hammerhead-line')
  })

  it('sends no templateId when the modal is reopened after a template was used', async () => {
    const wrapper = await mountModal()
    await useTheTemplateOfAlice(wrapper)

    await wrapper.setProps({ show: false })
    await wrapper.setProps({ show: true, prefill: SABRE_PREFILL })
    const body = await submitAndReadCreationBody(wrapper)

    expect(body.items).toMatchObject([{ typeId: 22456, typeName: 'Sabre' }])
    expect(body).not.toHaveProperty('templateId')
  })
})
