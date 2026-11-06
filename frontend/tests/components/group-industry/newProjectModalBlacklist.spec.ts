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

const PRIVATE_CHECKBOX = '[data-testid="project-private"]'
const OUTSIDE_FAVORITE_CHECKBOX = '[data-testid="project-outside-favorite"]'
const DEFAULT_MINIMUM_OUTSIDE_SAVING = 500_000
const BULK_ITEMS = 'Ferox 2 4 x100\nSabre 4 8 x20'

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
  await button!.trigger('click')
}

async function checkBlacklistCategory(wrapper: VueWrapper, label: string) {
  const category = wrapper.findAll('label').find(candidate => candidate.text() === label)
  expect(category, `category ${label}`).toBeDefined()
  await category!.get('input[type="checkbox"]').setValue(true)
}

async function addItemsByBulkPaste(wrapper: VueWrapper) {
  await clickButton(wrapper, 'Bulk Paste')
  await wrapper.get('textarea').setValue(BULK_ITEMS)
  await clickButton(wrapper, 'Parse & Add')
}

function sentCreationBody(): Record<string, unknown> {
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === '/industry/projects')
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(creationCall![1]!.body))
}

async function createProjectAfterUnchecking(wrapper: VueWrapper) {
  await wrapper.get(PRIVATE_CHECKBOX).setValue(false)
  await clickButton(wrapper, 'Create Project')
  await flushPromises()
}

describe('NewProjectModal production blacklist', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockResolvedValue({ id: 'group-project-created' })
  })

  it('offers no field to blacklist a specific item', () => {
    const wrapper = mountNewProjectModal()

    expect(wrapper.find('input[placeholder="Add specific item..."]').exists()).toBe(false)
    expect(wrapper.find('input[aria-label="Add specific item..."]').exists()).toBe(false)
  })

  it('still lists the ten blacklist categories, unchecked', () => {
    const wrapper = mountNewProjectModal()

    const categories = wrapper.findAll('label').filter(label => label.find('input[type="checkbox"]').exists() && !label.find(PRIVATE_CHECKBOX).exists() && !label.find(OUTSIDE_FAVORITE_CHECKBOX).exists())

    expect(categories.map(label => [label.text(), (label.get('input').element as HTMLInputElement).checked])).toEqual([
      ['Advanced Components', false],
      ['Capital Components', false],
      ['Advanced Capital Components', false],
      ['Hybrid Components', false],
      ['Fuel Blocks', false],
      ['Tools (R.A.M.)', false],
      ['Simple Reactions', false],
      ['Complex Reactions', false],
      ['Hybrid Reactions', false],
      ['Biochemical Reactions', false],
    ])
  })

  it('creates the project with the checked blacklist categories and without blacklisted type ids', async () => {
    const wrapper = mountNewProjectModal()
    await addItemsByBulkPaste(wrapper)
    await checkBlacklistCategory(wrapper, 'Fuel Blocks')
    await checkBlacklistCategory(wrapper, 'Complex Reactions')

    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    const body = sentCreationBody()
    expect(body).toEqual({
      items: [
        { typeId: 0, typeName: 'Ferox', meLevel: 2, teLevel: 4, runs: 100 },
        { typeId: 0, typeName: 'Sabre', meLevel: 4, teLevel: 8, runs: 20 },
      ],
      blacklistCategoryKeys: ['fuel_blocks', 'complex_reactions'],
      private: true,
      acceptsOutsideFavoriteSystem: false,
      minimumOutsideSaving: DEFAULT_MINIMUM_OUTSIDE_SAVING,
    })
    expect(body).not.toHaveProperty('blacklistTypeIds')
  })

  it('creates the project with no blacklist category when none is checked', async () => {
    const wrapper = mountNewProjectModal()
    await addItemsByBulkPaste(wrapper)

    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    const body = sentCreationBody()
    expect(body.blacklistCategoryKeys).toEqual([])
    expect(body).not.toHaveProperty('blacklistTypeIds')
  })
})

describe('NewProjectModal privacy', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockResolvedValue({ id: 'group-project-created' })
  })

  it('sends the project as private when the private checkbox is left untouched', async () => {
    const wrapper = mountNewProjectModal()
    await addItemsByBulkPaste(wrapper)

    expect((wrapper.get(PRIVATE_CHECKBOX).element as HTMLInputElement).checked).toBe(true)
    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    expect(sentCreationBody().private).toBe(true)
  })

  it('sends the project as not private when the private checkbox is unchecked', async () => {
    const wrapper = mountNewProjectModal()
    await addItemsByBulkPaste(wrapper)

    await createProjectAfterUnchecking(wrapper)

    expect(sentCreationBody().private).toBe(false)
  })
})
