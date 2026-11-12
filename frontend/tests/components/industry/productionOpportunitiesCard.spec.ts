import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProductionOpportunitiesCard from '@/components/industry/ProductionOpportunitiesCard.vue'
import { apiRequest } from '@/services/api'
import type { ProductionOpportunitiesResponse, ProductionOpportunity } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPPORTUNITIES_URL = '/industry/opportunities'
const CAPITAL_STORAGE_KEY = 'industry.opportunities.capital'
const CAPITAL_CAP = 59_500_000
const HAMMERHEAD_TYPE_ID = 2_446
const HOBGOBLIN_TYPE_ID = 2_454
const SENTRY_TYPE_ID = 2_464

const HAMMERHEAD_AT_JITA: ProductionOpportunity = {
  typeId: HAMMERHEAD_TYPE_ID,
  typeName: 'Hammerhead II',
  categoryLabel: 'T2',
  market: 'jita',
  sellPrice: 760_000,
  dailyVolume: 180,
  unitCost: 659_773.47,
  profitPerUnit: 64_088.53,
  marginPercent: 9.71,
  weeklyQuantity: 90,
  limitedBy: 'capital',
  weeklyProfit: 5_767_967.7,
  iskPerSlotHour: 138_819.92,
  missingSkills: [],
  groupHint: true,
}

const HOBGOBLIN_AT_CAPITAL: ProductionOpportunity = {
  ...HAMMERHEAD_AT_JITA,
  typeId: HOBGOBLIN_TYPE_ID,
  typeName: 'Hobgoblin II',
  market: 'capital',
  weeklyQuantity: 9,
  limitedBy: 'market',
  weeklyProfit: 1_334_320,
  iskPerSlotHour: 321_135.99,
  groupHint: false,
}

const SENTRY_NEEDING_A_SKILL: ProductionOpportunity = {
  ...HAMMERHEAD_AT_JITA,
  typeId: SENTRY_TYPE_ID,
  typeName: 'Curator II',
  weeklyQuantity: null,
  limitedBy: null,
  weeklyProfit: null,
  iskPerSlotHour: null,
  missingSkills: [{ skillId: 11_433, name: 'High Energy Physics', level: 5 }],
  groupHint: true,
}

function opportunities(rows: ProductionOpportunity[], overrides: Partial<ProductionOpportunitiesResponse> = {}): ProductionOpportunitiesResponse {
  return {
    capitalCap: null,
    capitalStructureName: 'Perimeter - Tranquility Trade Hub',
    freeSlots: { manufacturing: 14, reaction: 3 },
    sellShareOfDailyVolume: 0.1,
    salesFeesKnown: true,
    unknownProductCount: 0,
    rows,
    ...overrides,
  }
}

async function mountCard(response: ProductionOpportunitiesResponse | Error) {
  vi.mocked(apiRequest).mockImplementation((async () => {
    if (response instanceof Error) throw response
    return response
  }) as never)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProductionOpportunitiesCard, { global: { plugins: [i18n] } })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

function requestedUrls(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([url]) => url as string)
}

function row(wrapper: Awaited<ReturnType<typeof mountCard>>['wrapper'], typeId: number, market: string) {
  return wrapper.get(`[data-testid="opportunity-row-${typeId}-${market}"]`)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  localStorage.clear()
})

