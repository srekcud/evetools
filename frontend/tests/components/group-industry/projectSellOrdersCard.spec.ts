import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ProjectSellOrdersCard from '@/components/group-industry/ProjectSellOrdersCard.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type { ProjectSellOrders } from '@/stores/group-industry/types'
import { sellOrder } from '../../sellOrderFixtures'

const NOW = Date.parse('2026-11-10T12:15:00+00:00')
const ROW = '[data-testid="sell-order-row"]'
const POSITION = '[data-testid="sell-order-position"]'
const COPY_BUTTON = '[data-testid="sell-order-copy-price"]'

function sellOrdersOf(overrides: Partial<ProjectSellOrders> = {}): ProjectSellOrders {
  return {
    projectId: 'project-sabre',
    orders: [sellOrder()],
    charactersToReconnect: [],
    syncedAt: '2026-11-10T12:15:00+00:00',
    ...overrides,
  }
}

function mountCard(sellOrders: ProjectSellOrders, locale: 'en' | 'fr' = 'en'): VueWrapper {
  return mount(ProjectSellOrdersCard, {
    props: { sellOrders, now: NOW },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function normalized(text: string): string {
  return text.replace(/[  ]/g, ' ')
}

describe('ProjectSellOrdersCard', () => {
  it('shows the order, the market, my price, the best price and the rank with the time spent undercut', () => {
    const card = mountCard(sellOrdersOf())

    expect(normalized(card.get(ROW).text())).toEqual(expect.stringContaining('Hammerhead II'))
    expect({
      rows: card.findAll(ROW).length,
      text: normalized(card.get(ROW).text()),
      position: normalized(card.get(POSITION).text()),
      positionState: card.get(POSITION).attributes('data-state'),
    }).toMatchObject({
      rows: 1,
      text: expect.stringMatching(/×4.*Aria Solberg.*Jita IV - Moon 4.*760\.00 K.*690\.00 K/),
      position: '#3 · undercut for 5 min',
      positionState: 'undercut',
    })
  })

  it('counts the undercut time in hours after one hour', () => {
    const card = mountCard(sellOrdersOf({ orders: [sellOrder({ undercutSince: '2026-11-10T10:10:00+00:00' })] }))

    expect(normalized(card.get(POSITION).text())).toBe('#3 · undercut for 2 h')
  })

  it('shows a first order without a copy button', () => {
    const card = mountCard(sellOrdersOf({ orders: [sellOrder({ state: 'first', rank: 1, bestPrice: 760_000, undercutSince: null, suggestedPrice: null })] }))

    expect({
      position: normalized(card.get(POSITION).text()),
      state: card.get(POSITION).attributes('data-state'),
      copyButtons: card.findAll(COPY_BUTTON).length,
    }).toEqual({ position: '1st', state: 'first', copyButtons: 0 })
  })

  it('shows the cause of an unknown position instead of a rank or a best price, and no copy button', () => {
    const notFollowed = mountCard(sellOrdersOf({ orders: [sellOrder({ state: 'unknown', unknownReason: 'market_not_followed', rank: null, bestPrice: null, undercutSince: null, suggestedPrice: null, marketName: null })] }))
    const noData = mountCard(sellOrdersOf({ orders: [sellOrder({ state: 'unknown', unknownReason: 'no_market_data', rank: null, bestPrice: null, undercutSince: null, suggestedPrice: null })] }))

    expect({
      notFollowed: normalized(notFollowed.get(POSITION).text()),
      notFollowedState: notFollowed.get(POSITION).attributes('data-state'),
      noData: normalized(noData.get(POSITION).text()),
      copyButtons: notFollowed.findAll(COPY_BUTTON).length + noData.findAll(COPY_BUTTON).length,
      unknownBestPrice: noData.get('[data-testid="sell-order-best-price"]').text(),
    }).toEqual({
      notFollowed: 'Market not followed',
      notFollowedState: 'unknown',
      noData: 'No market data',
      copyButtons: 0,
      unknownBestPrice: 'Unknown value',
    })
  })

  it('copies the suggested price as a plain number and never modifies an order', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const card = mountCard(sellOrdersOf())

    await card.get(COPY_BUTTON).trigger('click')

    expect({ label: normalized(card.get(COPY_BUTTON).text()), copied: writeText.mock.calls }).toEqual({
      label: 'Copy 689.90 K',
      copied: [['689900']],
    })
    vi.unstubAllGlobals()
  })

  it('invites the viewer to log in again with each character that lacks the scope', () => {
    const card = mountCard(sellOrdersOf({ orders: [], charactersToReconnect: ['Brann Holt', 'Corin Vale'] }))

    expect(normalized(card.get('[data-testid="sell-orders-reconnect"]').text())).toEqual(expect.stringContaining('Brann Holt, Corin Vale'))
  })

  it('says that the orders are read-only and refreshed every 5 minutes, and shows an empty state', () => {
    const card = mountCard(sellOrdersOf({ orders: [] }))

    expect({
      empty: card.find('[data-testid="sell-orders-empty"]').exists(),
      hint: normalized(card.get('[data-testid="sell-orders-hint"]').text()),
      rows: card.findAll(ROW).length,
    }).toMatchObject({ empty: true, hint: expect.stringContaining('every 5 minutes'), rows: 0 })
  })

  it('has the same wording keys in French', () => {
    expect(normalized(mountCard(sellOrdersOf(), 'fr').get(POSITION).text())).toBe('#3 · sous-coté depuis 5 min')
  })
})
