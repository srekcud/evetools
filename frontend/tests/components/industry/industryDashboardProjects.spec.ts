import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECTS_ENDPOINT = '/industry/projects'

function groupProject(id: string, name: string, status: GroupProjectStatus): GroupProject {
  return {
    id,
    name,
    status,
    shortLinkCode: `link-${id}`,
    containerName: null,
    private: true,
    acceptsOutsideFavoriteSystem: false,
    excludesInvention: false,
    minimumOutsideSaving: 500000,
    sellStructureId: null,
    sellStructureName: null,
    notes: null,
    ownerCharacterName: 'Ilyra Castell',
    ownerCorporationId: 98000001,
    membersCount: 1,
    items: [{ typeId: 22436, typeName: 'Widow', meLevel: 10, teLevel: 20, runs: 4 }],
    totalBomValue: null,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    brokerFeePercent: null,
    salesTaxPercent: null,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-01T10:00:00+00:00',
    myRole: 'owner',
    myStatus: 'accepted',
  }
}

const GROUP_PROJECTS = [
  groupProject('widow-draft', 'Widow draft', 'draft'),
  groupProject('widow-in-progress', 'Widow in progress', 'in_progress'),
  groupProject('widow-completed', 'Widow completed', 'completed'),
]

function projectsRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/industry', name: 'industry', component: { template: '<div />' } },
      { path: '/industry/projects', name: 'industry-projects', component: { template: '<div />' } },
    ],
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) =>
    endpoint === PROJECTS_ENDPOINT ? GROUP_PROJECTS : new Promise(() => {})) as never)
})

describe('IndustryDashboard projects', () => {
  it('no longer shows the active projects, slots used and pipeline health cards that the workshop KPI cards replace', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n, projectsRouter()] } })
    await flushPromises()

    const text = wrapper.text()
    expect(['Active Projects', 'Slots Used', 'Pipeline Health'].filter(label => text.includes(label))).toStrictEqual([])
    expect(text).not.toContain('Widow in progress')
    expect(text).not.toContain('Widow completed')
  })

  it('keeps the alerts and the quick actions', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n, projectsRouter()] } })
    await flushPromises()

    expect(wrapper.text()).toContain(en.industry.dashboard.alertsTitle)
    expect(wrapper.text()).toContain(en.industry.dashboard.newProject)
    expect(wrapper.text()).toContain(en.industry.dashboard.scanProfits)
    expect(wrapper.text()).toContain(en.industry.dashboard.viewStockpile)
  })

  it('links to the projects page from the view all projects link', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n, projectsRouter()] } })
    await flushPromises()

    const viewAllLink = wrapper.findAll('a').find(link => link.text() === en.industry.dashboard.viewAllProjects)
    expect(viewAllLink?.attributes('href')).toBe('/industry/projects')
  })

  it('shows the failure message when the project list cannot be loaded', async () => {
    vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
      if (endpoint === PROJECTS_ENDPOINT) throw new Error('Projects service unavailable')
      return new Promise(() => {})
    }) as never)
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.text()).toContain('Projects service unavailable')
  })
})
