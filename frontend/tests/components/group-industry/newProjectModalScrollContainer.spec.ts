import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import { tablesInScrollContainer } from '../tableScrollContainer'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BULK_CATALOG = [
  { typeId: 16227, typeName: 'Ferox', isT2: false },
  { typeId: 22456, typeName: 'Sabre', isT2: false },
]

const newProjectLabels = en.groupIndustry.modals.newProject
const BULK_ITEMS = 'Ferox 2 4 x100\nSabre 4 8 x20'

function mountNewProjectModal() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint.startsWith('/industry/search')) {
      const query = decodeURIComponent(endpoint.split('q=')[1]).toLowerCase()
      return { results: BULK_CATALOG.filter(product => product.typeName.toLowerCase() === query) }
    }
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
  await button!.trigger('click')
}

async function mountWithItemsAndLineRentalOverride() {
  const wrapper = mountNewProjectModal()
  await clickButton(wrapper, newProjectLabels.bulkPaste)
  await wrapper.get('textarea').setValue(BULK_ITEMS)
  await clickButton(wrapper, newProjectLabels.parseAndAdd)
  await flushPromises()
  await clickButton(wrapper, newProjectLabels.overrideForProject)
  return wrapper
}

describe('NewProjectModal on a narrow screen', () => {
  it('puts the items table and the line rental override table in horizontally scrollable containers', async () => {
    const wrapper = await mountWithItemsAndLineRentalOverride()

    expect(tablesInScrollContainer(wrapper)).toEqual([true, true])
  })
})
