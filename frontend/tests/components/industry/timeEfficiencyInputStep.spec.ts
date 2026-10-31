import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepTree from '@/components/industry/StepTree.vue'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import Industry from '@/views/Industry.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {} })) as never,
  useRouter: vi.fn(() => ({ replace: vi.fn() })) as never,
}))

const TE_LABEL = en.industry.createProject.te
const EVEN_TE_ATTRIBUTES = { step: '2', min: '0', max: '20' }

const MANUFACTURING_STEP: IndustryProjectStep = {
  id: 'step-200-hull',
  blueprintTypeId: 9201,
  productTypeId: 9001,
  productTypeName: 'Reinforced Hull',
  quantity: 6,
  runs: 6,
  depth: 0,
  activityType: 'manufacturing',
  runsFixed: false,
  sortOrder: 0,
  purchased: false,
  inStockQuantity: 0,
  meLevel: 8,
  teLevel: 14,
  jobMatchMode: 'auto',
  structureConfigId: null,
  structureConfigName: null,
  structureMaterialBonus: null,
  structureTimeBonus: null,
  favoriteSystemWithoutSuitableStructure: false,
  deletable: true,
  timePerRun: null,
  recommendedCharacterName: null,
  splitGroupId: null,
  splitIndex: 0,
  totalGroupRuns: null,
  facilityInfoType: null,
  actualFacilityName: null,
  bestStructureName: null,
  bestMaterialBonus: null,
  jobMatches: [],
  jobsCost: null,
  purchases: [],
  purchasesCost: null,
  purchasedQuantity: 0,
  inStock: false,
  esiJobId: null,
  esiJobCost: null,
  esiJobStatus: null,
  esiJobEndDate: null,
  esiJobRuns: null,
  esiJobCharacterName: null,
  esiJobsCount: null,
  esiJobsTotalRuns: null,
  esiJobsActiveRuns: null,
  esiJobsDeliveredRuns: null,
  manualJobData: false,
  recommendedStructureName: null,
  structureBonus: null,
  similarJobs: [],
}

const ACTIVE_PROJECT: IndustryProject = {
  id: 'project-200',
  productTypeName: 'Reinforced Hull',
  productTypeId: 9001,
  displayName: 'Reinforced Hull',
  runs: 6,
  meLevel: 8,
  teLevel: 6,
  maxJobDurationDays: 2,
  status: 'active',
  personalUse: false,
  isT2: false,
  bpoCost: null,
  materialCost: null,
  transportCost: null,
  jobsCost: null,
  taxAmount: null,
  sellPrice: null,
  totalCost: null,
  estimatedJobCost: null,
  estimatedSellPrice: null,
  estimatedSellPriceSource: null,
  estimatedTaxAmount: null,
  estimatedMaterialCost: null,
  profit: null,
  profitPercent: null,
  notes: null,
  createdAt: '2026-10-29T08:00:00+00:00',
  completedAt: null,
  jobsStartDate: null,
}

const industryChildrenStubs = {
  MainLayout: { template: '<div><slot /></div>' },
  teleport: true,
  ErrorBanner: true,
  ProductSearch: true,
  ProjectTable: true,
  ProjectDetail: true,
  BlacklistConfig: true,
  StructureConfig: true,
  SkillsConfig: true,
  FeesConfig: true,
  IndustryDashboard: true,
  IndustrySlotsSection: true,
  ProfitMarginTab: true,
  BatchScanTab: true,
  BuyVsBuildTab: true,
  PivotAdvisorTab: true,
  StockpileDashboard: true,
}

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function teAttributesOf(wrapper: VueWrapper, selector: string) {
  const input = wrapper.find(selector)
  if (!input.exists()) return null
  return { step: input.attributes('step'), min: input.attributes('min'), max: input.attributes('max') }
}

function labelledTeInputSelector(wrapper: VueWrapper): string {
  const label = wrapper.findAll('label').find(candidate => candidate.text().trim() === TE_LABEL)
  if (!label) throw new Error('No TE label')
  return `[id="${label.attributes('for')}"]`
}

function buttonNamed(wrapper: VueWrapper, name: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === name)
  if (!button) throw new Error(`No button named ${name}`)
  return button
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(null)
})

describe('TE inputs step by two', () => {
  it('steps the TE of an editable step by two from 0 to 20', async () => {
    const wrapper = mount(StepTree, { props: { steps: [{ ...MANUFACTURING_STEP }] }, global: { plugins: [i18n()] } })

    await wrapper.get('button[aria-expanded="false"]').trigger('click')

    expect(teAttributesOf(wrapper, `input[aria-label="${TE_LABEL}"]`)).toEqual(EVEN_TE_ATTRIBUTES)
  })

  it('steps the TE of a project edited in the table by two from 0 to 20', async () => {
    useProjectsStore().projects = [{ ...ACTIVE_PROJECT }]
    const wrapper = mount(ProjectTable, { props: { activeOnly: true }, global: { plugins: [i18n()] } })

    await buttonNamed(wrapper, `${ACTIVE_PROJECT.meLevel}/${ACTIVE_PROJECT.teLevel}`).trigger('dblclick')

    expect(teAttributesOf(wrapper, `input[aria-label="${TE_LABEL}"]`)).toEqual(EVEN_TE_ATTRIBUTES)
  })

  it('steps the TE of the project creation form by two from 0 to 20', async () => {
    const wrapper = mount(Industry, { global: { plugins: [i18n()], stubs: industryChildrenStubs } })
    await flushPromises()
    await buttonNamed(wrapper, en.industry.tabs.projects).trigger('click')
    await buttonNamed(wrapper, en.industry.createProject.newProject).trigger('click')

    expect(teAttributesOf(wrapper, labelledTeInputSelector(wrapper))).toEqual(EVEN_TE_ATTRIBUTES)
  })

  it('steps the TE of the profit margin analysis by two from 0 to 20', () => {
    const wrapper = mount(ProfitMarginTab, { global: { plugins: [i18n()], stubs: { ProductSearch: true } } })

    expect(teAttributesOf(wrapper, labelledTeInputSelector(wrapper))).toEqual(EVEN_TE_ATTRIBUTES)
  })
})
