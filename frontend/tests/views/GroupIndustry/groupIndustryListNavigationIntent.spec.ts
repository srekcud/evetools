import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import { apiRequest } from '@/services/api'
import { useIndustryStore } from '@/stores/industry'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CREATE_PROJECT_LABEL = 'Create Project'
const PROJECTS_ENDPOINT = '/industry/projects'
const REFERENCES_ENDPOINT = '/industry/project-references'

const SABRE_PREFILL = {
  typeId: 22456,
  typeName: 'Sabre',
  runs: 5,
  me: 10,
  te: 20,
  excludedTypeIds: [11558],
}

function mountList(): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(GroupIndustryList, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        LoadingSpinner: true,
        ProjectKpiCards: true,
        LineRentalModal: true,
        AvailableProjectCard: true,
        ProjectCard: true,
      },
    },
  })
}

function createButton(wrapper: VueWrapper) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === CREATE_PROJECT_LABEL)
  if (!button) throw new Error(`No button named ${CREATE_PROJECT_LABEL}`)
  return button
}

function sentCreationBody(): Record<string, unknown> {
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint, options]) => endpoint === PROJECTS_ENDPOINT && options?.method === 'POST')
  if (!creationCall) throw new Error('No project creation request')
  return JSON.parse(String(creationCall[1]?.body))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (url: string, options?: { method?: string }) => {
    if (url === PROJECTS_ENDPOINT && options?.method === 'POST') return { id: 'created-project' }
    if (url === REFERENCES_ENDPOINT) return { id: 'project-references', rows: [] }
    return []
  }) as never)
})

describe('GroupIndustryList navigation intent', () => {
  it('opens the project creation with the product of a prefill and its excluded types, then consumes the intent', async () => {
    const industryStore = useIndustryStore()
    industryStore.navigationIntent = { target: 'projects', prefill: SABRE_PREFILL }

    const wrapper = mountList()
    await flushPromises()

    expect(industryStore.navigationIntent).toBeNull()
    expect(createButton(wrapper).attributes('disabled')).toBeUndefined()
    await createButton(wrapper).trigger('click')
    await flushPromises()
    expect(sentCreationBody()).toMatchObject({
      private: true,
      items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 5 }],
      blacklistTypeIds: [11558],
    })
  })

  it('opens an empty project creation when the intent only asks for the creation modal', async () => {
    const industryStore = useIndustryStore()
    industryStore.navigationIntent = { target: 'projects', openCreateModal: true }

    const wrapper = mountList()
    await flushPromises()

    expect(industryStore.navigationIntent).toBeNull()
    expect(createButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('gives an invented blueprint the T2 time efficiency when the prefill has none', async () => {
    const industryStore = useIndustryStore()
    industryStore.navigationIntent = { target: 'projects', prefill: { typeId: 12005, typeName: 'Ishtar', isT2: true } }

    const wrapper = mountList()
    await flushPromises()
    await createButton(wrapper).trigger('click')
    await flushPromises()

    expect(sentCreationBody()).toMatchObject({
      items: [{ typeId: 12005, typeName: 'Ishtar', meLevel: 0, teLevel: 4, runs: 1 }],
    })
  })
})
