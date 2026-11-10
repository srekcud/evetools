import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'

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

function project(id: string, status: GroupProjectStatus): GroupProject {
  return { id, status, items: [], myStatus: 'accepted', myRole: 'owner' } as unknown as GroupProject
}

const MY_PROJECTS = [
  project('project-ferox-draft', 'draft'),
  project('project-sabre-published', 'published'),
  project('project-hecate-in-progress', 'in_progress'),
  project('project-ishtar-completed', 'completed'),
]

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
        NewProjectModal: true,
        LineRentalModal: true,
        AvailableProjectCard: true,
        ProjectCard: { props: ['project'], template: '<div class="project-card">{{ project.id }}</div>' },
      },
    },
  })
}

function filterPill(wrapper: VueWrapper, label: string) {
  const pill = wrapper.findAll('button.filter-pill').find(candidate => candidate.text().startsWith(label))
  if (pill === undefined) throw new Error(`filter ${label} not found`)
  return pill
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === '/industry/projects' ? MY_PROJECTS : [])) as never)
})

describe('GroupIndustryList status filter', () => {
  it('offers a draft filter that counts and shows only the draft projects', async () => {
    const wrapper = mountList()
    await flushPromises()

    const draftPill = filterPill(wrapper, en.groupIndustry.status.draft)
    expect(draftPill.text()).toBe(`${en.groupIndustry.status.draft} 1`)
    await draftPill.trigger('click')

    expect(wrapper.findAll('.project-card').map(card => card.text())).toEqual(['project-ferox-draft'])
  })
})
