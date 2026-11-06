import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
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
    minimumOutsideSaving: 500000,
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

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) =>
    endpoint === PROJECTS_ENDPOINT ? GROUP_PROJECTS : new Promise(() => {})) as never)
})

describe('IndustryDashboard projects', () => {
  it('counts the projects that are not completed from the unified project list and lists them as recent projects', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(IndustryDashboard, { global: { plugins: [i18n] } })
    await flushPromises()

    const activeProjectsLabel = wrapper.findAll('p').find(label => label.text() === en.industry.dashboard.activeProjects)
    const activeProjectsCard = activeProjectsLabel?.element.parentElement?.parentElement
    expect(activeProjectsCard?.textContent?.replace(en.industry.dashboard.activeProjects, '').trim()).toBe('2')
    expect(wrapper.text()).toContain('Widow in progress')
    expect(wrapper.text()).toContain('Widow completed')
  })
})
