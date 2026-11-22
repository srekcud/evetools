import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject, ProjectOverview } from '@/stores/group-industry/types'

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

const OVERVIEW_URL = '/industry/projects/overview'

const ISHTAR_PROJECT = {
  id: 'project-ishtar',
  name: 'Ishtar batch',
  status: 'in_progress',
  items: [{ typeId: 12005, typeName: 'Ishtar', meLevel: 10, teLevel: 20, runs: 100 }],
  ownerCharacterName: 'Aria Solberg',
  myRole: 'owner',
  myStatus: 'accepted',
} as unknown as GroupProject

const ISHTAR_OVERVIEW: ProjectOverview = {
  projectId: 'project-ishtar',
  myRole: 'owner',
  ownerName: 'Aria Solberg',
  team: [{ name: 'Brann Holt', role: 'member' }],
  flow: { toBring: 4, onSite: 2, inProduction: 6, out: 1, done: 0 },
  flowReason: null,
  flowUnknown: 0,
  awaited: [{ name: 'Brann Holt', mine: false, count: 2 }],
}

function answer(overview: () => Promise<ProjectOverview[]>): void {
  vi.mocked(apiRequest).mockImplementation((async (url: string) => {
    if (url === '/industry/projects') return [ISHTAR_PROJECT]
    if (url === OVERVIEW_URL) return overview()
    return []
  }) as never)
}

function mountList(): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(GroupIndustryList, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        LoadingSpinner: true,
        ProjectKpiCards: true,
        UnassignedJobsPanel: true,
        NewProjectModal: true,
        LineRentalModal: true,
        AvailableProjectCard: true,
      },
    },
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  push.mockReset()
  vi.mocked(apiRequest).mockReset()
})

describe('GroupIndustryList my projects overview', () => {
  it('lists my projects as one row each with the flow of the overview', async () => {
    answer(() => Promise.resolve([ISHTAR_OVERVIEW]))

    const wrapper = mountList()
    await flushPromises()

    const row = wrapper.find('tr[data-project="project-ishtar"]')
    expect(row.exists()).toBe(true)
    expect(row.find('[data-column="team"]').text()).toBe('Aria Solberg · Brann Holt')
    expect(row.find('[data-column="inProduction"]').text()).toBe('6')
    expect(row.find('[data-column="awaited"]').text()).toBe('Brann Holt 2')
  })

  it('opens the project of the clicked row', async () => {
    answer(() => Promise.resolve([ISHTAR_OVERVIEW]))

    const wrapper = mountList()
    await flushPromises()
    await wrapper.find('tr[data-project="project-ishtar"]').trigger('click')

    expect(push).toHaveBeenCalledWith({ name: 'industry-project', params: { id: 'project-ishtar' } })
  })

  it('still lists my projects when the overview cannot be loaded', async () => {
    answer(() => Promise.reject(new Error('Service unavailable')))

    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.find('tr[data-project="project-ishtar"]').exists()).toBe(true)
    expect(wrapper.find('[data-column="inProduction"]').text()).toBe('?')
    expect(wrapper.find('[data-column="inProduction"]').attributes('title')).toBe('Service unavailable')
  })
})
