import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import { apiRequest } from '@/services/api'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })
const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CREATE_PROJECT_LABEL = 'Create Project'
const PROJECTS_ENDPOINT = '/industry/projects'
const REFERENCES_ENDPOINT = '/industry/project-references'
const CREATED_PROJECT_ID = 'project-348'
const SABRE_PREFILL = { typeId: 22456, typeName: 'Sabre', runs: 5, me: 10, te: 20 }

async function createSabreProject(): Promise<VueWrapper> {
  useIndustryStore().navigationIntent = { target: 'projects', prefill: SABRE_PREFILL }
  const wrapper = mount(GroupIndustryList, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        LoadingSpinner: true,
        ProjectKpiCards: true,
        LineRentalModal: true,
        AvailableProjectCard: true,
        ProjectCard: true,
      },
    },
  })
  await flushPromises()
  const createButton = wrapper.findAll('button').find(candidate => candidate.text() === CREATE_PROJECT_LABEL)
  if (!createButton) throw new Error(`No button named ${CREATE_PROJECT_LABEL}`)
  await createButton.trigger('click')
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  push.mockReset()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: { method?: string }) => {
    if (endpoint === PROJECTS_ENDPOINT && options?.method === 'POST') return { id: CREATED_PROJECT_ID }
    if (endpoint === REFERENCES_ENDPOINT) return { id: 'project-references', rows: [] }
    return []
  }) as never)
})

describe('GroupIndustryList once a project is created from the modal', () => {
  it('opens the created project', async () => {
    await createSabreProject()

    expect(push.mock.calls).toEqual([[{ name: 'industry-project', params: { id: CREATED_PROJECT_ID } }]])
  })
})
