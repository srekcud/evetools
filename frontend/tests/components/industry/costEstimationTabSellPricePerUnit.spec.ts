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

const PROJECT_ID = 'project-281'
const FERNITE_CARBIDE_TYPE_ID = 16673
const HELIUM_FUEL_BLOCK_TYPE_ID = 4247
const RIFTER_TYPE_ID = 587

interface ProducedProduct {
  productTypeId: number
  productTypeName: string
  runs: number
  sellPrice: number | null
}

const EIGHT_RUNS_OF_FERNITE_CARBIDE: ProducedProduct = { productTypeId: FERNITE_CARBIDE_TYPE_ID, productTypeName: 'Fernite Carbide', runs: 8, sellPrice: 2_400_000 }
const ONE_RUN_OF_HELIUM_FUEL_BLOCK: ProducedProduct = { productTypeId: HELIUM_FUEL_BLOCK_TYPE_ID, productTypeName: 'Helium Fuel Block', runs: 1, sellPrice: 100_000 }
const TWO_RUNS_OF_RIFTER: ProducedProduct = { productTypeId: RIFTER_TYPE_ID, productTypeName: 'Rifter', runs: 2, sellPrice: 50_000 }
const ONE_RUN_OF_RIFTER: ProducedProduct = { productTypeId: RIFTER_TYPE_ID, productTypeName: 'Rifter', runs: 1, sellPrice: 50_000 }
const TWO_RUNS_OF_RIFTER_WITHOUT_SELL_PRICE: ProducedProduct = { productTypeId: RIFTER_TYPE_ID, productTypeName: 'Rifter', runs: 2, sellPrice: null }

function projectProducing(product: ProducedProduct): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: product.productTypeName,
    productTypeId: product.productTypeId,
    displayName: null,
    runs: product.runs,
    meLevel: 0,
    teLevel: 0,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: product.sellPrice,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-29T10:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    rootProducts: [
      { typeId: product.productTypeId, typeName: product.productTypeName, runs: product.runs, meLevel: 0, teLevel: 0 },
    ],
    steps: [],
  } as IndustryProject
}

function costEstimationOf(totalCost: number, outputQuantity: number): CostEstimation {
  return {
    id: PROJECT_ID,
    materialCost: totalCost,
    jobInstallCost: 0,
    bpoCost: 0,
    totalCost,
    perUnit: totalCost / outputQuantity,
    outputQuantity,
    materials: [],
    jobInstallSteps: [],
    failedProducts: [],
  }
}

async function mountLoadedCostEstimationTab(product: ProducedProduct, costEstimation: CostEstimation, locale: 'en' | 'fr' = 'en') {
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url.endsWith('/cost-estimation')) return costEstimation
    return { copies: [], totalCopyCost: 0 }
  })
  useProjectsStore().currentProject = projectProducing(product)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(CostEstimationTab, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as { activate: () => Promise<void> }).activate()
  await flushPromises()
  return wrapper
}

type LoadedCostEstimationTab = Awaited<ReturnType<typeof mountLoadedCostEstimationTab>>

function sellPriceComparisonSections(wrapper: LoadedCostEstimationTab, sectionTitle: string) {
  return wrapper.findAll('.eve-card').filter(card => card.find('h4').exists() && card.find('h4').text() === sectionTitle)
}

function sellPriceComparisonSection(wrapper: LoadedCostEstimationTab, sectionTitle: string) {
  const sections = sellPriceComparisonSections(wrapper, sectionTitle)
  expect(sections).toHaveLength(1)
  return sections[0]
}

function sellPricePerUnitLines(wrapper: LoadedCostEstimationTab, sectionTitle: string) {
  return sellPriceComparisonSection(wrapper, sectionTitle)
    .findAll('div')
    .map(line => line.text())
    .filter(text => text.startsWith('@'))
}

function sellPriceComparisonHeader(wrapper: LoadedCostEstimationTab) {
  return sellPriceComparisonSection(wrapper, en.industry.costEstimation.sellPriceComparison).find('h4 + span').text()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('CostEstimationTab sell price per unit', () => {
  it.each([
    ['en', en, '@ 30 / unit'],
    ['fr', fr, '@ 30 / unite'],
  ] as const)('divides the sell price of a reaction project by its output quantity, not by its runs (%s)', async (locale, messages, sellPricePerUnitLine) => {
    const wrapper = await mountLoadedCostEstimationTab(EIGHT_RUNS_OF_FERNITE_CARBIDE, costEstimationOf(92_000, 80_000), locale)

    expect(sellPricePerUnitLines(wrapper, messages.industry.costEstimation.sellPriceComparison)).toEqual([sellPricePerUnitLine])
  })

  it('heads the sell price comparison with the output quantity of a reaction project, not its runs', async () => {
    const wrapper = await mountLoadedCostEstimationTab(EIGHT_RUNS_OF_FERNITE_CARBIDE, costEstimationOf(92_000, 80_000))

    expect(sellPriceComparisonHeader(wrapper)).toBe('80,000 units')
  })

  it('shows the sell price per unit of a one run project producing several units per run', async () => {
    const wrapper = await mountLoadedCostEstimationTab(ONE_RUN_OF_HELIUM_FUEL_BLOCK, costEstimationOf(60_000, 40))

    expect(sellPricePerUnitLines(wrapper, en.industry.costEstimation.sellPriceComparison)).toEqual(['@ 2.50 K / unit'])
  })

  it('divides the sell price of a ship project by its output quantity of one ship per run', async () => {
    const wrapper = await mountLoadedCostEstimationTab(TWO_RUNS_OF_RIFTER, costEstimationOf(19_600, 2))

    expect(sellPricePerUnitLines(wrapper, en.industry.costEstimation.sellPriceComparison)).toEqual(['@ 25.00 K / unit'])
  })

  it('shows no sell price per unit for a project producing a single unit', async () => {
    const wrapper = await mountLoadedCostEstimationTab(ONE_RUN_OF_RIFTER, costEstimationOf(9_800, 1))

    expect(sellPricePerUnitLines(wrapper, en.industry.costEstimation.sellPriceComparison)).toEqual([])
    expect(sellPriceComparisonHeader(wrapper)).toBe('1 unit')
  })

  it('shows no sell price comparison and no zero sell price per unit without a sell price', async () => {
    const wrapper = await mountLoadedCostEstimationTab(TWO_RUNS_OF_RIFTER_WITHOUT_SELL_PRICE, costEstimationOf(19_600, 2))

    expect(sellPriceComparisonSections(wrapper, en.industry.costEstimation.sellPriceComparison)).toHaveLength(0)
    expect(wrapper.text()).not.toContain('@ 0 / unit')
  })
})
