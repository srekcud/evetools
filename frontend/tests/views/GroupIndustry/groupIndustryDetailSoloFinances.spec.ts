import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import ProjectTransfers from '@/components/group-industry/ProjectTransfers.vue'
import SellForecastCard from '@/components/group-industry/SellForecastCard.vue'
import { apiRequest } from '@/services/api'
import type { GroupMember } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
  type CockpitAnswers,
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

function pilot(id: string, status: GroupMember['status']): GroupMember {
  return {
    id,
    characterName: `Pilot ${id}`,
    characterId: 2112000000 + id.length,
    corporationId: 98_000_007,
    corporationName: 'Sabre Doctrine Corp',
    role: status === 'accepted' && id === 'owner-1' ? 'owner' : 'member',
    status,
    totalContributionValue: 0,
    contributionCount: 0,
    joinedAt: '2026-11-02T08:30:00+00:00',
  }
}

const OWNER = pilot('owner-1', 'accepted')

async function mountFinances(answers: Partial<CockpitAnswers>): Promise<VueWrapper> {
  route.query = { tab: 'distribution' }
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'nothing_to_do' })),
    members: [OWNER],
    ...answers,
  })
}

function financePanels(wrapper: VueWrapper) {
  return {
    cascade: wrapper.findComponent(FinancialCascade).exists(),
    summary: wrapper.findComponent(FinancialSummary).exists(),
    forecast: wrapper.findComponent(SellForecastCard).exists(),
    distribution: wrapper.findComponent(DistributionTable).exists(),
    transfers: wrapper.findComponent(ProjectTransfers).exists(),
  }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('finances of a project worked on alone', () => {
  it('hides the distribution and the transfers and keeps the cascade, the summary and the forecast', async () => {
    const wrapper = await mountFinances({})

    expect(financePanels(wrapper)).toEqual({ cascade: true, summary: true, forecast: true, distribution: false, transfers: false })
  })

  it('keeps the distribution and the transfers hidden on a personal use project and still shows its cascade', async () => {
    const wrapper = await mountFinances({
      project: nyxProject('selling', 'owner', { membersCount: 1, pendingContributionCount: 0, personalUse: true }),
    })

    expect(financePanels(wrapper)).toEqual({ cascade: true, summary: false, forecast: false, distribution: false, transfers: false })
  })
})

describe('finances once the project has a team', () => {
  it('shows the distribution and the transfers with two members', async () => {
    const wrapper = await mountFinances({
      project: nyxProject('in_progress', 'owner', { membersCount: 2, pendingContributionCount: 0 }),
      members: [OWNER, pilot('pilot-3', 'accepted')],
    })

    expect(financePanels(wrapper)).toEqual({ cascade: true, summary: true, forecast: true, distribution: true, transfers: true })
  })

  it('shows the distribution and the transfers while a member is pending', async () => {
    const wrapper = await mountFinances({ members: [OWNER, pilot('waiting-2', 'pending')] })

    expect(financePanels(wrapper)).toMatchObject({ distribution: true, transfers: true })
  })

  it('shows the distribution and the transfers while a contribution waits for a review', async () => {
    const wrapper = await mountFinances({
      project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 1 }),
    })

    expect(financePanels(wrapper)).toMatchObject({ distribution: true, transfers: true })
  })

  it('shows the distribution and the transfers when the member list fails to load', async () => {
    const wrapper = await mountFinances({ members: new Error('Members unavailable') })

    expect(financePanels(wrapper)).toMatchObject({ distribution: true, transfers: true })
  })

  it('shows the distribution and the transfers while the member list is loading', async () => {
    const wrapper = await mountFinances({ members: deferred<GroupMember[]>().promise })

    expect(financePanels(wrapper)).toMatchObject({ distribution: true, transfers: true })
  })
})
