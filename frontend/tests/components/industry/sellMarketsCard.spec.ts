import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SellMarketsCard from '@/components/industry/SellMarketsCard.vue'
import { apiRequest } from '@/services/api'
import type { SellMarketImmediateSale, SellMarketLine, SellMarketResponse } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HAIL_L_TYPE_ID = 12779
const ORDERED_QUANTITY = 50
const EDITED_QUANTITY = 120
const AMBER_DAYS_THRESHOLD = 14
const PARTIAL_JITA_COVERAGE_PERCENT = 60
const PARTIAL_BUY_COVERAGE_PERCENT = 40
const SELL_MARKETS_URL = `/industry/sell-markets/${HAIL_L_TYPE_ID}`
const API_FAILURE = 'Market data unavailable (fictif)'

const SELL_MARKETS_MESSAGES = {
  title: 'Where to sell',
  quantity: 'Quantity',
  price: 'Price',
  volume: 'Volume',
  fees: 'Fees',
  net: 'Net / unit',
  daysToSellAll: 'To sell everything',
  days: '{days} d',
  capitalEstimate: 'estimate, lower bound',
  immediateSale: 'Immediate sale · Jita buy orders',
  averagePrice: 'Average price',
  unknown: {
    noSellOrder: 'No sell order',
    noBuyOrder: 'No buy order',
    noVolume: 'Fewer than 7 days of observed sales',
  },
}

const MESSAGES = { en: { ...en, industry: { ...en.industry, sellMarkets: SELL_MARKETS_MESSAGES } } }

const JITA_LINE: SellMarketLine = {
  key: 'jita',
  name: 'Jita IV - Moon 4',
  unitPrice: 760_000,
  coveragePercent: null,
  dailyVolume: 20,
  volumeKind: 'region_history',
  feeRate: 0.04755,
  netUnitPrice: 723_862,
  daysToSell: 12.5,
}

const CAPITAL_LINE: SellMarketLine = {
  key: 'capital',
  name: 'Perimeter - Tranquility Trade Hub',
  unitPrice: 800_000,
  coveragePercent: null,
  dailyVolume: 8,
  volumeKind: 'structure_estimate',
  feeRate: 0.04755,
  netUnitPrice: 761_960,
  daysToSell: 62.5,
}

const IMMEDIATE_SALE: SellMarketImmediateSale = {
  averageUnitPrice: 694_000,
  coveragePercent: null,
  netUnitPrice: 670_577.5,
}

const NO_IMMEDIATE_SALE: SellMarketImmediateSale = { averageUnitPrice: null, coveragePercent: null, netUnitPrice: null }

function sellMarketsResponse(markets: SellMarketLine[], immediateSale: SellMarketImmediateSale = IMMEDIATE_SALE, quantity = ORDERED_QUANTITY): SellMarketResponse {
  return { typeId: HAIL_L_TYPE_ID, typeName: 'Hail L', quantity, sellShareOfDailyVolume: 0.1, markets, immediateSale }
}

function money(amount: number): string {
  return `${amount.toLocaleString('en-US', { maximumFractionDigits: 0 })} ISK`
}

async function mountCard(response: SellMarketResponse, quantity = ORDERED_QUANTITY) {
  const api = apiOutcomesByPath()
  api.respondTo(SELL_MARKETS_URL, respondWith(response))
  return mountCardWith(api, quantity)
}

async function mountCardWith(api: ReturnType<typeof apiOutcomesByPath>, quantity = ORDERED_QUANTITY) {
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    const outcome = api.answer(url)
    if (outcome === undefined) throw new Error(`Unexpected request ${url}`)
    return outcome
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: MESSAGES })
  const wrapper = mount(SellMarketsCard, {
    global: { plugins: [i18n] },
    props: { typeId: HAIL_L_TYPE_ID, quantity },
  })
  await flushPromises()
  return { wrapper, api, t: i18n.global.t }
}

type Wrapper = Awaited<ReturnType<typeof mountCard>>['wrapper']

