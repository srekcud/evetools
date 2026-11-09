import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import ProjectPlanning from '@/components/group-industry/ProjectPlanning.vue'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_ID,
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
const ACTIVE_TABS = ['bom', 'contributions', 'distribution', 'sales', 'members', 'chain', 'planning', 'journal']
const COMING_SOON_TABS = ['invention']

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
  it('shows the eight working tabs, the team one included for a solo project, then one disabled coming soon tab, and opens on the materials', async () => {
    const wrapper = await mountSellingNyxOn(undefined)

    const tabs = wrapper.findAll('[data-testid^="project-tab-"]')
    const disabled = tabs.map(tab => (tab.element as HTMLButtonElement).disabled)
    const comingSoonMentions = tabs.map(tab => tab.text().includes(COMING_SOON_FRENCH))
    const materialsPanel = wrapper.findComponent(BomTable).exists()
    const chainQuery = await mountSellingNyxOn('chain')
    const chainPanels = {
      chain: chainQuery.findComponent(ProductionChain).exists(),
      materialsClosed: !chainQuery.findComponent(BomTable).exists(),
    }
    const planningQuery = await mountSellingNyxOn('planning')

    expect({
      ids: tabs.map(tab => tab.attributes('data-testid')),
      disabled,
      comingSoonMentions,
      materialsPanel,
      chainQueryOpensTheProductionChain: chainPanels.chain,
      chainQueryKeepsTheMaterialsClosed: chainPanels.materialsClosed,
      planningQueryOpensThePlanning: planningQuery.findComponent(ProjectPlanning).exists(),
      planningQueryKeepsTheMaterialsClosed: !planningQuery.findComponent(BomTable).exists(),
    }).toEqual({
      ids: [...ACTIVE_TABS, ...COMING_SOON_TABS].map(tab => `project-tab-${tab}`),
      disabled: [false, false, false, false, false, false, false, false, true],
      comingSoonMentions: [false, false, false, false, false, false, false, false, true],
      materialsPanel: true,
      chainQueryOpensTheProductionChain: true,
      chainQueryKeepsTheMaterialsClosed: true,
      planningQueryOpensThePlanning: true,
      planningQueryKeepsTheMaterialsClosed: true,
    })
  })

  it('mounts the research card of the project in the chain tab and not in the materials tab', async () => {
    const chain = await mountSellingNyxOn('chain')
    const research = chain.findComponent(ProjectResearch)
    const chainShowsTheResearch = research.exists()
    const researchProjectId = research.exists() ? research.props('projectId') : 'absent'
    const materials = await mountSellingNyxOn('bom')

    expect({
      chainShowsTheResearch,
      researchProjectId,
      materialsShowTheResearch: materials.findComponent(ProjectResearch).exists(),
    }).toEqual({
      chainShowsTheResearch: true,
      researchProjectId: PROJECT_ID,
      materialsShowTheResearch: false,
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
