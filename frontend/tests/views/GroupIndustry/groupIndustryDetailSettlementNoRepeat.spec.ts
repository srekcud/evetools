import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import ProjectDiscordRecap from '@/components/group-industry/ProjectDiscordRecap.vue'
import ProjectSettlementBlock from '@/components/group-industry/ProjectSettlementBlock.vue'
import ProjectSurplus from '@/components/group-industry/ProjectSurplus.vue'
import ProjectTransfers from '@/components/group-industry/ProjectTransfers.vue'
import SellForecastCard from '@/components/group-industry/SellForecastCard.vue'
import { apiRequest } from '@/services/api'
import type { GroupProjectStatus } from '@/stores/group-industry/types'
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

const FINANCES_TAB = 'distribution'
const SOURCING_TAB = 'bom'

async function mountTeamProject(status: GroupProjectStatus, tab: string, personalUse = false): Promise<VueWrapper> {
  route.query = { tab }
  return mountCockpitDetail({
    project: nyxProject(status, 'owner', { membersCount: 3, personalUse }),
    cockpit: cockpitOf(status, nextAction({ code: 'nothing_to_do' })),
  })
}

function financesParts(wrapper: VueWrapper) {
  return {
    settlementBlock: wrapper.findComponent(ProjectSettlementBlock).exists(),
    cascade: wrapper.findComponent(FinancialCascade).exists(),
    distribution: wrapper.findComponent(DistributionTable).exists(),
    transfers: wrapper.findComponent(ProjectTransfers).exists(),
    discordRecap: wrapper.findComponent(ProjectDiscordRecap).exists(),
    summary: wrapper.findComponent(FinancialSummary).exists(),
    forecast: wrapper.findComponent(SellForecastCard).exists(),
  }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('Finances section while the Settlement block is shown', () => {
  it.each<GroupProjectStatus>(['selling', 'completed'])('shows the cascade, distribution, transfers and Discord recap only in the Settlement block of a %s project and keeps the fee summary and the sell forecast', async status => {
    const wrapper = await mountTeamProject(status, FINANCES_TAB)

    expect(financesParts(wrapper)).toEqual({
      settlementBlock: true,
      cascade: false,
      distribution: false,
      transfers: false,
      discordRecap: false,
      summary: true,
      forecast: true,
    })
  })

  it.each<GroupProjectStatus>(['draft', 'published', 'in_progress'])('keeps the whole Finances section of a %s project, which has no Settlement block yet', async status => {
    const wrapper = await mountTeamProject(status, FINANCES_TAB)

    expect(financesParts(wrapper)).toEqual({
      settlementBlock: false,
      cascade: true,
      distribution: true,
      transfers: true,
      discordRecap: true,
      summary: true,
      forecast: true,
    })
  })

  it('keeps the cascade and the Discord recap in the Finances section of a personal use project being sold', async () => {
    const wrapper = await mountTeamProject('selling', FINANCES_TAB, true)

    expect(financesParts(wrapper)).toMatchObject({ settlementBlock: false, cascade: true, discordRecap: true })
  })
})

describe('Sourcing section surplus', () => {
  it('shows the surplus only in the Settlement block of a completed project', async () => {
    const wrapper = await mountTeamProject('completed', SOURCING_TAB)

    expect({
      settlementBlock: wrapper.findComponent(ProjectSettlementBlock).exists(),
      surplus: wrapper.findComponent(ProjectSurplus).exists(),
    }).toEqual({ settlementBlock: true, surplus: false })
  })

  it('keeps the surplus in the Sourcing section of a completed personal use project', async () => {
    const wrapper = await mountTeamProject('completed', SOURCING_TAB, true)

    expect({
      settlementBlock: wrapper.findComponent(ProjectSettlementBlock).exists(),
      surplus: wrapper.findComponent(ProjectSurplus).exists(),
    }).toEqual({ settlementBlock: false, surplus: true })
  })

  it('shows no surplus on a project being sold, as before', async () => {
    const wrapper = await mountTeamProject('selling', SOURCING_TAB)

    expect(wrapper.findComponent(ProjectSurplus).exists()).toBe(false)
  })
})
