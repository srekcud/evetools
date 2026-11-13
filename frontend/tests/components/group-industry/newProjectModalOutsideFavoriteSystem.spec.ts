import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OUTSIDE_FAVORITE_CHECKBOX = '[data-testid="project-outside-favorite"]'
const MINIMUM_SAVING_INPUT = '[data-testid="project-minimum-outside-saving"]'
const DEFAULT_MINIMUM_OUTSIDE_SAVING = 500_000
const ENTERED_MINIMUM_OUTSIDE_SAVING = 750_000

function mountNewProjectModal() {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

async function clickButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await present(button).trigger('click')
}

async function addSabreByBulkPaste(wrapper: VueWrapper) {
  await clickButton(wrapper, 'Bulk Paste')
  await wrapper.get('textarea').setValue('Sabre 4 8 x20')
  await clickButton(wrapper, 'Parse & Add')
  await flushPromises()
}

async function createProject(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  await clickButton(wrapper, 'Create Project')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === '/industry/projects')
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(present(present(creationCall)[1]).body))
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint.startsWith('/industry/search')) return { results: [{ typeId: 22456, typeName: 'Sabre', isT2: false }] }
    return { id: 'group-project-created' }
  })
})

describe('NewProjectModal outside favorite systems', () => {
  it('sends the checked outside favorite systems option with the entered minimum saving', async () => {
    const wrapper = mountNewProjectModal()
    await addSabreByBulkPaste(wrapper)

    await wrapper.get(OUTSIDE_FAVORITE_CHECKBOX).setValue(true)
    expect((wrapper.get(MINIMUM_SAVING_INPUT).element as HTMLInputElement).disabled).toBe(false)
    await wrapper.get(MINIMUM_SAVING_INPUT).setValue(String(ENTERED_MINIMUM_OUTSIDE_SAVING))
    const body = await createProject(wrapper)

    expect(body).toMatchObject({ acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: ENTERED_MINIMUM_OUTSIDE_SAVING })
  })

  it('disables the minimum saving while the option is unchecked and sends false with the default minimum saving', async () => {
    const wrapper = mountNewProjectModal()
    await addSabreByBulkPaste(wrapper)

    expect((wrapper.get(OUTSIDE_FAVORITE_CHECKBOX).element as HTMLInputElement).checked).toBe(false)
    expect((wrapper.get(MINIMUM_SAVING_INPUT).element as HTMLInputElement).disabled).toBe(true)
    const body = await createProject(wrapper)

    expect(body).toMatchObject({ acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: DEFAULT_MINIMUM_OUTSIDE_SAVING })
  })
})
