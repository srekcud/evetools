import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import MarginBridgeActions from '@/components/industry/MarginBridgeActions.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useIndustryStore } from '@/stores/industry'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginResult } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HERON_TYPE_ID = 605
const HERON_RUNS = 12
const HERON_ME_LEVEL = 8
const TYPED_ME_LEVEL = 6
const EmptyView = { render: () => null }

const HERON_MARGIN: ProfitMarginResult = {
  typeId: HERON_TYPE_ID,
  typeName: 'Heron',
  isT2: false,
  runs: HERON_RUNS,
  outputQuantity: HERON_RUNS,
  outputPerRun: 1,
  materialCost: 412_600,
  materials: [{ typeId: 37, typeName: 'Isogen', quantity: 3_150, unitPrice: 96.45, totalPrice: 303_817.5 }],
  jobInstallCost: 18_300,
  jobInstallSteps: [],
  inventionCost: 0,
  totalCost: 430_900,
  costPerUnit: 35_908.33,
  invention: null,
  sellPrices: {
    jitaSell: 615_000,
    jitaSellCoveragePercent: null,
    structureSell: null,
    structureBuy: null,
    contractSell: null,
    contractCount: null,
    structureId: 1_046_664_001_931,
    structureName: 'Raitaru Heron Works',
  },
  brokerFeeRate: 0.015,
  salesTaxRate: 0.0337,
  margins: { jitaSell: { revenue: 7_380_000, fees: 358_000, profit: 6_591_100, margin: 25.07 } },
  dailyVolume: 54,
  missingData: [],
  warnings: [],
}

const CONTAINER = '[data-testid="margin-bridge-actions"]'
const CREATE_PROJECT = '[data-testid="margin-create-project"]'
const BUY_VS_BUILD = '[data-testid="margin-buy-vs-build"]'
const MARKET_LINK = '[data-testid="margin-market-link"]'

function control(wrapper: VueWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

function mountActions(result: ProfitMarginResult, meLevel: number) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/market', component: EmptyView }] })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(MarginBridgeActions, {
    global: { plugins: [i18n, router] },
    props: { result, meLevel },
  })
}

function mountTab(result: ProfitMarginResult | null, loading = false) {
  const projects = useProjectsStore()
  projects.marginResult = result
  projects.marginLoading = loading
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/market', component: EmptyView }] })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ProfitMarginTab, {
    global: { plugins: [i18n, router], stubs: { ProductSearch: true } },
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  useIndustryStore().navigationIntent = null
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(null)
})

describe('Margins bridges', () => {
  it('shows no bridge bar without analysis and keeps it for the displayed analysis during a reload', () => {
    const withoutAnalysis = mountTab(null)
    const reloading = mountTab(HERON_MARGIN, true)

    expect({
      withoutAnalysis: withoutAnalysis.find(CONTAINER).exists(),
      reloading: reloading.find(CONTAINER).exists(),
    }).toEqual({ withoutAnalysis: false, reloading: true })
  })

  it('creates the project with the analysed runs and the ME currently typed in the tab', async () => {
    const wrapper = mountTab(HERON_MARGIN)
    const meLabel = wrapper.findAll('label').find(label => label.text() === en.industry.createProject.me)
    if (!meLabel) throw new Error('No ME label')
    await wrapper.find(`input[id="${meLabel.attributes('for')}"]`).setValue(TYPED_ME_LEVEL)

    await control(wrapper, CREATE_PROJECT).trigger('click')

    expect(useIndustryStore().navigationIntent).toEqual({
      target: 'projects',
      prefill: { typeId: HERON_TYPE_ID, typeName: 'Heron', runs: HERON_RUNS, me: TYPED_ME_LEVEL, isT2: false },
    })
  })

  it('asks Buy vs Build for the same product and links to its market detail', async () => {
    const wrapper = mountActions(HERON_MARGIN, HERON_ME_LEVEL)

    await control(wrapper, BUY_VS_BUILD).trigger('click')

    expect({
      intent: useIndustryStore().navigationIntent,
      marketHref: control(wrapper, MARKET_LINK).attributes('href'),
    }).toEqual({
      intent: { target: 'buy-vs-build', typeId: HERON_TYPE_ID },
      marketHref: `/market?tab=detail&typeId=${HERON_TYPE_ID}`,
    })
  })
})
