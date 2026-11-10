import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import GroupIndustryJoin from '@/views/GroupIndustry/GroupIndustryJoin.vue'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import { apiRequest } from '@/services/api'
import { cockpitOf, mountCockpitDetail, nextAction, nyxProject, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const stubs = {
  MainLayout: { template: '<div><slot /></div>' },
  IndustryModuleTabs: true,
  teleport: true,
  LoadingSpinner: true,
  ProjectKpiCards: true,
  LineRentalModal: true,
  AvailableProjectCard: true,
  ProjectCard: true,
  NewProjectModal: true,
}

function activeModulePage(wrapper: VueWrapper): unknown {
  const moduleTabs = wrapper.findComponent(IndustryModuleTabs)
  return moduleTabs.exists() ? moduleTabs.props('active') : 'no module tabs mounted'
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  route.params = { id: 'project-nyx-batch', code: 'K7Q2MX' }
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async () => []) as never)
})

describe('industry module tabs on the project pages', () => {
  it('marks Projects as the active page on the projects list', async () => {
    const wrapper = mount(GroupIndustryList, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs },
    })
    await flushPromises()

    expect(activeModulePage(wrapper)).toBe('projects')
  })

  it('marks Projects as the active page on a project detail', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      cockpit: cockpitOf('in_progress', nextAction({ code: 'nothing_to_do' })),
    })

    expect(activeModulePage(wrapper)).toBe('projects')
  })

  it('shows no module tabs on the invitation page', async () => {
    vi.mocked(apiRequest).mockImplementation((async () => ({ id: 'project-219', myStatus: 'pending' })) as never)

    const wrapper = mount(GroupIndustryJoin, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs },
    })
    await flushPromises()

    expect(wrapper.findComponent(IndustryModuleTabs).exists()).toBe(false)
  })
})
