import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ContractSaleSuggestions from '@/components/group-industry/ContractSaleSuggestions.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
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

async function mountSalesTab(status: GroupProjectStatus): Promise<VueWrapper> {
  route.query = { tab: 'sales' }
  return mountCockpitDetail({
    project: nyxProject(status, 'owner'),
    cockpit: cockpitOf(status, nextAction({ code: 'nothing_to_do' })),
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('sales tab of a completed project', () => {
  it('tells the sales table the project is completed and hides the matching panels', async () => {
    const wrapper = await mountSalesTab('completed')

    expect({
      salesTableCompleted: wrapper.findComponent(SalesTable).props('isCompleted'),
      transactionsPanel: wrapper.findComponent(SaleSuggestionsPanel).exists(),
      contractsPanel: wrapper.findComponent(ContractSaleSuggestions).exists(),
    }).toEqual({ salesTableCompleted: true, transactionsPanel: false, contractsPanel: false })
  })

  it('keeps the sales table open and the matching panels on a project being sold', async () => {
    const wrapper = await mountSalesTab('selling')

    expect({
      salesTableCompleted: wrapper.findComponent(SalesTable).props('isCompleted'),
      transactionsPanel: wrapper.findComponent(SaleSuggestionsPanel).exists(),
      contractsPanel: wrapper.findComponent(ContractSaleSuggestions).exists(),
    }).toEqual({ salesTableCompleted: false, transactionsPanel: true, contractsPanel: true })
  })
})
