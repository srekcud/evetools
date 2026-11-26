import { enableAutoUnmount } from '@vue/test-utils'
import {
  BROADCAST_NODE,
  COOLANT,
  LONG_TYPE_NAME,
  MECHANICAL_PARTS,
  PLANNER_CATALOG,
  RANKING_CATALOG,
  RANKING_INPUT_A,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  ROBOTICS,
  withMarketProduct,
  withSellPrices,
  withTypeName,
  withTypeVolume,
} from '../../planetaryPlannerFixtures'
import { cellOf, compact, hasFigure, mountRanking, rankingRows, rankingTypeIds, rowOf } from '../../planetaryRankingHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const UNKNOWN_CELLS = ['ranking-margin', 'ranking-profit-day', 'ranking-profit-week', 'ranking-profit-month', 'ranking-purchases']

describe('PiPlannerRanking table', () => {
  it('lists the P2, P3 and P4 ranked by profit a day with their rank, their name and their tier, the unknown volume last', async () => {
    const { wrapper } = await mountRanking()

    expect(rankingTypeIds(wrapper)).toEqual([ROBOTICS, MECHANICAL_PARTS, COOLANT, BROADCAST_NODE])
    expect(rankingRows(wrapper).map(row => row.get('[data-testid="ranking-rank"]').text())).toEqual(['1', '2', '3', '4'])
    expect(cellOf(wrapper, ROBOTICS, 'ranking-product').text()).toContain('Robotics')
    expect(cellOf(wrapper, ROBOTICS, 'ranking-product').text()).toContain('P3')
    expect(cellOf(wrapper, MECHANICAL_PARTS, 'ranking-product').text()).toContain('Mechanical Parts')
    expect(cellOf(wrapper, MECHANICAL_PARTS, 'ranking-product').text()).toContain('P2')
    expect(cellOf(wrapper, BROADCAST_NODE, 'ranking-product').text()).toContain('Broadcast Node')
    expect(cellOf(wrapper, BROADCAST_NODE, 'ranking-product').text()).toContain('P4')
  })

  it('shows the chain made and bought per tier, the capped output, the planets used on the base, the volume with its last date and the trend of a P2', async () => {
    const { wrapper } = await mountRanking({ catalog: RANKING_CATALOG, market: RANKING_MARKET })

    expect(compact(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-chain').text())).toBe('P10·2')
    expect(hasFigure(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-output').text(), '1,000')).toBe(true)
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-output').find('[data-testid="ranking-capped"]').exists()).toBe(true)
    expect(compact(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-planets').text())).toBe('1/1')
    expect(hasFigure(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-volume').text(), '20,000')).toBe(true)
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-volume').attributes('data-last-date')).toBe('2026-11-25')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-trend').text()).toMatch(/(?<![\d.])\+3\.5 ?%/)
  })

  it('shows the margin, the profit a day, a week and a month, the purchases of the week and an enabled plan button for a P2', async () => {
    const { wrapper } = await mountRanking({ catalog: RANKING_CATALOG, market: RANKING_MARKET })

    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-margin').text()).toMatch(/(?<![\d.])56(\.0)? ?%/)
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-profit-day').text()).toBe('560.00 K')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-profit-week').text()).toBe('3.92 M')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-profit-month').text()).toBe('16.80 M')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-purchases').text()).toBe('2.80 M')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-add').attributes('disabled')).toBeUndefined()
  })

  it('shows the maximum output without the capped mark when the volume cap is above it', async () => {
    const { wrapper } = await mountRanking({ catalog: RANKING_CATALOG, market: withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume: 100_000 }) })

    expect(hasFigure(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-output').text(), '3,120')).toBe(true)
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-output').find('[data-testid="ranking-capped"]').exists()).toBe(false)
    expect(compact(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-planets').text())).toBe('1/1')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-profit-day').text()).toBe('1.75 M')
  })

  it('shows every figure of a P3 whose Coolant is bought: chain, output, planets, volume, trend, profits and purchases', async () => {
    const { wrapper } = await mountRanking()

    expect(compact(cellOf(wrapper, ROBOTICS, 'ranking-chain').text())).toBe('P10·2P21·1')
    expect(hasFigure(cellOf(wrapper, ROBOTICS, 'ranking-output').text(), '23')).toBe(true)
    expect(cellOf(wrapper, ROBOTICS, 'ranking-output').find('[data-testid="ranking-capped"]').exists()).toBe(true)
    expect(compact(cellOf(wrapper, ROBOTICS, 'ranking-planets').text())).toBe('1/1')
    expect(hasFigure(cellOf(wrapper, ROBOTICS, 'ranking-volume').text(), '460')).toBe(true)
    expect(cellOf(wrapper, ROBOTICS, 'ranking-volume').attributes('data-last-date')).toBe('2026-11-22')
    expect(cellOf(wrapper, ROBOTICS, 'ranking-trend').text()).toMatch(/(?<![\d.])\+8\.8 ?%/)
    expect(cellOf(wrapper, ROBOTICS, 'ranking-profit-day').text()).toBe('496.19 K')
    expect(cellOf(wrapper, ROBOTICS, 'ranking-profit-week').text()).toBe('3.47 M')
    expect(cellOf(wrapper, ROBOTICS, 'ranking-profit-month').text()).toBe('14.89 M')
    expect(cellOf(wrapper, ROBOTICS, 'ranking-purchases').text()).toBe('1.12 M')
  })

  it('shows a loss with its sign and a falling trend with its sign', async () => {
    const { wrapper } = await mountRanking()

    expect(cellOf(wrapper, COOLANT, 'ranking-profit-day').text()).toBe('-384.00 K')
    expect(cellOf(wrapper, COOLANT, 'ranking-trend').text()).toMatch(/(?<![\d.])\+4\.2 ?%/)
    expect(cellOf(wrapper, MECHANICAL_PARTS, 'ranking-profit-day').text()).toBe('175.44 K')
    expect(cellOf(wrapper, MECHANICAL_PARTS, 'ranking-trend').text()).toMatch(/(?<![\d.])[-−]1\.5 ?%/)
    expect(hasFigure(cellOf(wrapper, MECHANICAL_PARTS, 'ranking-output').text(), '170')).toBe(true)
    expect(hasFigure(cellOf(wrapper, COOLANT, 'ranking-output').text(), '600')).toBe(true)
  })

  it('shows an unknown volume, trend and profit without digits for a product absent from the market', async () => {
    const { wrapper } = await mountRanking()

    for (const testId of ['ranking-volume', 'ranking-trend', 'ranking-profit-day', 'ranking-profit-week', 'ranking-profit-month']) {
      const cell = cellOf(wrapper, BROADCAST_NODE, testId)
      expect(cell.attributes('data-state')).toBe('unknown')
      expect(cell.text()).not.toMatch(/\d/)
    }
  })

  it('shows an unknown margin, profit and purchases when a price of the chain is missing, and keeps the known volume', async () => {
    const { wrapper } = await mountRanking({ catalog: withSellPrices(RANKING_CATALOG, { [RANKING_INPUT_A]: null }), market: RANKING_MARKET })

    for (const testId of UNKNOWN_CELLS) {
      const cell = cellOf(wrapper, RANKING_PRODUCT_B, testId)
      expect(cell.attributes('data-state')).toBe('unknown')
      expect(cell.text()).not.toMatch(/\d/)
    }
    expect(hasFigure(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-volume').text(), '20,000')).toBe(true)
  })

  it('shows a product that does not fit with the days between rounds and no profit, and a product with an unknown SDE volume without output', async () => {
    const heavy = await mountRanking({ catalog: withTypeVolume(RANKING_CATALOG, RANKING_PRODUCT_B, 300), market: RANKING_MARKET })

    expect(rowOf(heavy.wrapper, RANKING_PRODUCT_B).attributes('data-status')).toBe('doesNotFit')
    expect(hasFigure(cellOf(heavy.wrapper, RANKING_PRODUCT_B, 'ranking-output').text(), '4')).toBe(true)
    expect(cellOf(heavy.wrapper, RANKING_PRODUCT_B, 'ranking-output').find('[data-testid="ranking-capped"]').exists()).toBe(false)
    expect(cellOf(heavy.wrapper, RANKING_PRODUCT_B, 'ranking-planets').attributes('data-state')).toBe('unknown')
    expect(cellOf(heavy.wrapper, RANKING_PRODUCT_B, 'ranking-profit-day').attributes('data-state')).toBe('unknown')
    expect(cellOf(heavy.wrapper, RANKING_PRODUCT_B, 'ranking-add').attributes('disabled')).toBeDefined()

    const unknownVolume = await mountRanking({ catalog: withTypeVolume(RANKING_CATALOG, RANKING_INPUT_A, null), market: RANKING_MARKET })

    expect(rowOf(unknownVolume.wrapper, RANKING_PRODUCT_B).attributes('data-status')).toBe('unknownVolume')
    expect(cellOf(unknownVolume.wrapper, RANKING_PRODUCT_B, 'ranking-output').attributes('data-state')).toBe('unknown')
    expect(cellOf(unknownVolume.wrapper, RANKING_PRODUCT_B, 'ranking-profit-day').attributes('data-state')).toBe('unknown')
    expect(cellOf(unknownVolume.wrapper, RANKING_PRODUCT_B, 'ranking-add').attributes('disabled')).toBeDefined()
  })

  it('truncates a very long product name inside its cell and keeps the full name in the title', async () => {
    const { wrapper } = await mountRanking({ catalog: withTypeName(PLANNER_CATALOG, ROBOTICS, LONG_TYPE_NAME) })

    const name = rowOf(wrapper, ROBOTICS).get(`[title="${LONG_TYPE_NAME}"]`)

    expect(name.text()).toContain(LONG_TYPE_NAME)
    expect(name.classes()).toContain('truncate')
  })

  it('shows an unknown volume and profit on every row, with the rows still ranked, when the market failed to load', async () => {
    const { wrapper } = await mountRanking({ market: new Error('market unavailable') })

    expect(rankingTypeIds(wrapper)).toEqual([COOLANT, MECHANICAL_PARTS, ROBOTICS, BROADCAST_NODE])
    for (const typeId of rankingTypeIds(wrapper)) {
      expect(cellOf(wrapper, typeId, 'ranking-volume').attributes('data-state')).toBe('unknown')
      expect(cellOf(wrapper, typeId, 'ranking-profit-day').attributes('data-state')).toBe('unknown')
    }
  })
})
