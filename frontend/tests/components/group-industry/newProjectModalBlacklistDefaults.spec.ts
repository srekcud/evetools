import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECTS_ENDPOINT = '/industry/projects'
const BLACKLIST_ENDPOINT = '/industry/blacklist'
const FEROX = { typeId: 16227, typeName: 'Ferox', isT2: false }

const SETTINGS_CATEGORIES = [
  { key: 'advanced_components', label: 'Advanced Components', groupIds: [334], blacklisted: false },
  { key: 'fuel_blocks', label: 'Fuel Blocks', groupIds: [1136], blacklisted: true },
  { key: 'complex_reactions', label: 'Composite Reactions', groupIds: [484], blacklisted: true },
  { key: 'tools', label: 'Tools (R.A.M.)', groupIds: [332], blacklisted: false },
]

function mountModal(categories = SETTINGS_CATEGORIES) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === BLACKLIST_ENDPOINT) return { categories, items: [] }
    if (endpoint.startsWith('/industry/search')) return { results: [FEROX] }
    return { id: 'group-project-created' }
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

function categoryLabel(wrapper: VueWrapper, label: string) {
  const category = wrapper.findAll('label').find(candidate => candidate.text() === label)
  expect(category, `category ${label}`).toBeDefined()
  return category!
}

function isChecked(wrapper: VueWrapper, label: string): boolean {
  return (categoryLabel(wrapper, label).get('input[type="checkbox"]').element as HTMLInputElement).checked
}

async function createWithFerox(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  const bulkButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Bulk Paste')
  await bulkButton!.trigger('click')
  await wrapper.get('textarea').setValue('Ferox 2 4 x100')
  await wrapper.findAll('button').find(candidate => candidate.text() === 'Parse & Add')!.trigger('click')
  await flushPromises()
  await wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project')!.trigger('click')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(creationCall![1]!.body))
}

describe('NewProjectModal blacklist defaults from the industry settings', () => {
  it('pre-checks the categories blacklisted in the industry settings and leaves the others unchecked', async () => {
    const wrapper = mountModal()
    await flushPromises()

    expect(isChecked(wrapper, 'Fuel Blocks')).toBe(true)
    expect(isChecked(wrapper, 'Composite Reactions')).toBe(true)
    expect(isChecked(wrapper, 'Advanced Components')).toBe(false)
    expect(isChecked(wrapper, 'Tools (R.A.M.)')).toBe(false)
  })

  it('creates the project with the pre-checked categories', async () => {
    const wrapper = mountModal()
    await flushPromises()

    const body = await createWithFerox(wrapper)

    expect(body.blacklistCategoryKeys).toEqual(['fuel_blocks', 'complex_reactions'])
  })

  it('creates the project without a category the user unchecked', async () => {
    const wrapper = mountModal()
    await flushPromises()
    await categoryLabel(wrapper, 'Fuel Blocks').get('input[type="checkbox"]').setValue(false)

    const body = await createWithFerox(wrapper)

    expect(body.blacklistCategoryKeys).toEqual(['complex_reactions'])
  })

  it('keeps the choice of the user when the settings arrive after it', async () => {
    let releaseSettings: (value: unknown) => void = () => undefined
    setActivePinia(createPinia())
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockImplementation(async endpoint => {
      if (endpoint === BLACKLIST_ENDPOINT) {
        return new Promise(resolve => { releaseSettings = resolve })
      }
      return { id: 'group-project-created' }
    })
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(NewProjectModal, {
      global: { plugins: [i18n], stubs: { teleport: true } },
      props: { show: true },
    })
    await flushPromises()
    await categoryLabel(wrapper, 'Tools (R.A.M.)').get('input[type="checkbox"]').setValue(true)

    releaseSettings({ categories: SETTINGS_CATEGORIES, items: [] })
    await flushPromises()

    expect(isChecked(wrapper, 'Tools (R.A.M.)')).toBe(true)
    expect(isChecked(wrapper, 'Fuel Blocks')).toBe(false)
  })

  it('names the reactions category like the server does', async () => {
    const wrapper = mountModal()
    await flushPromises()

    const labels = wrapper.findAll('label').map(label => label.text())

    expect(labels).toContain('Composite Reactions')
    expect(labels).not.toContain('Complex Reactions')
  })

  it('offers no broker fee nor sales tax field at creation', async () => {
    const wrapper = mountModal()
    await flushPromises()

    expect(wrapper.text()).not.toMatch(/broker fee|sales tax/i)
  })
})
