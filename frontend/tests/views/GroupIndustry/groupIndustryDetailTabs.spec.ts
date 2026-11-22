import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  cockpitOf,
  isSectionOpen,
  sectionToggleSelector,
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

const ACTIVE_TABS = ['bom', 'invention', 'chain', 'sales', 'distribution', 'journal']
const COMING_SOON_TABS: string[] = []

async function mountSellingNyxOn(tab: string | undefined, locale: TestLocale = 'fr', teamSize = 1) {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('selling', 'owner', { membersCount: teamSize }),
    cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
  }, locale)
}

const TEAM_OF_TWO = 2

const JOBS_SECTION = '[data-testid="project-jobs-section"]'
const SOURCING_TAB_LABELS: Record<TestLocale, string> = { en: 'Sourcing', fr: 'Approvisionnement' }
const PRODUCTION_TAB_LABELS: Record<TestLocale, string> = { en: 'Production', fr: 'Production' }

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project cockpit sections', () => {
  it('shows the seven working sections of a solo project, all closed, and opens the one a query names and no other', async () => {
    const wrapper = await mountSellingNyxOn(undefined)

    const ids = wrapper.findAll('[data-testid^="project-section-"]')
      .map(section => (section.attributes('data-testid') ?? '').replace('project-section-', ''))
      .filter(id => !id.startsWith('toggle-'))
    const materialsPanel = wrapper.findComponent(BomTable).exists()
    const chainQuery = await mountSellingNyxOn('chain')
    const chainPanels = {
      chain: chainQuery.findComponent(ProductionChain).exists(),
      materialsClosed: !chainQuery.findComponent(BomTable).exists(),
    }
    const planningQuery = await mountSellingNyxOn('planning')

    expect({
      ids: [...ids].sort(),
      open: ACTIVE_TABS.filter(tab => isSectionOpen(wrapper, tab)),
      materialsPanel,
      chainQueryOpensTheProductionChain: chainPanels.chain,
      chainQueryKeepsTheMaterialsClosed: chainPanels.materialsClosed,
      planningQueryOpensNoSection: ACTIVE_TABS.filter(tab => isSectionOpen(planningQuery, tab)),
      planningQueryKeepsTheMaterialsClosed: !planningQuery.findComponent(BomTable).exists(),
    }).toEqual({
      ids: [...ACTIVE_TABS, ...COMING_SOON_TABS].sort(),
      open: [],
      materialsPanel: false,
      chainQueryOpensTheProductionChain: true,
      chainQueryKeepsTheMaterialsClosed: true,
      planningQueryOpensNoSection: [],
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

  it('shows the financial summary without the distribution of the Settlement block on the distribution query and the sales with their suggestions on the sales query', async () => {
    const finances = await mountSellingNyxOn('distribution', 'fr', TEAM_OF_TWO)
    const financesPanels = {
      financialSummary: finances.findComponent(FinancialSummary).exists(),
      distributionTable: finances.findComponent(DistributionTable).exists(),
      salesTable: finances.findComponent(SalesTable).exists(),
      saleSuggestions: finances.findComponent(SaleSuggestionsPanel).exists(),
    }
    const sales = await mountSellingNyxOn('sales', 'fr', TEAM_OF_TWO)

    expect({
      finances: financesPanels,
      sales: {
        salesTable: sales.findComponent(SalesTable).exists(),
        saleSuggestions: sales.findComponent(SaleSuggestionsPanel).exists(),
        financialSummary: sales.findComponent(FinancialSummary).exists(),
        distributionTable: sales.findComponent(DistributionTable).exists(),
      },
    }).toEqual({
      finances: { financialSummary: true, distributionTable: false, salesTable: false, saleSuggestions: false },
      sales: { salesTable: true, saleSuggestions: true, financialSummary: false, distributionTable: false },
    })
  })
})

describe('project cockpit sourcing and production sections', () => {
  it.each(TEST_LOCALES)('names the first section Sourcing without jobs and keeps Production on the chain section in %s', async (locale) => {
    const wrapper = await mountSellingNyxOn(undefined, locale)

    expect(wrapper.find(sectionToggleSelector('bom')).exists()).toBe(true)
    expect(wrapper.find(sectionToggleSelector('chain')).exists()).toBe(true)
    const sourcingLabel = wrapper.get(sectionToggleSelector('bom')).text()
    expect({
      sourcing: sourcingLabel.includes(SOURCING_TAB_LABELS[locale]),
      mentionsJobs: /jobs/i.test(sourcingLabel),
      rawKey: sourcingLabel.includes('groupIndustry.tabs'),
      production: wrapper.get(sectionToggleSelector('chain')).text().includes(PRODUCTION_TAB_LABELS[locale]),
    }).toEqual({ sourcing: true, mentionsJobs: false, rawKey: false, production: true })
  })

  it('renders no jobs section and no old jobs wording on the sourcing section', async () => {
    const wrapper = await mountSellingNyxOn('bom', 'en')

    expect({
      materialsTable: wrapper.findComponent(BomTable).exists(),
      productionChain: wrapper.findComponent(ProductionChain).exists(),
      jobsSection: wrapper.find(JOBS_SECTION).exists(),
      oldHint: wrapper.text().includes('Jobs below'),
    }).toEqual({ materialsTable: true, productionChain: false, jobsSection: false, oldHint: false })
  })

  it('wraps the production chain in the jobs section on the production section and shows no materials table there', async () => {
    const wrapper = await mountSellingNyxOn('chain', 'en')

    const section = wrapper.find(JOBS_SECTION)
    expect({
      jobsSection: section.exists(),
      sectionHoldsTheChain: section.exists() && section.element.contains(wrapper.getComponent(ProductionChain).element),
      materialsTable: wrapper.findComponent(BomTable).exists(),
    }).toEqual({ jobsSection: true, sectionHoldsTheChain: true, materialsTable: false })
  })

  it('opens the sourcing section when the materials card of the production chain asks for it', async () => {
    const wrapper = await mountSellingNyxOn('chain', 'en')

    wrapper.getComponent(ProductionChain).vm.$emit('open-sourcing')
    await flushPromises()

    expect({
      sourcingSectionOpen: isSectionOpen(wrapper, 'bom'),
      materialsTable: wrapper.findComponent(BomTable).exists(),
      jobsSection: wrapper.find(JOBS_SECTION).exists(),
    }).toEqual({ sourcingSectionOpen: true, materialsTable: true, jobsSection: false })
  })
})
