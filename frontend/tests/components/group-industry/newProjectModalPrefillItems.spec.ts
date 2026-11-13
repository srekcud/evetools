import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import type { ProjectPrefillItem } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DRAKE: ProjectPrefillItem = { typeId: 24698, typeName: 'Drake', meLevel: 10, teLevel: 20, runs: 1 }
const HAMMERHEAD: ProjectPrefillItem = { typeId: 2185, typeName: 'Hammerhead II', meLevel: 2, teLevel: 4, runs: 50 }
const SABRE = { typeId: 22456, typeName: 'Sabre' }
const CREATE_PROJECT_LABEL = 'Create Project'

async function mountModal(props: { prefill?: typeof SABRE; prefillItems?: ProjectPrefillItem[] }) {
  setActivePinia(createPinia())
  const wrapper = mount(NewProjectModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: { show: false, ...props },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

async function createdItems(wrapper: Awaited<ReturnType<typeof mountModal>>): Promise<ProjectPrefillItem[]> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === CREATE_PROJECT_LABEL)
  if (!button) throw new Error(`No button named ${CREATE_PROJECT_LABEL}`)
  await button.trigger('click')
  await flushPromises()
  const call = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === '/industry/projects')
  if (!call) throw new Error('No project creation request')
  return (JSON.parse(String(call[1]?.body)) as { items: ProjectPrefillItem[] }).items
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => Promise.resolve(
    endpoint === '/industry/project-references' ? { rows: [] } : { id: 'project-903', status: 'draft' },
  ))
})

describe('NewProjectModal pre-filled with several items (issue 903)', () => {
  it('lists one line per pre-filled item with its efficiencies and runs', async () => {
    const wrapper = await mountModal({ prefillItems: [DRAKE, HAMMERHEAD] })

    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(await createdItems(wrapper)).toEqual([DRAKE, HAMMERHEAD])
  })

  it('keeps the single-item pre-fill working', async () => {
    const wrapper = await mountModal({ prefill: SABRE })

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })
})
