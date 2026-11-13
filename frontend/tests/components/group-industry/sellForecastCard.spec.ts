import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import SellForecastCard from '@/components/group-industry/SellForecastCard.vue'
import { apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { Distribution, GroupProject, SellForecast, SellForecastMarket, SellForecastProduct } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NYX_PROJECT_ID = 'project-nyx-batch'
const SABRE_PROJECT_ID = 'project-sabre-batch'
const HAIL_L_TYPE_ID = 12_779
const BARRAGE_L_TYPE_ID = 12_801
const PERIMETER_STRUCTURE = { id: 1_042_000_000_001, name: 'Perimeter - Tranquility Trade Hub' }
const PARTIAL_JITA_COVERAGE_PERCENT = 60
const API_FAILURE = 'Sell forecast unavailable (fictif)'
const CARD = '[data-testid="sell-forecast-card"]'
const ROW_OF = (typeId: number) => `[data-testid="sell-forecast-row-${typeId}"]`

const HAIL_L_STRUCTURE: SellForecastMarket = {
  name: PERIMETER_STRUCTURE.name,
  unitPrice: 800_000,
  coveragePercent: null,
  feeRate: 0.04755,
  netUnitPrice: 761_960,
  netRevenue: 4_571_760,
  daysToSell: 62.5,
}

const HAIL_L_JITA: SellForecastMarket = {
  name: 'Jita IV - Moon 4',
  unitPrice: 760_000,
  coveragePercent: null,
  feeRate: 0.04755,
  netUnitPrice: 723_862,
  netRevenue: 4_343_172,
  daysToSell: 12.5,
}

const BARRAGE_L_STRUCTURE: SellForecastMarket = {
  name: PERIMETER_STRUCTURE.name,
  unitPrice: 1_300_000,
  coveragePercent: null,
  feeRate: 0.04755,
  netUnitPrice: 1_238_185,
  netRevenue: 30_954_625,
  daysToSell: 9,
}

const BARRAGE_L_JITA: SellForecastMarket = {
  name: 'Jita IV - Moon 4',
  unitPrice: 1_250_000,
  coveragePercent: null,
  feeRate: 0.04755,
  netUnitPrice: 1_190_562.5,
  netRevenue: 29_764_062.5,
  daysToSell: 4,
}

const UNKNOWN_MARKET: SellForecastMarket = {
  name: PERIMETER_STRUCTURE.name,
  unitPrice: null,
  coveragePercent: null,
  feeRate: 0.04755,
  netUnitPrice: null,
  netRevenue: null,
  daysToSell: 40,
}

const HAIL_L: SellForecastProduct = { typeId: HAIL_L_TYPE_ID, typeName: 'Hail L', quantity: 6, structure: HAIL_L_STRUCTURE, jita: HAIL_L_JITA }
const BARRAGE_L: SellForecastProduct = { typeId: BARRAGE_L_TYPE_ID, typeName: 'Barrage L', quantity: 25, structure: BARRAGE_L_STRUCTURE, jita: BARRAGE_L_JITA }

const FULLY_PRICED_FORECAST: SellForecast = {
  projectId: NYX_PROJECT_ID,
  sellStructure: PERIMETER_STRUCTURE,
  products: [BARRAGE_L, HAIL_L],
  totals: { structureNetRevenue: 35_526_385, jitaNetRevenue: 34_107_234.5, structureUnknownProductCount: 0, jitaUnknownProductCount: 0, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: 31_926_385, jitaProfit: 30_507_234.5 },
}

const WITHOUT_STRUCTURE_FORECAST: SellForecast = {
  projectId: NYX_PROJECT_ID,
  sellStructure: null,
  products: [{ ...BARRAGE_L, structure: null }, { ...HAIL_L, structure: null }],
  totals: { structureNetRevenue: null, jitaNetRevenue: 34_107_234.5, structureUnknownProductCount: 0, jitaUnknownProductCount: 0, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: null, jitaProfit: 30_507_234.5 },
}

const NOTHING_TO_SELL_FORECAST: SellForecast = {
  projectId: NYX_PROJECT_ID,
  sellStructure: PERIMETER_STRUCTURE,
  products: [],
  totals: { structureNetRevenue: null, jitaNetRevenue: null, structureUnknownProductCount: 0, jitaUnknownProductCount: 0, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: null, jitaProfit: null },
}

function distributionOf(id: string, totalRevenue: number): Distribution {
  return {
    id,
    totalRevenue,
    brokerFee: null,
    salesTax: null,
    netRevenue: null,
    totalProjectCost: null,
    marginPercent: null,
    awaitingSales: true,
    unsoldProducts: [],
    projectedMarginPercent: null,
    members: [],
  }
}

function projectOf(sellStructureId: number | null): GroupProject {
  return {
    id: NYX_PROJECT_ID,
    name: 'Nyx batch',
    status: 'selling',
    shortLinkCode: 'nyx731',
    containerName: null,
    ownerCharacterName: 'Selene Vance',
    ownerCorporationId: 98_000_007,
    membersCount: 1,
    items: [{ typeId: 23_913, typeName: 'Nyx', meLevel: 8, teLevel: 16, runs: 2 }],
    totalBomValue: 41_000_000_000,
    fulfillmentPercent: 40,
    approvedContributionValue: 16_400_000_000,
    brokerFeePercent: 2.8,
    salesTaxPercent: 3.4,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-11-02T08:30:00+00:00',
    myRole: 'owner',
    myStatus: 'accepted',
    private: false,
    notes: null,
    acceptsOutsideFavoriteSystem: false,
    excludesInvention: false,
    minimumOutsideSaving: 5,
    sellStructureId,
    sellStructureName: sellStructureId === null ? null : PERIMETER_STRUCTURE.name,
  }
}

function sellForecastPath(projectId: string): string {
  return `/industry/projects/${projectId}/sell-forecast`
}

function sellForecastCalls(projectId: string = NYX_PROJECT_ID): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === sellForecastPath(projectId)).length
}

