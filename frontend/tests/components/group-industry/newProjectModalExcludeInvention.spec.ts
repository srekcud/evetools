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

const EXCLUDE_INVENTION_CHECKBOX = '[data-testid="project-exclude-invention"]'

function mountNewProjectModal(settingsExcludeInvention: boolean) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === '/industry/blacklist') return { categories: [], groupIds: [], items: [], excludeInvention: settingsExcludeInvention }
    if (endpoint.startsWith('/industry/search')) return { results: [{ typeId: 22456, typeName: 'Sabre', isT2: false }] }
    return { id: 'group-project-created' }
  })
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

async function createSabreProject(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  await clickButton(wrapper, 'Bulk Paste')
  await wrapper.get('textarea').setValue('Sabre 4 8 x20')
  await clickButton(wrapper, 'Parse & Add')
  await flushPromises()
  await clickButton(wrapper, 'Create Project')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === '/industry/projects')
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(present(present(creationCall)[1]).body))
}

function isChecked(wrapper: VueWrapper): boolean | 'absent' {
  const box = wrapper.find(EXCLUDE_INVENTION_CHECKBOX)
  return box.exists() ? (box.element as HTMLInputElement).checked : 'absent'
}

describe('NewProjectModal invention exclusion (issue 806)', () => {
  it('checks the option when the industry settings exclude the invention and sends true', async () => {
    const wrapper = mountNewProjectModal(true)
    await flushPromises()

    expect(isChecked(wrapper)).toBe(true)
    const body = await createSabreProject(wrapper)

    expect(body.excludesInvention).toBe(true)
  })

  it('leaves the option unchecked otherwise, sends false explicitly, and sends true once the user checks it', async () => {
    const unchecked = mountNewProjectModal(false)
    await flushPromises()
    expect(isChecked(unchecked)).toBe(false)
    const explicitFalse = await createSabreProject(unchecked)

    const checked = mountNewProjectModal(false)
    await flushPromises()
    if (checked.find(EXCLUDE_INVENTION_CHECKBOX).exists()) await checked.get(EXCLUDE_INVENTION_CHECKBOX).setValue(true)
    const explicitTrue = await createSabreProject(checked)

    expect([explicitFalse.excludesInvention, explicitTrue.excludesInvention]).toEqual([false, true])
  })
})
