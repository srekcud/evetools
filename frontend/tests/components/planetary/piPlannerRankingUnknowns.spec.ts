import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import {
  RANKING_CATALOG,
  RANKING_INPUT_A,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  withTypeVolume,
  withoutMarketProduct,
} from '../../planetaryPlannerFixtures'
import { cellOf, compact, hasFigure, mountRanking, type RankingWrapper } from '../../planetaryRankingHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const SHARE_INVALID_TEXT = 'A share between 0 and 100 is expected, the previous value is kept'
const DOES_NOT_FIT_TEXT = 'Does not fit'
const SDE_VOLUME_UNKNOWN_TEXT = 'SDE volume unknown'

async function typeShare(wrapper: RankingWrapper, raw: string) {
  const input = wrapper.get('[data-testid="ranking-share"]')
  await input.setValue(raw)
  await flushPromises()
}

describe('PiPlannerRanking on a product whose volume is unknown', () => {
  it('shows the uncapped output of 3,120 on 1 / 1 planet and the purchases of the week, and no profit', async () => {
    const { wrapper } = await mountRanking({ catalog: RANKING_CATALOG, market: withoutMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B) })

    expect(hasFigure(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-output').text(), '3,120')).toBe(true)
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-output').find('[data-testid="ranking-capped"]').exists()).toBe(false)
    expect(compact(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-planets').text())).toBe('1/1')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-purchases').text()).toBe('8.74 M')
    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-profit-day').attributes('data-state')).toBe('unknown')
  })

  it('disables the plan button and adds nothing to the plan when it is clicked', async () => {
    const { wrapper, store } = await mountRanking({ catalog: RANKING_CATALOG, market: withoutMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B) })

    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-add').attributes('disabled')).toBeDefined()

    await cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-add').trigger('click')

    expect(store.productions).toEqual([])
  })
})

describe('PiPlannerRanking share field', () => {
  it('flags a refused share with the invalid message and aria-invalid, then clears both once a valid share is typed', async () => {
    const { wrapper } = await mountRanking()
    expect(wrapper.find('[data-testid="ranking-share-invalid"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="ranking-share"]').attributes('aria-invalid')).toBe('false')

    for (const raw of ['150', '-1', '', 'abc', '100.5']) {
      await typeShare(wrapper, raw)

      expect(wrapper.get('[data-testid="ranking-share-invalid"]').text()).toBe(SHARE_INVALID_TEXT)
      expect(wrapper.get('[data-testid="ranking-share"]').attributes('aria-invalid')).toBe('true')
    }

    await typeShare(wrapper, '10')

    expect(wrapper.find('[data-testid="ranking-share-invalid"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="ranking-share"]').attributes('aria-invalid')).toBe('false')
  })
})

describe('PiPlannerRanking output of a product that cannot be placed', () => {
  it('tells apart a product that does not fit, with its days, from a product whose SDE volume is unknown', async () => {
    const heavy = await mountRanking({ catalog: withTypeVolume(RANKING_CATALOG, RANKING_PRODUCT_B, 300), market: RANKING_MARKET })
    const unknownVolume = await mountRanking({ catalog: withTypeVolume(RANKING_CATALOG, RANKING_INPUT_A, null), market: RANKING_MARKET })

    const heavyOutput = cellOf(heavy.wrapper, RANKING_PRODUCT_B, 'ranking-output')
    const unknownOutput = cellOf(unknownVolume.wrapper, RANKING_PRODUCT_B, 'ranking-output')

    expect(heavyOutput.text()).toContain(DOES_NOT_FIT_TEXT)
    expect(hasFigure(heavyOutput.text(), '4')).toBe(true)
    expect(heavyOutput.text()).not.toContain(SDE_VOLUME_UNKNOWN_TEXT)
    expect(unknownOutput.text()).toContain(SDE_VOLUME_UNKNOWN_TEXT)
    expect(unknownOutput.text()).not.toContain(DOES_NOT_FIT_TEXT)
    expect(unknownOutput.text()).not.toMatch(/\d/)
  })
})
