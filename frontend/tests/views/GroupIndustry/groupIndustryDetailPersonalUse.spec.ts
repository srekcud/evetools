import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import ProjectTransfers from '@/components/group-industry/ProjectTransfers.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import type { GroupMember } from '@/stores/group-industry/types'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
  sectionSelector,
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

function pilot(id: string, role: GroupMember['role']): GroupMember {
  return {
    id,
    characterName: `Pilot ${id}`,
    characterId: 2112000000 + id.length,
    corporationId: 98_000_007,
    corporationName: 'Sabre Doctrine Corp',
    role,
    status: 'accepted',
    totalContributionValue: 0,
    contributionCount: 0,
    joinedAt: '2026-11-02T08:30:00+00:00',
  }
}

async function mountInProgressTeamProject(personalUse: boolean, query: Record<string, string> = {}): Promise<VueWrapper> {
  route.query = query
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { membersCount: 2, pendingContributionCount: 0, personalUse }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'nothing_to_do' })),
    members: [pilot('owner-1', 'owner'), pilot('pilot-3', 'member')],
  })
}

function headerButtonLabels(wrapper: VueWrapper): string[] {
  return wrapper.findAll('button').map(button => button.text())
}

function headerStatusLabelsOf(wrapper: VueWrapper): { selling: boolean; completed: boolean } {
  const labels = headerButtonLabels(wrapper)
  return {
    selling: labels.includes(en.groupIndustry.detail.statusActions.selling),
    completed: labels.includes(en.groupIndustry.detail.statusActions.completed),
  }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('detail page of a personal use project in progress', () => {
  it('has no sales section', async () => {
    const personal = await mountInProgressTeamProject(true)

    expect(personal.find(sectionSelector('sales')).exists()).toBe(false)
  })

  it('keeps the sales section of the same team project without personal use', async () => {
    const sold = await mountInProgressTeamProject(false)

    expect(sold.find(sectionSelector('sales')).exists()).toBe(true)
  })

  it('shows no sales section and no sales table when the sales tab is requested', async () => {
    const wrapper = await mountInProgressTeamProject(true, { tab: 'sales' })

    expect({
      sales: wrapper.find(sectionSelector('sales')).exists(),
      salesTable: wrapper.findComponent(SalesTable).exists(),
    }).toEqual({ sales: false, salesTable: false })
  })

  it('shows no distribution table nor transfers in the finances section and keeps the cascade', async () => {
    const wrapper = await mountInProgressTeamProject(true, { tab: 'distribution' })

    expect({
      cascade: wrapper.findComponent(FinancialCascade).exists(),
      distribution: wrapper.findComponent(DistributionTable).exists(),
      transfers: wrapper.findComponent(ProjectTransfers).exists(),
    }).toEqual({ cascade: true, distribution: false, transfers: false })
  })

  it('shows the distribution table and the transfers of the same team project without personal use', async () => {
    const wrapper = await mountInProgressTeamProject(false, { tab: 'distribution' })

    expect({
      distribution: wrapper.findComponent(DistributionTable).exists(),
      transfers: wrapper.findComponent(ProjectTransfers).exists(),
    }).toEqual({ distribution: true, transfers: true })
  })

  it('offers closing the project and no move to selling in the header', async () => {
    const personal = await mountInProgressTeamProject(true)

    expect(headerStatusLabelsOf(personal)).toEqual({ selling: false, completed: true })
  })

  it('offers the move to selling and not the closing in the header of the same team project without personal use', async () => {
    const sold = await mountInProgressTeamProject(false)

    expect(headerStatusLabelsOf(sold)).toEqual({ selling: true, completed: false })
  })
})
