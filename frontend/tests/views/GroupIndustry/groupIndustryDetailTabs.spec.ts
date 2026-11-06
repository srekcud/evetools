import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
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

const COMING_SOON_FRENCH = 'À venir'
const ACTIVE_TABS = ['bom', 'contributions', 'distribution', 'sales', 'members']
const COMING_SOON_TABS = ['chain', 'invention', 'planning', 'journal']

async function mountSellingNyxOn(tab: string | undefined) {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('selling', 'owner'),
    cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
  }, 'fr')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project cockpit tabs', () => {
  it('shows the five working tabs, the team one included for a solo project, then four disabled coming soon tabs, and opens on the materials', async () => {
    const wrapper = await mountSellingNyxOn(undefined)

    const tabs = wrapper.findAll('[data-testid^="project-tab-"]')
    const disabled = tabs.map(tab => (tab.element as HTMLButtonElement).disabled)
    const comingSoonMentions = tabs.map(tab => tab.text().includes(COMING_SOON_FRENCH))
    const materialsPanel = wrapper.findComponent(BomTable).exists()
    const comingSoonQuery = await mountSellingNyxOn('chain')

    expect({
      ids: tabs.map(tab => tab.attributes('data-testid')),
      disabled,
      comingSoonMentions,
      materialsPanel,
      comingSoonQueryOpensMaterials: comingSoonQuery.findComponent(BomTable).exists(),
    }).toEqual({
      ids: [...ACTIVE_TABS, ...COMING_SOON_TABS].map(tab => `project-tab-${tab}`),
      disabled: [false, false, false, false, false, true, true, true, true],
      comingSoonMentions: [false, false, false, false, false, true, true, true, true],
      materialsPanel: true,
      comingSoonQueryOpensMaterials: true,
    })
  })

  it('shows the financial summary and the distribution on the distribution query and the sales with their suggestions on the sales query', async () => {
    const finances = await mountSellingNyxOn('distribution')
    const financesPanels = {
      financialSummary: finances.findComponent(FinancialSummary).exists(),
      distributionTable: finances.findComponent(DistributionTable).exists(),
      salesTable: finances.findComponent(SalesTable).exists(),
      saleSuggestions: finances.findComponent(SaleSuggestionsPanel).exists(),
    }
    const sales = await mountSellingNyxOn('sales')

    expect({
      finances: financesPanels,
      sales: {
        salesTable: sales.findComponent(SalesTable).exists(),
        saleSuggestions: sales.findComponent(SaleSuggestionsPanel).exists(),
        financialSummary: sales.findComponent(FinancialSummary).exists(),
        distributionTable: sales.findComponent(DistributionTable).exists(),
      },
    }).toEqual({
      finances: { financialSummary: true, distributionTable: true, salesTable: false, saleSuggestions: false },
      sales: { salesTable: true, saleSuggestions: true, financialSummary: false, distributionTable: false },
    })
  })
})
