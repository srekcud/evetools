import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import type { SellForecast } from '@/stores/group-industry/types'
import { cockpitOf, COCKPIT_PATH, nextAction, nyxProject, PROJECT_ID, PROJECT_PATH, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SELL_FORECAST_PATH = `${PROJECT_PATH}/sell-forecast`
const SETTINGS_ENDPOINT = '/me/settings'
const SELL_FORECAST_CARD = '[data-testid="sell-forecast-card"]'
const FINANCIAL_SUMMARY_STUB = 'financial-summary-stub'
const FINANCES_TAB = 'distribution'
const OTHER_TABS = ['bom', 'contributions', 'sales', 'members', 'chain', 'planning']

const NYX_FORECAST: SellForecast = {
  projectId: PROJECT_ID,
  sellStructure: { id: 1_042_000_000_001, name: 'Perimeter - Tranquility Trade Hub' },
  products: [{
    typeId: 23_913,
    typeName: 'Nyx',
    quantity: 2,
    structure: { name: 'Perimeter - Tranquility Trade Hub', unitPrice: 20_000_000_000, coveragePercent: null, feeRate: 0.04755, netUnitPrice: 19_049_000_000, netRevenue: 38_098_000_000, daysToSell: 31 },
    jita: { name: 'Jita IV - Moon 4', unitPrice: 19_500_000_000, coveragePercent: null, feeRate: 0.04755, netUnitPrice: 18_572_775_000, netRevenue: 37_145_550_000, daysToSell: 12 },
  }],
  totals: { structureNetRevenue: 38_098_000_000, jitaNetRevenue: 37_145_550_000, structureUnknownProductCount: 0, jitaUnknownProductCount: 0 },
}

async function mountSellingNyxOn(tab: string): Promise<VueWrapper> {
  route.query = { tab }
  const project = nyxProject('selling', 'owner', { sellStructureId: 1_042_000_000_001, sellStructureName: 'Perimeter - Tranquility Trade Hub' })
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SETTINGS_ENDPOINT) return { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] }
    if (endpoint === PROJECT_PATH) return project
    if (endpoint === COCKPIT_PATH) return cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 }))
    if (endpoint === SELL_FORECAST_PATH) return NYX_FORECAST
    return []
  })
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        BomTable: true,
        JobsTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ContributeModal: true,
        ContributionTable: true,
        SalesTable: true,
        SaleSuggestionsPanel: true,
        FinancialCascade: true,
        FinancialSummary: true,
        DistributionTable: true,
        ProjectDiscordRecap: true,
        ProductionChain: true,
        ProjectBuyOrBuild: true,
        ProjectStructures: true,
        ProjectStructureHealth: true,
        MaterialSourcing: true,
        ProjectSurplus: true,
        ProjectPlanning: true,
        MemberTable: true,
        PendingRequestsTable: true,
        ShareLinkCard: true,
        LoadingSpinner: true,
        ConfirmModal: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function sellForecastCalls(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SELL_FORECAST_PATH).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('group project detail sell forecast', () => {
  it('mounts the sell forecast card of the project in the finances tab, after the financial summary, and loads its forecast', async () => {
    const wrapper = await mountSellingNyxOn(FINANCES_TAB)

    const card = wrapper.find(SELL_FORECAST_CARD)
    const financialSummary = wrapper.find(FINANCIAL_SUMMARY_STUB)
    expect(card.exists()).toBe(true)
    expect(financialSummary.exists()).toBe(true)
    expect(financialSummary.element.compareDocumentPosition(card.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(sellForecastCalls()).toBe(1)
  })

  it.each(OTHER_TABS)('does not mount the sell forecast card nor load its forecast in the %s tab', async (tab) => {
    const wrapper = await mountSellingNyxOn(tab)

    expect(wrapper.find(`[data-testid="project-tab-${tab}"]`).exists()).toBe(true)
    expect(wrapper.find(SELL_FORECAST_CARD).exists()).toBe(false)
    expect(sellForecastCalls()).toBe(0)
  })
})
