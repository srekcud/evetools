import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
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
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  cockpitOf,
  tabSelector,
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
const ACTIVE_TABS = ['bom', 'distribution', 'sales', 'chain', 'planning', 'journal', 'invention']
const COMING_SOON_TABS: string[] = []

async function mountSellingNyxOn(tab: string | undefined, locale: TestLocale = 'fr') {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('selling', 'owner'),
    cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
  }, locale)
}

const JOBS_SECTION = '[data-testid="project-jobs-section"]'
const SOURCING_TAB_LABELS: Record<TestLocale, string> = { en: 'Sourcing', fr: 'Approvisionnement' }
const PRODUCTION_TAB_LABELS: Record<TestLocale, string> = { en: 'Production', fr: 'Production' }

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project cockpit tabs', () => {
  it('shows the seven working tabs of a solo project, with no contributions and no team tab and no coming soon tab, and opens on the materials', async () => {
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
      disabled: [false, false, false, false, false, false, false],
      comingSoonMentions: [false, false, false, false, false, false, false],
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

describe('project cockpit sourcing and production tabs', () => {
  it.each(TEST_LOCALES)('names the first tab Sourcing without jobs and keeps Production on the chain tab in %s', async (locale) => {
    const wrapper = await mountSellingNyxOn(undefined, locale)

    const sourcingLabel = wrapper.get(tabSelector('bom')).text()
    expect({
      sourcing: sourcingLabel.includes(SOURCING_TAB_LABELS[locale]),
      mentionsJobs: /jobs/i.test(sourcingLabel),
      rawKey: sourcingLabel.includes('groupIndustry.tabs'),
      production: wrapper.get(tabSelector('chain')).text().includes(PRODUCTION_TAB_LABELS[locale]),
    }).toEqual({ sourcing: true, mentionsJobs: false, rawKey: false, production: true })
  })

  it('renders no jobs section and no old jobs wording on the sourcing tab', async () => {
    const wrapper = await mountSellingNyxOn('bom', 'en')

    expect({
      materialsTable: wrapper.findComponent(BomTable).exists(),
      productionChain: wrapper.findComponent(ProductionChain).exists(),
      jobsSection: wrapper.find(JOBS_SECTION).exists(),
      oldHint: wrapper.text().includes('Jobs below'),
    }).toEqual({ materialsTable: true, productionChain: false, jobsSection: false, oldHint: false })
  })

  it('wraps the production chain in the jobs section on the production tab and shows no materials table there', async () => {
    const wrapper = await mountSellingNyxOn('chain', 'en')

    const section = wrapper.find(JOBS_SECTION)
    expect({
      jobsSection: section.exists(),
      sectionHoldsTheChain: section.exists() && section.element.contains(wrapper.getComponent(ProductionChain).element),
      materialsTable: wrapper.findComponent(BomTable).exists(),
    }).toEqual({ jobsSection: true, sectionHoldsTheChain: true, materialsTable: false })
  })

  it('opens the sourcing tab when the materials card of the production chain asks for it', async () => {
    const wrapper = await mountSellingNyxOn('chain', 'en')

    wrapper.getComponent(ProductionChain).vm.$emit('open-sourcing')
    await flushPromises()

    expect({
      sourcingTabSelected: wrapper.get(tabSelector('bom')).attributes('aria-selected'),
      materialsTable: wrapper.findComponent(BomTable).exists(),
      jobsSection: wrapper.find(JOBS_SECTION).exists(),
    }).toEqual({ sourcingTabSelected: 'true', materialsTable: true, jobsSection: false })
  })
})
