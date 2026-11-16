import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
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
const REFERENCES_ENDPOINT = '/industry/project-references'
const CHARACTERS_ENDPOINT = '/me/characters'
const PROJECTS_ENDPOINT = '/industry/projects'
const SABRE_PREFILL = { typeId: 22456, typeName: 'Sabre' }
const ARIA = { id: 'uuid-aria', eveCharacterId: 2_112_000_001, name: 'Aria Solberg' }
const BRIN = { id: 'uuid-brin', eveCharacterId: 2_112_000_002, name: 'Brin Solberg' }

async function mountModal(characters: unknown) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === CHARACTERS_ENDPOINT) return characters
    if (endpoint === TEMPLATES_ENDPOINT) return [HAMMERHEAD_LINE_FROM_ALICE]
    if (endpoint === REFERENCES_ENDPOINT) return { id: 'project-references', rows: [] }
    return { id: 'group-project-created' }
  })
  const wrapper = mount(NewProjectModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: { show: false, prefill: SABRE_PREFILL },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountModal>>

async function submitAndReadCreationBody(wrapper: Wrapper) {
  const createButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project')
  if (createButton === undefined) throw new Error('no create button')
  await createButton.trigger('click')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
  if (creationCall === undefined) throw new Error('no project creation request')
  return JSON.parse(String(creationCall[1]?.body)) as Record<string, unknown>
}

enableAutoUnmount(afterEach)

describe('NewProjectModal producer characters', () => {
  it('shows one ticked checkbox per character of the user', async () => {
    const wrapper = await mountModal([ARIA, BRIN])

    const boxes = wrapper.findAll('[data-testid^="producer-character-"]').filter(candidate => candidate.attributes('type') === 'checkbox')

    expect(boxes.map(candidate => (candidate.element as HTMLInputElement).checked)).toEqual([true, true])
  })

  it('leaves producerCharacterIds out of the creation when every character stays ticked', async () => {
    const wrapper = await mountModal([ARIA, BRIN])

    const body = await submitAndReadCreationBody(wrapper)

    expect(body).not.toHaveProperty('producerCharacterIds')
  })

  it('creates the project with the ticked characters only', async () => {
    const wrapper = await mountModal([ARIA, BRIN])

    await wrapper.get(`[data-testid="producer-character-${BRIN.eveCharacterId}"]`).setValue(false)
    const body = await submitAndReadCreationBody(wrapper)

    expect(body.producerCharacterIds).toEqual([2_112_000_001])
  })

  it('still creates the project without a selection when the characters cannot be loaded', async () => {
    const wrapper = await mountModal({ id: 'not-a-character-list' })

    const body = await submitAndReadCreationBody(wrapper)

    expect(body).not.toHaveProperty('producerCharacterIds')
    expect(wrapper.find('[data-testid="producer-characters-load-failed"]').exists()).toBe(true)
  })
})