function cell(wrapper: Wrapper, marketKey: string, column: string) {
  return wrapper.get(`[data-testid="sell-market-row-${marketKey}"] [data-testid="sell-market-${column}"]`)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('SellMarketsCard', () => {
  it('loads the resource for the quantity and shows the Jita and capital rows with their price, net and days to sell', async () => {
    const { wrapper } = await mountCard(sellMarketsResponse([JITA_LINE, CAPITAL_LINE]))

    expect(apiRequest).toHaveBeenCalledWith(`${SELL_MARKETS_URL}?quantity=${ORDERED_QUANTITY}`)
    expect(wrapper.get('[data-testid="sell-market-row-jita"]').text()).toContain('Jita IV - Moon 4')
    expect(cell(wrapper, 'jita', 'price').text()).toContain(money(760_000))
    expect(cell(wrapper, 'jita', 'net').text()).toContain(money(723_862))
    expect(cell(wrapper, 'jita', 'days').text()).toContain('12.5 d')
    expect(wrapper.get('[data-testid="sell-market-row-capital"]').text()).toContain('Perimeter - Tranquility Trade Hub')
    expect(cell(wrapper, 'capital', 'price').text()).toContain(money(800_000))
    expect(cell(wrapper, 'capital', 'net').text()).toContain(money(761_960))
    expect(cell(wrapper, 'capital', 'days').text()).toContain('62.5 d')
  })

  it('marks the capital volume as an estimate and a lower bound, and not the Jita volume', async () => {
    const { wrapper, t } = await mountCard(sellMarketsResponse([JITA_LINE, CAPITAL_LINE]))

    expect(cell(wrapper, 'capital', 'volume').text()).toContain(t('industry.sellMarkets.capitalEstimate'))
    expect(cell(wrapper, 'jita', 'volume').text()).not.toContain(t('industry.sellMarkets.capitalEstimate'))
  })

  it('shows a price without sell order as an unknown value with its cause, never as zero', async () => {
    const { wrapper, t } = await mountCard(sellMarketsResponse([{ ...JITA_LINE, unitPrice: null, netUnitPrice: null }, CAPITAL_LINE]))

    const unknownPrice = cell(wrapper, 'jita', 'price').get('[data-testid="unknown-value"]')
    expect(unknownPrice.text()).toBe(t('industry.sellMarkets.unknown.noSellOrder'))
    expect(unknownPrice.classes()).toEqual(expect.arrayContaining(['border-dashed', 'border-violet-400', 'text-violet-300']))
    expect(cell(wrapper, 'jita', 'price').text()).not.toMatch(/\d/)
    expect(cell(wrapper, 'jita', 'net').text()).not.toMatch(/\d/)
    expect(cell(wrapper, 'capital', 'price').text()).toContain(money(800_000))
  })

  it('shows the days to sell of an unknown capital volume as an unknown value with its cause, never as zero', async () => {
    const { wrapper, t } = await mountCard(sellMarketsResponse([JITA_LINE, { ...CAPITAL_LINE, dailyVolume: null, daysToSell: null }]))

    for (const column of ['volume', 'days']) {
      const unknownValue = cell(wrapper, 'capital', column).get('[data-testid="unknown-value"]')
      expect(unknownValue.text()).toContain(t('industry.sellMarkets.unknown.noVolume'))
      expect(unknownValue.classes()).toEqual(expect.arrayContaining(['border-dashed', 'border-violet-400', 'text-violet-300']))
      expect(cell(wrapper, 'capital', column).text()).not.toMatch(/\d/)
    }
    expect(cell(wrapper, 'capital', 'price').text()).toContain(money(800_000))
  })

  it('shows more than 14 days to sell in amber and not 14 days or fewer', async () => {
    const { wrapper } = await mountCard(sellMarketsResponse([{ ...JITA_LINE, daysToSell: AMBER_DAYS_THRESHOLD }, CAPITAL_LINE]))

    expect(cell(wrapper, 'jita', 'days').classes()).not.toContain('text-amber-400')
    expect(cell(wrapper, 'capital', 'days').classes()).toContain('text-amber-400')
  })

  it('keeps the amount of a partial Jita price and marks its coverage', async () => {
    const { wrapper, t } = await mountCard(sellMarketsResponse([{ ...JITA_LINE, coveragePercent: PARTIAL_JITA_COVERAGE_PERCENT }, CAPITAL_LINE]))

    expect(cell(wrapper, 'jita', 'price').text()).toContain(money(760_000))
    expect(cell(wrapper, 'jita', 'price').text()).toContain(t('industry.partialPrice.marker', { percent: PARTIAL_JITA_COVERAGE_PERCENT }))
    expect(cell(wrapper, 'capital', 'price').text()).not.toContain(t('industry.partialPrice.marker', { percent: PARTIAL_JITA_COVERAGE_PERCENT }))
  })

  it('shows the immediate sale at the average price and the net price, marking a partial buy book', async () => {
    const { wrapper, t } = await mountCard(
      sellMarketsResponse([JITA_LINE, CAPITAL_LINE], { ...IMMEDIATE_SALE, coveragePercent: PARTIAL_BUY_COVERAGE_PERCENT }),
    )

    const immediateSale = wrapper.get('[data-testid="immediate-sale"]')
    expect(immediateSale.get('[data-testid="immediate-sale-price"]').text()).toContain(money(694_000))
    expect(immediateSale.get('[data-testid="immediate-sale-price"]').text()).toContain(t('industry.partialPrice.marker', { percent: PARTIAL_BUY_COVERAGE_PERCENT }))
    expect(immediateSale.get('[data-testid="immediate-sale-net"]').text()).toContain(money(670_578))
  })

  it('shows an empty buy book as an unknown immediate sale with its cause, never as zero', async () => {
    const { wrapper, t } = await mountCard(sellMarketsResponse([JITA_LINE, CAPITAL_LINE], NO_IMMEDIATE_SALE))

    const immediateSale = wrapper.get('[data-testid="immediate-sale"]')
    for (const field of ['price', 'net']) {
      const unitPrice = immediateSale.get(`[data-testid="immediate-sale-${field}"]`)
      expect(unitPrice.get('[data-testid="unknown-value"]').text()).toContain(t('industry.sellMarkets.unknown.noBuyOrder'))
      expect(unitPrice.text()).not.toMatch(/\d/)
    }
  })

  it('reloads the resource when the quantity is edited and when the quantity prop changes', async () => {
    const { wrapper, api } = await mountCard(sellMarketsResponse([JITA_LINE]))
    api.respondTo(SELL_MARKETS_URL, respondWith(sellMarketsResponse([{ ...JITA_LINE, daysToSell: 30 }], IMMEDIATE_SALE, EDITED_QUANTITY)))
    api.respondTo(SELL_MARKETS_URL, respondWith(sellMarketsResponse([{ ...JITA_LINE, daysToSell: 31 }], IMMEDIATE_SALE, 700)))

    const quantityInput = wrapper.get('[data-testid="sell-markets-quantity"]')
    await quantityInput.setValue(String(EDITED_QUANTITY))
    await quantityInput.trigger('change')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(`${SELL_MARKETS_URL}?quantity=${EDITED_QUANTITY}`)
    expect(cell(wrapper, 'jita', 'days').text()).toContain('30 d')

    await wrapper.setProps({ quantity: 700 })
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(`${SELL_MARKETS_URL}?quantity=700`)
    expect(cell(wrapper, 'jita', 'days').text()).toContain('31 d')
  })

  it('shows the API error instead of the rows when the resource cannot be loaded', async () => {
    const api = apiOutcomesByPath()
    api.respondTo(SELL_MARKETS_URL, rejectWith(new Error(API_FAILURE)))
    const { wrapper } = await mountCardWith(api)

    expect(wrapper.text()).toContain(API_FAILURE)
    expect(wrapper.find('[data-testid="sell-market-row-jita"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="immediate-sale"]').exists()).toBe(false)
  })
})
