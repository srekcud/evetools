import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CostEstimationTab from '@/components/industry/CostEstimationTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { CostEstimation, IndustryProject } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-119'
const RIFTER_TYPE_ID = 587
const ROBOTICS_TYPE_ID = 9848
const ROBOTICS_FAILURE_REASON = `No blueprint or reaction found for product type ID ${ROBOTICS_TYPE_ID}`
const SELL_PRICE = 50_000

const PYERITE_MATERIAL = { typeId: 35, typeName: 'Pyerite', quantity: 1200, unitPrice: 7.25, totalPrice: 8700 }

const PROJECT = {
  id: PROJECT_ID,
  productTypeName: 'Rifter',
  productTypeId: RIFTER_TYPE_ID,
  displayName: null,
  runs: 2,
  meLevel: 10,
  teLevel: 20,
  maxJobDurationDays: 2,
  status: 'active',
  personalUse: false,
  isT2: false,
  bpoCost: 1000,
  materialCost: null,
  transportCost: null,
  jobsCost: null,
  taxAmount: null,
  sellPrice: SELL_PRICE,
  totalCost: null,
  estimatedJobCost: null,
  estimatedSellPrice: null,
  estimatedSellPriceSource: null,
  estimatedTaxAmount: null,
  estimatedMaterialCost: null,
  profit: null,
  profitPercent: null,
  notes: null,
  createdAt: '2026-10-19T10:00:00+00:00',
  completedAt: null,
  jobsStartDate: null,
  rootProducts: [
    { typeId: RIFTER_TYPE_ID, typeName: 'Rifter', runs: 2, meLevel: 10, teLevel: 20 },
    { typeId: ROBOTICS_TYPE_ID, typeName: 'Robotics', runs: 3, meLevel: 8, teLevel: 16 },
  ],
  steps: [],
} as IndustryProject

const UNKNOWN_COST_ESTIMATION = {
  id: PROJECT_ID,
  materialCost: null,
  jobInstallCost: 5000,
  bpoCost: 1000,
  totalCost: null,
  perUnit: null,
  outputQuantity: 2,
  materials: [PYERITE_MATERIAL],
  jobInstallSteps: [],
  failedProducts: [{ typeId: ROBOTICS_TYPE_ID, reason: ROBOTICS_FAILURE_REASON }],
} as unknown as CostEstimation

const PRICED_COST_ESTIMATION = {
  id: PROJECT_ID,
  materialCost: 8700,
  jobInstallCost: 5000,
  bpoCost: 1000,
  totalCost: 14_700,
  perUnit: 7350,
  outputQuantity: 2,
  materials: [PYERITE_MATERIAL],
  jobInstallSteps: [],
  failedProducts: [],
} as unknown as CostEstimation

async function mountLoadedCostEstimationTab(costEstimation: CostEstimation, locale: 'en' | 'fr' = 'en') {
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url.endsWith('/cost-estimation')) return costEstimation
    return { copies: [], totalCopyCost: 0 }
  })
  useProjectsStore().currentProject = PROJECT
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(CostEstimationTab, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as { activate: () => Promise<void> }).activate()
  await flushPromises()
  return wrapper
}

function summaryCard(wrapper: Awaited<ReturnType<typeof mountLoadedCostEstimationTab>>, label: string) {
  const card = wrapper.findAll('.eve-card').find(candidate => candidate.find('p').text() === label)
  expect(card, `summary card ${label}`).toBeDefined()
  return card!
}

function saveCostsButton(wrapper: Awaited<ReturnType<typeof mountLoadedCostEstimationTab>>, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  return button!
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('CostEstimationTab', () => {
  it.each([
    ['en', en, 'Unknown'],
    ['fr', fr, 'Inconnu'],
  ] as const)('shows the material, total and per unit costs of a project with a failed product as unknown (%s)', async (locale, messages, unknownLabel) => {
    const wrapper = await mountLoadedCostEstimationTab(UNKNOWN_COST_ESTIMATION, locale)
    const labels = messages.industry.costEstimation

    const materialCostCard = summaryCard(wrapper, labels.materialCost)
    expect(materialCostCard.find('p:nth-of-type(2)').text()).toBe(unknownLabel)
    const totalCostCard = summaryCard(wrapper, labels.totalProductionCost)
    expect(totalCostCard.find('p:nth-of-type(2)').text()).toBe(unknownLabel)
    expect(totalCostCard.find('p:nth-of-type(3)').text()).toContain(`${labels.perUnit}: ${unknownLabel}`)
    expect(summaryCard(wrapper, labels.jobInstallCost).find('p:nth-of-type(2)').text()).toBe('5,000')
  })

  it('warns about the failed product with its name and reason, keeps the priced materials and computes no profit, margin nor breakdown', async () => {
    const wrapper = await mountLoadedCostEstimationTab(UNKNOWN_COST_ESTIMATION)

    const warning = wrapper.find('[data-testid="failed-products-warning"]')
    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain('Robotics')
    expect(warning.text()).toContain(ROBOTICS_FAILURE_REASON)
    expect(wrapper.text()).toContain('Pyerite')
    expect(wrapper.text()).toContain('8,700')
    expect(wrapper.text()).not.toContain('+50,000')
    expect(wrapper.text()).not.toContain('100.0%')
    expect(wrapper.text()).not.toContain('NaN')
    expect(wrapper.text()).not.toContain('Infinity')
    expect(saveCostsButton(wrapper, en.industry.costEstimation.saveCostsToProject).attributes('disabled')).toBeDefined()
  })

  it('shows the priced costs, profit, margin and breakdown without any failed products warning', async () => {
    const wrapper = await mountLoadedCostEstimationTab(PRICED_COST_ESTIMATION)
    const labels = en.industry.costEstimation

    expect(summaryCard(wrapper, labels.materialCost).find('p:nth-of-type(2)').text()).toBe('8,700')
    const totalCostCard = summaryCard(wrapper, labels.totalProductionCost)
    expect(totalCostCard.find('p:nth-of-type(2)').text()).toBe('14,700')
    expect(totalCostCard.find('p:nth-of-type(3)').text()).toBe(`${labels.perUnit}: 7,350 ISK`)
    expect(wrapper.text()).toContain('+35,300')
    expect(wrapper.text()).toContain('70.6%')
    expect(wrapper.text()).toContain('59.2%')
    expect(wrapper.text()).toContain('34.0%')
    expect(wrapper.text()).toContain('6.8%')
    expect(wrapper.find('[data-testid="failed-products-warning"]').exists()).toBe(false)
    expect(saveCostsButton(wrapper, labels.saveCostsToProject).attributes('disabled')).toBeUndefined()
  })
})
