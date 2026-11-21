import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PENDING_CONTRIBUTIONS_COUNT = 4
const PROJECT_CONTRIBUTIONS_COUNT = 9
const CONTRIBUTIONS_ENDPOINT_FRAGMENT = '/contributions'

async function mountTeamProject(tab: string | undefined, pendingContributionCount: number, contributionsCount = PROJECT_CONTRIBUTIONS_COUNT): Promise<VueWrapper> {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { membersCount: 3, pendingContributionCount, contributionsCount }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'nothing_to_do' })),
    members: [],
  })
}

function contributionsBadge(wrapper: VueWrapper): string {
  return wrapper.get('[data-testid="project-tab-contributions"]').text()
}

function contributionsWereFetched(): boolean {
  return vi.mocked(apiRequest).mock.calls.some(([url]) => String(url).endsWith(CONTRIBUTIONS_ENDPOINT_FRAGMENT))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('contributions tab badge', () => {
  it('shows the pending contributions carried by the project payload before the tab is ever opened', async () => {
    const wrapper = await mountTeamProject(undefined, PROJECT_PENDING_CONTRIBUTIONS_COUNT)

    expect(contributionsWereFetched()).toBe(false)
    expect(contributionsBadge(wrapper)).toMatch(new RegExp(`${PROJECT_PENDING_CONTRIBUTIONS_COUNT}$`))
    expect(contributionsBadge(wrapper)).not.toContain(String(PROJECT_CONTRIBUTIONS_COUNT))
  })

  it('shows no count when no contribution waits for review', async () => {
    const wrapper = await mountTeamProject(undefined, 0)

    expect(contributionsBadge(wrapper)).not.toMatch(/\d/)
  })

  it('keeps the members badge on the project payload', async () => {
    const wrapper = await mountTeamProject(undefined, PROJECT_PENDING_CONTRIBUTIONS_COUNT)

    expect(wrapper.get('[data-testid="project-tab-members"]').text()).toContain('3')
  })
})