describe('ProductionOpportunitiesCard', () => {
  it('loads both markets by weekly profit without a capital cap and shows quantity, limit, profit and ISK per slot hour', async () => {
    const { wrapper, t } = await mountCard(opportunities([HAMMERHEAD_AT_JITA]))

    expect(requestedUrls()).toEqual([`${OPPORTUNITIES_URL}?market=all&sort=week`])
    const hammerhead = row(wrapper, HAMMERHEAD_TYPE_ID, 'jita')
    expect(hammerhead.text()).toContain('Hammerhead II')
    expect(hammerhead.get('[data-testid="opportunity-quantity"]').text()).toContain('90')
    expect(hammerhead.get('[data-testid="opportunity-limit"]').text()).toContain(t('industry.opportunities.limitedBy.capital'))
    expect(hammerhead.get('[data-testid="opportunity-profit"]').text()).toContain('5,767,968')
    expect(hammerhead.get('[data-testid="opportunity-isk-hour"]').text()).toContain('138,820')
  })

  it('reloads with the chosen market and the chosen sort', async () => {
    const { wrapper } = await mountCard(opportunities([HAMMERHEAD_AT_JITA]))

    await wrapper.get('[data-testid="opportunities-market-jita"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="opportunities-sort-hour"]').trigger('click')
    await flushPromises()

    expect(requestedUrls()).toEqual([
      `${OPPORTUNITIES_URL}?market=all&sort=week`,
      `${OPPORTUNITIES_URL}?market=jita&sort=week`,
      `${OPPORTUNITIES_URL}?market=jita&sort=hour`,
    ])
    expect(wrapper.get('[data-testid="opportunities-sort-hour"]').attributes('aria-pressed')).toBe('true')
  })

  it('sends the capital cap once entered and keeps it for the next visit', async () => {
    const { wrapper } = await mountCard(opportunities([HAMMERHEAD_AT_JITA]))

    const input = wrapper.get('[data-testid="opportunities-capital-input"]')
    await input.setValue(String(CAPITAL_CAP))
    await input.trigger('change')
    await flushPromises()

    expect(requestedUrls().at(-1)).toBe(`${OPPORTUNITIES_URL}?market=all&sort=week&capital=${CAPITAL_CAP}`)
    expect(localStorage.getItem(CAPITAL_STORAGE_KEY)).toBe(String(CAPITAL_CAP))

    vi.mocked(apiRequest).mockClear()
    await mountCard(opportunities([HAMMERHEAD_AT_JITA]))

    expect(requestedUrls()).toEqual([`${OPPORTUNITIES_URL}?market=all&sort=week&capital=${CAPITAL_CAP}`])
  })

  it('does not offer the capital market when the player has no preferred market structure', async () => {
    const { wrapper } = await mountCard(opportunities([HAMMERHEAD_AT_JITA], { capitalStructureName: null }))

    expect(wrapper.get('[data-testid="opportunities-market-capital"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="opportunities-market-jita"]').attributes('disabled')).toBeUndefined()
  })

  it('shows the group hint only on the products that need a group', async () => {
    const { wrapper } = await mountCard(opportunities([HAMMERHEAD_AT_JITA, HOBGOBLIN_AT_CAPITAL]))

    expect(row(wrapper, HAMMERHEAD_TYPE_ID, 'jita').find('[data-testid="opportunity-group-hint"]').exists()).toBe(true)
    expect(row(wrapper, HOBGOBLIN_TYPE_ID, 'capital').find('[data-testid="opportunity-group-hint"]').exists()).toBe(false)
  })

  it('greys a product whose blueprint skill is missing and names the skill instead of a quantity', async () => {
    const { wrapper, t } = await mountCard(opportunities([SENTRY_NEEDING_A_SKILL]))

    const sentry = row(wrapper, SENTRY_TYPE_ID, 'jita')
    expect(sentry.attributes('data-state')).toBe('blocked')
    expect(sentry.text()).toContain(t('industry.opportunities.required', { skills: 'High Energy Physics 5' }))
    expect(sentry.find('[data-testid="opportunity-quantity"]').text()).not.toMatch(/\b0\b/)
    expect(sentry.find('[data-testid="opportunity-group-hint"]').exists()).toBe(true)
  })

  it('shows an unknown quantity with its cause, never as zero, when the free slots are unknown', async () => {
    const response = opportunities([{ ...HAMMERHEAD_AT_JITA, weeklyQuantity: null, limitedBy: null, weeklyProfit: null, iskPerSlotHour: null }], { freeSlots: null })
    const { wrapper, t } = await mountCard(response)

    const hammerhead = row(wrapper, HAMMERHEAD_TYPE_ID, 'jita')
    expect(hammerhead.get('[data-testid="opportunity-quantity"]').text()).toContain(t('industry.opportunities.unknown.slots'))
    expect(hammerhead.get('[data-testid="opportunity-profit"]').text()).not.toMatch(/\b0\b/)
  })

  it('tells how many products were left out for unknown data and when the seller fees are unknown', async () => {
    const { wrapper, t } = await mountCard(opportunities([], { unknownProductCount: 37, salesFeesKnown: false }))

    expect(wrapper.get('[data-testid="opportunities-unknown-count"]').text()).toContain(t('industry.opportunities.unknownCount', { count: 37 }))
    expect(wrapper.get('[data-testid="opportunities-fees-unknown"]').text()).toContain(t('industry.opportunities.feesUnknown'))
  })

  it('says nothing profitable is left to produce, with the left-out count, when no row remains', async () => {
    const { wrapper, t } = await mountCard(opportunities([], { unknownProductCount: 12 }))

    expect(wrapper.get('[data-testid="opportunities-empty"]').text()).toContain(t('industry.opportunities.empty'))
    expect(wrapper.get('[data-testid="opportunities-unknown-count"]').text()).toContain(t('industry.opportunities.unknownCount', { count: 12 }))
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('shows the error when the ranking cannot be loaded', async () => {
    const { wrapper } = await mountCard(new Error('Ranking unavailable (fictif)'))

    expect(wrapper.get('[data-testid="opportunities-error"]').text()).toContain('Ranking unavailable (fictif)')
  })
})
