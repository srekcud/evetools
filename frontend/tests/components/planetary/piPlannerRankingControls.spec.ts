import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import {
  ALMA_CHARACTER_ID,
  BRAN_CHARACTER_ID,
  BROADCAST_NODE,
  COOLANT,
  MECHANICAL_PARTS,
  PLANNER_ACCOUNT,
  RANKING_CATALOG,
  RANKING_MARKET,
  RANKING_PRODUCT_B,
  ROBOTICS,
  catalogWithP2Products,
  withMarketProduct,
  withMaxPlanets,
} from '../../planetaryPlannerFixtures'
import { cellOf, compact, hasFigure, mountRanking, rankingRows, rankingTypeIds, type RankingWrapper } from '../../planetaryRankingHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const EMPTY_MARKET = { ...RANKING_MARKET, products: [] }

async function typeShare(wrapper: RankingWrapper, raw: string) {
  const input = wrapper.get('[data-testid="ranking-share"]')
  await input.setValue(raw)
  await input.trigger('change')
  await flushPromises()
}

function shareField(wrapper: RankingWrapper): string {
  return (wrapper.get('[data-testid="ranking-share"]').element as HTMLInputElement).value
}

describe('PiPlannerRanking controls', () => {
  it('keeps only the products of the tier chip the player picks and gives them all back with the all chip', async () => {
    const { wrapper } = await mountRanking()

    await wrapper.get('[data-testid="ranking-tier"] [data-tier="P3"]').trigger('click')
    expect(rankingTypeIds(wrapper)).toEqual([ROBOTICS])
    await wrapper.get('[data-testid="ranking-tier"] [data-tier="P2"]').trigger('click')
    expect(rankingTypeIds(wrapper)).toEqual([MECHANICAL_PARTS, COOLANT])
    await wrapper.get('[data-testid="ranking-tier"] [data-tier="P4"]').trigger('click')
    expect(rankingTypeIds(wrapper)).toEqual([BROADCAST_NODE])
    await wrapper.get('[data-testid="ranking-tier"] [data-tier="all"]').trigger('click')
    expect(rankingRows(wrapper)).toHaveLength(4)
  })

  it('disables the free planets base while the maximum of a character is unknown and enables it once every maximum is known', async () => {
    const unknown = await mountRanking({ account: withMaxPlanets(PLANNER_ACCOUNT, { [BRAN_CHARACTER_ID]: null }) })
    const known = await mountRanking({ account: withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 5 }) })

    expect(unknown.wrapper.get('[data-testid="ranking-base"] [data-base="free"]').attributes('disabled')).toBeDefined()
    expect(unknown.wrapper.get('[data-testid="ranking-base"] [data-base="planet"]').attributes('disabled')).toBeUndefined()
    expect(unknown.wrapper.get('[data-testid="ranking-base"] [data-base="character"]').attributes('disabled')).toBeUndefined()
    expect(known.wrapper.get('[data-testid="ranking-base"] [data-base="free"]').attributes('disabled')).toBeUndefined()
  })

  it('shows the planets used on the base the player picks: 1 on 6 for a character, 1 on 10 for the free planets, 1 on 1 for a planet', async () => {
    const { wrapper } = await mountRanking()

    await wrapper.get('[data-testid="ranking-base"] [data-base="character"]').trigger('click')
    expect(compact(cellOf(wrapper, ROBOTICS, 'ranking-planets').text())).toBe('1/6')
    await wrapper.get('[data-testid="ranking-base"] [data-base="free"]').trigger('click')
    expect(compact(cellOf(wrapper, ROBOTICS, 'ranking-planets').text())).toBe('1/10')
    await wrapper.get('[data-testid="ranking-base"] [data-base="planet"]').trigger('click')
    expect(compact(cellOf(wrapper, ROBOTICS, 'ranking-planets').text())).toBe('1/1')
  })

  it('takes the volume share the player types, with a decimal comma: 10 % gives an output of 46 for Robotics, 7,5 % is kept as 7.5', async () => {
    const { wrapper, store } = await mountRanking()

    await typeShare(wrapper, '10')
    expect(store.volumeSharePercent).toBe(10)
    expect(hasFigure(cellOf(wrapper, ROBOTICS, 'ranking-output').text(), '46')).toBe(true)

    await typeShare(wrapper, '7,5')
    expect(store.volumeSharePercent).toBe(7.5)
  })

  it('refuses an empty, negative, above 100 or non numeric share, keeps the previous value in the store and puts it back in the field', async () => {
    const { wrapper, store } = await mountRanking()

    for (const raw of ['150', '-1', '', 'abc', '100.5']) {
      await typeShare(wrapper, raw)

      expect(store.volumeSharePercent).toBe(5)
      expect(shareField(wrapper)).toBe('5')
    }
  })

  it('shows 15 rows first and all 17 once the player asks to see everything', async () => {
    const { wrapper } = await mountRanking({ catalog: catalogWithP2Products(17), market: EMPTY_MARKET })

    expect(rankingRows(wrapper)).toHaveLength(15)
    expect(rankingRows(wrapper)[14].get('[data-testid="ranking-rank"]').text()).toBe('15')

    await wrapper.get('[data-testid="ranking-show-all"]').trigger('click')

    expect(rankingRows(wrapper)).toHaveLength(17)
    expect(rankingRows(wrapper)[16].get('[data-testid="ranking-rank"]').text()).toBe('17')
  })

  it('shows 15 rows then 16 for 16 products, and exactly 15 rows without a show all button for 15 products', async () => {
    const sixteen = await mountRanking({ catalog: catalogWithP2Products(16), market: EMPTY_MARKET })

    expect(rankingRows(sixteen.wrapper)).toHaveLength(15)
    await sixteen.wrapper.get('[data-testid="ranking-show-all"]').trigger('click')
    expect(rankingRows(sixteen.wrapper)).toHaveLength(16)

    const fifteen = await mountRanking({ catalog: catalogWithP2Products(15), market: EMPTY_MARKET })

    expect(rankingRows(fifteen.wrapper)).toHaveLength(15)
    expect(fifteen.wrapper.find('[data-testid="ranking-show-all"]').exists()).toBe(false)
  })

  it('adds the product to the planned productions with the output rounded to the unit: 23 for Robotics', async () => {
    const { wrapper, store } = await mountRanking()

    await cellOf(wrapper, ROBOTICS, 'ranking-add').trigger('click')

    expect(store.productions).toEqual([{ typeId: ROBOTICS, targetPerDay: 23 }])
  })

  it('adds 1,000 for an output of 1,000.4 a day', async () => {
    const { wrapper, store } = await mountRanking({ catalog: RANKING_CATALOG, market: withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume: 20_008 }) })

    await cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-add').trigger('click')

    expect(store.productions).toEqual([{ typeId: RANKING_PRODUCT_B, targetPerDay: 1_000 }])
  })

  it('disables the plan button below one unit a day, at an output of 0.4, and for every product at a share of 0 %', async () => {
    const { wrapper, store } = await mountRanking({ catalog: RANKING_CATALOG, market: withMarketProduct(RANKING_MARKET, RANKING_PRODUCT_B, { averageDailyVolume: 8 }) })

    expect(cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-add').attributes('disabled')).toBeDefined()
    await cellOf(wrapper, RANKING_PRODUCT_B, 'ranking-add').trigger('click')
    expect(store.productions).toEqual([])

    const overall = await mountRanking()
    await typeShare(overall.wrapper, '0')

    expect(overall.store.volumeSharePercent).toBe(0)
    for (const typeId of rankingTypeIds(overall.wrapper)) {
      expect(cellOf(overall.wrapper, typeId, 'ranking-add').attributes('disabled')).toBeDefined()
    }
  })
})
