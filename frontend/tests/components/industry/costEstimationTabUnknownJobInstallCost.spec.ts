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

const PROJECT_ID = 'project-7'
const RIFTER_TYPE_ID = 587
const MERLIN_TYPE_ID = 603
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
  ],
  steps: [],
} as IndustryProject

const UNKNOWN_STEP_COST_INDEX = {
  stepId: 'step-unindexed',
  productTypeId: RIFTER_TYPE_ID,
  productName: 'Rifter',
  solarSystemId: 30_002_510,
  systemName: 'Unindexed',
  costIndex: null,
  runs: 2,
  installCost: null,
  unknownReason: 'missing_cost_index',
  missingAdjustedPriceTypeIds: [],
}

const KNOWN_STEP = {
  stepId: 'step-jita',
  productTypeId: MERLIN_TYPE_ID,
  productName: 'Merlin',
  solarSystemId: 30_000_142,
  systemName: 'Jita',
  costIndex: 0.0625,
  runs: 5,
  installCost: 9900,
  unknownReason: null,
  missingAdjustedPriceTypeIds: [],
}

const UNKNOWN_JOB_INSTALL_COST_ESTIMATION = {
  id: PROJECT_ID,
  materialCost: 8700,
  jobInstallCost: null,
  bpoCost: 1000,
  totalCost: null,
  perUnit: null,
  materials: [PYERITE_MATERIAL],
  jobInstallSteps: [KNOWN_STEP, UNKNOWN_STEP_COST_INDEX],
  failedProducts: [],
} as unknown as CostEstimation

const KNOWN_JOB_INSTALL_COST_ESTIMATION = {
  id: PROJECT_ID,
  materialCost: 8700,
  jobInstallCost: 9900,
  bpoCost: 1000,
  totalCost: 19_600,
  perUnit: 9800,
  materials: [PYERITE_MATERIAL],
  jobInstallSteps: [KNOWN_STEP],
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

const JOB_INSTALL_TABLE_INDEX = 1

function jobInstallTable(wrapper: Awaited<ReturnType<typeof mountLoadedCostEstimationTab>>) {
  return wrapper.findAll('table')[JOB_INSTALL_TABLE_INDEX]
}

function stepCells(wrapper: Awaited<ReturnType<typeof mountLoadedCostEstimationTab>>, stepIndex: number) {
  return jobInstallTable(wrapper).findAll('tbody tr')[stepIndex].findAll('td').map(cell => cell.text())
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('CostEstimationTab with an unknown job install cost', () => {
  it.each([
    ['en', en, 'Unknown'],
    ['fr', fr, 'Inconnu'],
  ] as const)('shows the jobs total, the total production cost and the per unit cost as unknown while keeping the material cost (%s)', async (locale, messages, unknownLabel) => {
    const wrapper = await mountLoadedCostEstimationTab(UNKNOWN_JOB_INSTALL_COST_ESTIMATION, locale)
    const labels = messages.industry.costEstimation

    expect(summaryCard(wrapper, labels.jobInstallCost).find('p:nth-of-type(2)').text()).toBe(unknownLabel)
    expect(summaryCard(wrapper, labels.materialCost).find('p:nth-of-type(2)').text()).toBe('8,700')
    const totalCostCard = summaryCard(wrapper, labels.totalProductionCost)
    expect(totalCostCard.find('p:nth-of-type(2)').text()).toBe(unknownLabel)
    expect(totalCostCard.find('p:nth-of-type(3)').text()).toContain(`${labels.perUnit}: ${unknownLabel}`)
    expect(jobInstallTable(wrapper).find('tfoot').text()).toContain(unknownLabel)
  })

  it.each([
    ['en', en, 'Unknown'],
    ['fr', fr, 'Inconnu'],
  ] as const)('shows the cost index and the install cost of the unknown step as unknown and keeps the known step amounts (%s)', async (locale, _messages, unknownLabel) => {
    const wrapper = await mountLoadedCostEstimationTab(UNKNOWN_JOB_INSTALL_COST_ESTIMATION, locale)

    const knownStepCells = stepCells(wrapper, 0)
    expect(knownStepCells).toContain('6.25%')
    expect(knownStepCells).toContain('9,900')
    const unknownStepCells = stepCells(wrapper, 1)
    expect(unknownStepCells.filter(cell => cell === unknownLabel)).toHaveLength(2)
    expect(unknownStepCells).not.toContain('0.00%')
    expect(unknownStepCells).not.toContain('0')
  })

  it('shows no cost breakdown percentages, no NaN and disables saving the costs into the project', async () => {
    const wrapper = await mountLoadedCostEstimationTab(UNKNOWN_JOB_INSTALL_COST_ESTIMATION)

    expect(wrapper.text()).not.toContain('NaN')
    expect(wrapper.text()).not.toContain('Infinity')
    expect(wrapper.text()).not.toContain('%)')
    expect(saveCostsButton(wrapper, en.industry.costEstimation.saveCostsToProject).attributes('disabled')).toBeDefined()
  })

  it('does not send the jobs cost to the project when the job install cost is unknown', async () => {
    const wrapper = await mountLoadedCostEstimationTab(UNKNOWN_JOB_INSTALL_COST_ESTIMATION)

    await saveCostsButton(wrapper, en.industry.costEstimation.saveCostsToProject).trigger('click')
    await flushPromises()

    const patchCalls = vi.mocked(apiRequest).mock.calls.filter(([, options]) => (options as { method?: string } | undefined)?.method === 'PATCH')
    expect(patchCalls).toHaveLength(0)
  })

  it('shows the amounts and enables saving the costs when every step is known', async () => {
    const wrapper = await mountLoadedCostEstimationTab(KNOWN_JOB_INSTALL_COST_ESTIMATION)
    const labels = en.industry.costEstimation

    expect(summaryCard(wrapper, labels.jobInstallCost).find('p:nth-of-type(2)').text()).toBe('9,900')
    expect(stepCells(wrapper, 0)).toContain('9,900')
    expect(wrapper.text()).not.toContain(labels.unknown)
    expect(saveCostsButton(wrapper, labels.saveCostsToProject).attributes('disabled')).toBeUndefined()
  })
})
