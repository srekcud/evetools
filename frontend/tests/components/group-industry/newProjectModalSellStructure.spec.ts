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
const REFERENCES_ENDPOINT = '/industry/project-references'
const SETTINGS_ENDPOINT = '/me/settings'
const PROJECTS_ENDPOINT = '/industry/projects'
const SELL_STRUCTURE_SELECT = '[data-testid="sell-structure-select"]'

const JITA_4_4 = { id: 60_003_760, name: 'Jita 4-4' }
const KEEPSTAR_A = { id: 1_042_000_000_001, name: 'Keepstar A' }
const SABRE_PREFILL = { typeId: 22456, typeName: 'Sabre' }

type Settings = {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: { id: number; name: string }[]
}

const SETTINGS_WITH_KEEPSTAR: Settings = {
  preferredMarketStructureId: JITA_4_4.id,
  preferredMarketStructureName: JITA_4_4.name,
  marketStructures: [JITA_4_4, KEEPSTAR_A],
}
const SETTINGS_WITH_JITA_ONLY: Settings = { ...SETTINGS_WITH_KEEPSTAR, marketStructures: [JITA_4_4] }
const SETTINGS_WITHOUT_STRUCTURE: Settings = { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] }

type Wrapper = Awaited<ReturnType<typeof mountModal>>

async function mountModal(settings: Settings) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === SETTINGS_ENDPOINT) return settings
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

async function useTheTemplateOfAlice(wrapper: Wrapper) {
  await wrapper.get('[data-testid="new-project-template-tab"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="project-template-use"]').trigger('click')
  await flushPromises()
}

async function submitAndReadCreationBody(wrapper: Wrapper) {
  const createButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project')
  if (createButton === undefined) throw new Error('no create button')
  await createButton.trigger('click')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
  if (creationCall === undefined) throw new Error('no project creation request')
  return JSON.parse(String(creationCall[1]?.body)) as Record<string, unknown>
}

describe('NewProjectModal sell structure', () => {
  it('creates the project on the preferred market structure of the user by default', async () => {
    const wrapper = await mountModal(SETTINGS_WITH_KEEPSTAR)

    const body = await submitAndReadCreationBody(wrapper)

    expect(body.sellStructureId).toBe(60_003_760)
  })

  it('creates the project on the structure the user picked instead of the preferred one', async () => {
    const wrapper = await mountModal(SETTINGS_WITH_KEEPSTAR)

    await wrapper.get(SELL_STRUCTURE_SELECT).setValue(String(KEEPSTAR_A.id))
    const body = await submitAndReadCreationBody(wrapper)

    expect(body.sellStructureId).toBe(1_042_000_000_001)
  })

  it('selects the sell structure of a used template when it is one of the structures of the user', async () => {
    const wrapper = await mountModal(SETTINGS_WITH_KEEPSTAR)

    await useTheTemplateOfAlice(wrapper)
    const body = await submitAndReadCreationBody(wrapper)

    expect(body.templateId).toBe('template-hammerhead-line')
    expect(body.sellStructureId).toBe(1_042_000_000_001)
  })

  it('keeps the preferred structure selected when the sell structure of a used template is not one of the structures of the user', async () => {
    const wrapper = await mountModal(SETTINGS_WITH_JITA_ONLY)

    await useTheTemplateOfAlice(wrapper)
    const body = await submitAndReadCreationBody(wrapper)

    expect(body.templateId).toBe('template-hammerhead-line')
    expect(body.sellStructureId).toBe(60_003_760)
  })

  it('sends no sellStructureId when the user has no market structure and still allows the creation', async () => {
    const wrapper = await mountModal(SETTINGS_WITHOUT_STRUCTURE)

    const body = await submitAndReadCreationBody(wrapper)

    expect(body).not.toHaveProperty('sellStructureId')
    expect(wrapper.get('[data-testid="sell-structure-none"]').text()).toBe('No market structure configured')
  })
})