function answerWith(forecast: SellForecast): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === sellForecastPath(forecast.projectId)) return forecast
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountCard(projectId: string = NYX_PROJECT_ID) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(SellForecastCard, { global: { plugins: [i18n] }, props: { projectId } })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

async function mountCardOf(forecast: SellForecast) {
  answerWith(forecast)
  return mountCard(forecast.projectId)
}

function cardOf(wrapper: VueWrapper) {
  return wrapper.get(CARD)
}

function cellOf(wrapper: VueWrapper, typeId: number, cell: string) {
  return cardOf(wrapper).get(`${ROW_OF(typeId)} [data-testid="sell-forecast-${cell}"]`)
}

function profitOf(wrapper: VueWrapper, column: 'structure' | 'jita') {
  return cardOf(wrapper).get(`[data-testid="sell-forecast-profit-${column}"]`)
}

function totalOf(wrapper: VueWrapper, column: 'structure' | 'jita') {
  return cardOf(wrapper).get(`[data-testid="sell-forecast-total-${column}"]`)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distributionOf(NYX_PROJECT_ID, 1_000_000)
  useGroupProjectStore().currentProject = projectOf(PERIMETER_STRUCTURE.id)
  vi.mocked(apiRequest).mockReset()
})

describe('SellForecastCard', () => {
  it('loads the sell forecast of the project once and shows its title and the profit basis mention', async () => {
    const { wrapper, t } = await mountCardOf(FULLY_PRICED_FORECAST)

    expect(sellForecastCalls()).toBe(1)
    expect(cardOf(wrapper).text()).toContain(t('groupIndustry.sellForecast.title'))
    expect(cardOf(wrapper).text()).toContain(t('groupIndustry.sellForecast.profitBasis'))
  })

  it('names the sell structure of the project in the subtitle', async () => {
    const { wrapper, t } = await mountCardOf(FULLY_PRICED_FORECAST)

    expect(cardOf(wrapper).get('[data-testid="sell-forecast-subtitle"]').text()).toBe(t('groupIndustry.sellForecast.sellStructure', { name: PERIMETER_STRUCTURE.name }))
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-no-structure"]').exists()).toBe(false)
  })

  it('lists the products in the order of the forecast with the quantity left to sell', async () => {
    const { wrapper } = await mountCardOf(FULLY_PRICED_FORECAST)

    const rows = cardOf(wrapper).findAll('[data-testid^="sell-forecast-row-"]')
    expect(rows.map(row => row.attributes('data-testid'))).toEqual([`sell-forecast-row-${BARRAGE_L_TYPE_ID}`, `sell-forecast-row-${HAIL_L_TYPE_ID}`])
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'product').text()).toContain('Barrage L')
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'quantity').text()).toContain('25')
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'product').text()).toContain('Hail L')
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'quantity').text()).toContain('6')
  })

  it('shows the net unit price and the net revenue of each product in the structure column and in the Jita column', async () => {
    const { wrapper } = await mountCardOf(FULLY_PRICED_FORECAST)

    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'structure-net-unit').text()).toContain('761.96 K')
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'structure-net-revenue').text()).toContain('4.57 M')
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-unit').text()).toContain('723.86 K')
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-revenue').text()).toContain('4.34 M')
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'structure-net-unit').text()).toContain('1.24 M')
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'structure-net-revenue').text()).toContain('30.95 M')
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'jita-net-unit').text()).toContain('1.19 M')
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'jita-net-revenue').text()).toContain('29.76 M')
  })

  it('shows the net revenue total of each column without any unknown product count when every product is priced', async () => {
    const { wrapper, t } = await mountCardOf(FULLY_PRICED_FORECAST)

    expect(totalOf(wrapper, 'structure').text()).toContain('35.53 M')
    expect(totalOf(wrapper, 'jita').text()).toContain('34.11 M')
    expect(totalOf(wrapper, 'structure').findComponent(UnknownValue).exists()).toBe(false)
    expect(totalOf(wrapper, 'jita').findComponent(UnknownValue).exists()).toBe(false)
    expect(totalOf(wrapper, 'structure').text()).not.toContain(t('groupIndustry.sellForecast.unknownProducts', { count: 0 }))
  })

  it('shows an unknown total with the count of unknown products when a product has no price in the structure, and keeps the Jita total', async () => {
    const withUnknownStructurePrice: SellForecast = {
      ...FULLY_PRICED_FORECAST,
      products: [BARRAGE_L, { ...HAIL_L, structure: UNKNOWN_MARKET }],
      totals: { structureNetRevenue: null, jitaNetRevenue: 34_107_234.5, structureUnknownProductCount: 1, jitaUnknownProductCount: 0, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: null, jitaProfit: 30_507_234.5 },
    }
    const { wrapper, t } = await mountCardOf(withUnknownStructurePrice)

    expect(totalOf(wrapper, 'structure').findComponent(UnknownValue).exists()).toBe(true)
    expect(totalOf(wrapper, 'structure').text()).toContain(t('groupIndustry.sellForecast.unknownProducts', { count: 1 }))
    expect(totalOf(wrapper, 'structure').text()).not.toContain('30.95 M')
    expect(totalOf(wrapper, 'jita').text()).toContain('34.11 M')
    expect(totalOf(wrapper, 'jita').findComponent(UnknownValue).exists()).toBe(false)
  })

  it('shows an unknown Jita total with its count of unknown products when fees are unknown for every product', async () => {
    const withUnknownFees: SellForecast = {
      ...FULLY_PRICED_FORECAST,
      products: [
        { ...BARRAGE_L, jita: { ...BARRAGE_L_JITA, feeRate: null, netUnitPrice: null, netRevenue: null } },
        { ...HAIL_L, jita: { ...HAIL_L_JITA, feeRate: null, netUnitPrice: null, netRevenue: null } },
      ],
      totals: { structureNetRevenue: 35_526_385, jitaNetRevenue: null, structureUnknownProductCount: 0, jitaUnknownProductCount: 2, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: 31_926_385, jitaProfit: null },
    }
    const { wrapper, t } = await mountCardOf(withUnknownFees)

    expect(totalOf(wrapper, 'jita').findComponent(UnknownValue).exists()).toBe(true)
    expect(totalOf(wrapper, 'jita').text()).toContain(t('groupIndustry.sellForecast.unknownProducts', { count: 2 }))
    expect(totalOf(wrapper, 'structure').text()).toContain('35.53 M')
  })

  it('shows a null net unit price and a null net revenue of a product as unknown values, never as zero or empty, and keeps the other cells', async () => {
    const withUnknownHailL: SellForecast = {
      ...FULLY_PRICED_FORECAST,
      products: [BARRAGE_L, { ...HAIL_L, structure: UNKNOWN_MARKET, jita: { ...HAIL_L_JITA, netUnitPrice: null, netRevenue: null } }],
    }
    const { wrapper } = await mountCardOf(withUnknownHailL)

    for (const cell of ['structure-net-unit', 'structure-net-revenue', 'jita-net-unit', 'jita-net-revenue']) {
      const unknownCell = cellOf(wrapper, HAIL_L_TYPE_ID, cell)
      expect(unknownCell.findComponent(UnknownValue).exists()).toBe(true)
      expect(unknownCell.text()).not.toMatch(/\d/)
    }
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'structure-net-unit').findComponent(UnknownValue).exists()).toBe(false)
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'jita-net-revenue').text()).toContain('29.76 M')
  })

  it('shows a null net revenue as unknown while keeping a known net unit price when only the revenue is unknown', async () => {
    const withOnlyUnknownRevenue: SellForecast = {
      ...FULLY_PRICED_FORECAST,
      products: [{ ...HAIL_L, jita: { ...HAIL_L_JITA, netRevenue: null } }],
      totals: { structureNetRevenue: 4_571_760, jitaNetRevenue: null, structureUnknownProductCount: 0, jitaUnknownProductCount: 1, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: 4_568_160, jitaProfit: null },
    }
    const { wrapper } = await mountCardOf(withOnlyUnknownRevenue)

    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-unit').text()).toContain('723.86 K')
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-revenue').findComponent(UnknownValue).exists()).toBe(true)
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-revenue').text()).not.toMatch(/\d/)
  })

  it('shows the cost of the remaining materials once, under the total, and the forecast profit of each column', async () => {
    const { wrapper, t } = await mountCardOf(FULLY_PRICED_FORECAST)

    const remainingCost = cardOf(wrapper).get('[data-testid="sell-forecast-remaining-cost"]')
    expect(remainingCost.text()).toContain(t('groupIndustry.sellForecast.remainingCost'))
    expect(remainingCost.text()).toContain('3.60 M')
    expect(remainingCost.findComponent(UnknownValue).exists()).toBe(false)
    expect(cardOf(wrapper).get('[data-testid="sell-forecast-profit"]').text()).toContain(t('groupIndustry.sellForecast.plannedProfit'))
    expect(profitOf(wrapper, 'structure').text()).toContain('31.93 M')
    expect(profitOf(wrapper, 'jita').text()).toContain('30.51 M')
  })

  it('shows a remaining cost of zero as a known value, not as unknown', async () => {
    const { wrapper } = await mountCardOf({
      ...FULLY_PRICED_FORECAST,
      totals: { ...FULLY_PRICED_FORECAST.totals, remainingCost: 0, structureProfit: 35_526_385, jitaProfit: 34_107_234.5 },
    })

    expect(cardOf(wrapper).get('[data-testid="sell-forecast-remaining-cost"]').findComponent(UnknownValue).exists()).toBe(false)
    expect(profitOf(wrapper, 'structure').text()).toContain('35.53 M')
  })

  it('shows an unknown remaining cost with the count of material lines without price, and an unknown profit in each column', async () => {
    const { wrapper, t } = await mountCardOf({
      ...FULLY_PRICED_FORECAST,
      totals: { ...FULLY_PRICED_FORECAST.totals, remainingCost: null, remainingCostUnknownLineCount: 2, structureProfit: null, jitaProfit: null },
    })

    const remainingCost = cardOf(wrapper).get('[data-testid="sell-forecast-remaining-cost"]')
    expect(remainingCost.findComponent(UnknownValue).exists()).toBe(true)
    expect(remainingCost.text()).toContain(t('groupIndustry.sellForecast.remainingCostUnknownLines', { count: 2 }))
    expect(remainingCost.text()).not.toContain('3.60 M')
    expect(profitOf(wrapper, 'structure').findComponent(UnknownValue).exists()).toBe(true)
    expect(profitOf(wrapper, 'structure').text()).not.toMatch(/\d/)
    expect(profitOf(wrapper, 'jita').findComponent(UnknownValue).exists()).toBe(true)
  })

  it('shows an unknown structure profit while keeping the Jita profit when the structure revenue is unknown', async () => {
    const { wrapper } = await mountCardOf({
      ...FULLY_PRICED_FORECAST,
      totals: { ...FULLY_PRICED_FORECAST.totals, structureNetRevenue: null, structureUnknownProductCount: 1, structureProfit: null },
    })

    expect(profitOf(wrapper, 'structure').findComponent(UnknownValue).exists()).toBe(true)
    expect(profitOf(wrapper, 'jita').text()).toContain('30.51 M')
    expect(profitOf(wrapper, 'jita').findComponent(UnknownValue).exists()).toBe(false)
  })

  it('colors a positive profit in green and a negative profit in red', async () => {
    const { wrapper } = await mountCardOf({
      ...FULLY_PRICED_FORECAST,
      totals: { ...FULLY_PRICED_FORECAST.totals, remainingCost: 40_000_000, structureProfit: -4_473_615, jitaProfit: -5_892_765.5 },
    })
    expect(profitOf(wrapper, 'structure').classes()).toContain('text-red-400')
    expect(profitOf(wrapper, 'jita').classes()).toContain('text-red-400')
    expect(profitOf(wrapper, 'structure').text()).toContain('4.47 M')

    const positive = await mountCardOf(FULLY_PRICED_FORECAST)
    expect(profitOf(positive.wrapper, 'structure').classes()).toContain('text-emerald-400')
    expect(profitOf(positive.wrapper, 'jita').classes()).toContain('text-emerald-400')
  })

  it('shows the profit of the Jita column only when the project has no sell structure', async () => {
    const { wrapper } = await mountCardOf(WITHOUT_STRUCTURE_FORECAST)

    expect(cardOf(wrapper).find('[data-testid="sell-forecast-profit-structure"]').exists()).toBe(false)
    expect(profitOf(wrapper, 'jita').text()).toContain('30.51 M')
    expect(cardOf(wrapper).get('[data-testid="sell-forecast-remaining-cost"]').text()).toContain('3.60 M')
  })

  it('marks the coverage of a partial Jita price on its row and not on the structure cells', async () => {
    const withPartialJita: SellForecast = {
      ...FULLY_PRICED_FORECAST,
      products: [{ ...HAIL_L, jita: { ...HAIL_L_JITA, coveragePercent: PARTIAL_JITA_COVERAGE_PERCENT } }],
    }
    const { wrapper, t } = await mountCardOf(withPartialJita)
    const marker = t('industry.partialPrice.marker', { percent: PARTIAL_JITA_COVERAGE_PERCENT })

    expect(cardOf(wrapper).get(ROW_OF(HAIL_L_TYPE_ID)).text()).toContain(marker)
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'structure-net-unit').text()).not.toContain(marker)
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'structure-net-revenue').text()).not.toContain(marker)
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-unit').text()).toContain('723.86 K')
  })

  it('shows no coverage marker when no price is partial', async () => {
    const { wrapper } = await mountCardOf(FULLY_PRICED_FORECAST)

    expect(cardOf(wrapper).findAll('[data-testid^="sell-forecast-row-"]')).toHaveLength(2)
    expect(cardOf(wrapper).text()).not.toMatch(/%/)
  })

  it('shows the notice and the Jita column only, without any structure column or structure subtitle, when the project has no sell structure', async () => {
    useGroupProjectStore().currentProject = projectOf(null)
    const { wrapper, t } = await mountCardOf(WITHOUT_STRUCTURE_FORECAST)

    expect(cardOf(wrapper).get('[data-testid="sell-forecast-no-structure"]').text()).toBe(t('groupIndustry.sellForecast.noSellStructure'))
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-subtitle"]').exists()).toBe(false)
    expect(cardOf(wrapper).text()).not.toContain(t('groupIndustry.sellForecast.sellStructure', { name: '' }).trim())
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-structure-header"]').exists()).toBe(false)
    expect(cardOf(wrapper).find('[data-testid^="sell-forecast-structure-"]').exists()).toBe(false)
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-total-structure"]').exists()).toBe(false)
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'jita-net-unit').text()).toContain('723.86 K')
    expect(cellOf(wrapper, BARRAGE_L_TYPE_ID, 'jita-net-revenue').text()).toContain('29.76 M')
    expect(totalOf(wrapper, 'jita').text()).toContain('34.11 M')
  })

  it('shows nothing to sell without any total or row when no product is left', async () => {
    const { wrapper, t } = await mountCardOf(NOTHING_TO_SELL_FORECAST)

    expect(cardOf(wrapper).get('[data-testid="sell-forecast-empty"]').text()).toBe(t('groupIndustry.sellForecast.nothingToSell'))
    expect(cardOf(wrapper).find('[data-testid^="sell-forecast-row-"]').exists()).toBe(false)
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-total"]').exists()).toBe(false)
    expect(cardOf(wrapper).findComponent(UnknownValue).exists()).toBe(false)
  })

  it('shows the load error in the card instead of any row or total', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error(API_FAILURE))
    const { wrapper, t } = await mountCard()

    expect(cardOf(wrapper).get('[data-testid="sell-forecast-error"]').text()).toContain(t('groupIndustry.sellForecast.loadError'))
    expect(cardOf(wrapper).find('[data-testid^="sell-forecast-row-"]').exists()).toBe(false)
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-total"]').exists()).toBe(false)
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-empty"]').exists()).toBe(false)
  })

  it('shows a loading indicator without any row while the forecast is pending', async () => {
    const pending = deferred<SellForecast>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const { wrapper } = await mountCard()

    expect(cardOf(wrapper).find('[data-testid="sell-forecast-loading"]').exists()).toBe(true)
    expect(cardOf(wrapper).find('[data-testid^="sell-forecast-row-"]').exists()).toBe(false)
    expect(cardOf(wrapper).find('[data-testid="sell-forecast-empty"]').exists()).toBe(false)

    pending.resolve(FULLY_PRICED_FORECAST)
    await flushPromises()

    expect(cardOf(wrapper).find('[data-testid="sell-forecast-loading"]').exists()).toBe(false)
    expect(cardOf(wrapper).find(ROW_OF(HAIL_L_TYPE_ID)).exists()).toBe(true)
  })

  it('reloads the forecast when the distribution of the store is replaced after a sale', async () => {
    const afterSale: SellForecast = {
      ...FULLY_PRICED_FORECAST,
      products: [{ ...HAIL_L, quantity: 4, structure: { ...HAIL_L_STRUCTURE, netRevenue: 3_047_840 }, jita: { ...HAIL_L_JITA, netRevenue: 2_895_448 } }],
      totals: { structureNetRevenue: 3_047_840, jitaNetRevenue: 2_895_448, structureUnknownProductCount: 0, jitaUnknownProductCount: 0, remainingCost: 3_600_000, remainingCostUnknownLineCount: 0, structureProfit: 3_044_240, jitaProfit: 2_891_848 },
    }
    vi.mocked(apiRequest)
      .mockResolvedValueOnce(FULLY_PRICED_FORECAST)
      .mockResolvedValueOnce(afterSale)
    const { wrapper } = await mountCard()
    expect(sellForecastCalls()).toBe(1)

    useGroupDistributionStore().distribution = distributionOf(NYX_PROJECT_ID, 2_000_000)
    await flushPromises()

    expect(sellForecastCalls()).toBe(2)
    expect(cellOf(wrapper, HAIL_L_TYPE_ID, 'quantity').text()).toContain('4')
    expect(totalOf(wrapper, 'structure').text()).toContain('3.05 M')
    expect(cardOf(wrapper).find(ROW_OF(BARRAGE_L_TYPE_ID)).exists()).toBe(false)
  })

  it('reloads the forecast when the sell structure of the project changes', async () => {
    answerWith(FULLY_PRICED_FORECAST)
    await mountCard()
    expect(sellForecastCalls()).toBe(1)

    useGroupProjectStore().currentProject = projectOf(PERIMETER_STRUCTURE.id + 1)
    await flushPromises()

    expect(sellForecastCalls()).toBe(2)
  })

  it('does not reload the forecast when the project is replaced by itself with the same sell structure', async () => {
    answerWith(FULLY_PRICED_FORECAST)
    await mountCard()

    useGroupProjectStore().currentProject = projectOf(PERIMETER_STRUCTURE.id)
    await flushPromises()

    expect(sellForecastCalls()).toBe(1)
  })

  it('loads the forecast of the other project when the projectId prop changes', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === sellForecastPath(NYX_PROJECT_ID)) return FULLY_PRICED_FORECAST
      if (endpoint === sellForecastPath(SABRE_PROJECT_ID)) return { ...NOTHING_TO_SELL_FORECAST, projectId: SABRE_PROJECT_ID }
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const { wrapper, t } = await mountCard()
    expect(cardOf(wrapper).find(ROW_OF(HAIL_L_TYPE_ID)).exists()).toBe(true)

    await wrapper.setProps({ projectId: SABRE_PROJECT_ID })
    await flushPromises()

    expect(sellForecastCalls(SABRE_PROJECT_ID)).toBe(1)
    expect(cardOf(wrapper).get('[data-testid="sell-forecast-empty"]').text()).toBe(t('groupIndustry.sellForecast.nothingToSell'))
    expect(cardOf(wrapper).find(ROW_OF(HAIL_L_TYPE_ID)).exists()).toBe(false)
  })
})
