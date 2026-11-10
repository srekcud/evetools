import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE = { typeId: 22456, typeName: 'Sabre' }
const CREATED_PROJECT = { id: 'project-348', name: 'Sabre batch', status: 'draft', shortLinkCode: 'S4B7RQ' }
const CREATE_PROJECT_LABEL = 'Create Project'
const FAILURE_MESSAGE = 'Creation refused'

async function mountModal() {
  setActivePinia(createPinia())
  const wrapper = mount(NewProjectModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { teleport: true } },
    props: { show: false, prefill: SABRE },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

async function clickCreate(wrapper: Awaited<ReturnType<typeof mountModal>>): Promise<void> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === CREATE_PROJECT_LABEL)
  if (!button) throw new Error(`No button named ${CREATE_PROJECT_LABEL}`)
  await button.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('NewProjectModal created event', () => {
  it('emits created with the project returned by the creation request', async () => {
    vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: { method?: string }) => {
      if (endpoint === '/industry/projects' && options?.method === 'POST') return CREATED_PROJECT
      return { id: 'project-references', rows: [] }
    }) as never)
    const wrapper = await mountModal()

    await clickCreate(wrapper)

    expect(wrapper.emitted('created')).toEqual([[CREATED_PROJECT]])
  })

  it('emits nothing and shows the failure when the creation request fails', async () => {
    vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: { method?: string }) => {
      if (endpoint === '/industry/projects' && options?.method === 'POST') throw new Error(FAILURE_MESSAGE)
      return { id: 'project-references', rows: [] }
    }) as never)
    const wrapper = await mountModal()

    await clickCreate(wrapper)

    expect(wrapper.emitted('created')).toBeUndefined()
    expect(wrapper.text()).toContain(FAILURE_MESSAGE)
  })
})
