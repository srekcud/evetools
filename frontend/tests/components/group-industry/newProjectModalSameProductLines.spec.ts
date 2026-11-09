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

const SABRE = { typeId: 22456, typeName: 'Sabre', isT2: false }
const ITEM_ROWS = 'tbody tr'
const REQUESTED_LINES = 20

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

async function addSabreFromTheSearch(wrapper: VueWrapper) {
  const vm = wrapper.vm as unknown as { selectSearchResult: (result: typeof SABRE) => void }
  vm.selectSearchResult(SABRE)
  await wrapper.vm.$nextTick()
  await clickButton(wrapper, 'Add to list')
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({ id: 'group-project-created' })
})

describe('NewProjectModal lines of the same product', () => {
  it('keeps a second line of the same product added from the search', async () => {
    const wrapper = mountNewProjectModal()

    await addSabreFromTheSearch(wrapper)
    await addSabreFromTheSearch(wrapper)

    expect(wrapper.findAll(ITEM_ROWS)).toHaveLength(2)
  })

  it('sends every line of the same product in the creation request', async () => {
    const wrapper = mountNewProjectModal()

    for (let line = 0; line < REQUESTED_LINES; line++) {
      await addSabreFromTheSearch(wrapper)
    }
    await clickButton(wrapper, 'Create Project')
    await flushPromises()

    const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === '/industry/projects')
    expect(creationCall, 'project creation request').toBeDefined()
    const body = JSON.parse(String(creationCall![1]!.body)) as { items: Array<{ typeId: number }> }
    expect(body.items.map(item => item.typeId)).toEqual(Array(REQUESTED_LINES).fill(SABRE.typeId))
  })
})
