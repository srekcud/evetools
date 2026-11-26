import { enableAutoUnmount, RouterLinkStub } from '@vue/test-utils'
import PiRevenuePlanLine from '@/components/planetary/PiRevenuePlanLine.vue'
import { RANKING_INPUT_C, RANKING_PRODUCT_B, withSellPrices } from '../../planetaryPlannerFixtures'
import { TEMPLATE_B_LINE, TEMPLATE_CATALOG, asFollowed } from '../../planetaryTemplatesFixtures'
import { mountFollowed, revenueOf, type FollowScenario } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

type Mounted = Awaited<ReturnType<typeof mountFollowed>>

async function mountLine(scenario: FollowScenario = {}) {
  return mountFollowed(PiRevenuePlanLine, { catalog: TEMPLATE_CATALOG, templates: [asFollowed(TEMPLATE_B_LINE)], revenue: revenueOf(900_000, 30), ...scenario })
}

function cell(wrapper: Mounted['wrapper'], testId: string) {
  return wrapper.get(`[data-testid="${testId}"]`)
}

function figures(wrapper: Mounted['wrapper']): string[] {
  return ['revenue-plan-planned', 'revenue-plan-real', 'revenue-plan-gap', 'revenue-plan-ratio', 'revenue-plan-profit'].map(testId => cell(wrapper, testId).text())
}

describe('PiRevenuePlanLine', () => {
  it('shows nothing while no template is followed', async () => {
    const { wrapper } = await mountLine({ templates: [TEMPLATE_B_LINE] })

    expect(wrapper.find('[data-testid="revenue-plan-line"]').exists()).toBe(false)
  })

  it('compares the 120,000 a day of planned sales with the 900,000 realised over 30 days, 30,000 a day: gap -90,000, ratio 0.25, planned profit 67,200', async () => {
    const { wrapper } = await mountLine()

    expect(cell(wrapper, 'revenue-plan-line').text()).toContain('Planned against real')
    expect(cell(wrapper, 'revenue-plan-name').text()).toBe('Barren B line')
    expect(figures(wrapper)).toEqual(['120.00 K', '30.00 K', '-90.00 K', '0.25', '67.20 K'])
    expect(['revenue-plan-planned', 'revenue-plan-real', 'revenue-plan-gap', 'revenue-plan-ratio', 'revenue-plan-profit'].map(testId => cell(wrapper, testId).attributes('data-state'))).toEqual(['known', 'known', 'known', 'known', 'known'])
  })

  it('links to the planner tab of the colonies page', async () => {
    const { wrapper } = await mountLine()

    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'planetary', query: { tab: 'planner' } })
    expect(cell(wrapper, 'revenue-plan-link').text()).toBe('Open the planner')
  })

  it('shows the planned sales, the gap, the ratio and the profit as unknown, never 0, when the selling price is missing, and keeps the realised sales', async () => {
    const { wrapper } = await mountLine({ catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: null }) })

    expect(figures(wrapper)).toEqual(['unknown', '30.00 K', 'unknown', 'unknown', 'unknown'])
    expect(cell(wrapper, 'revenue-plan-planned').attributes('data-state')).toBe('unknown')
    expect(cell(wrapper, 'revenue-plan-real').attributes('data-state')).toBe('known')
  })

  it('keeps the comparison and shows only the profit as unknown when the price of a bought input is missing', async () => {
    const { wrapper } = await mountLine({ catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_INPUT_C]: null }) })

    expect(figures(wrapper)).toEqual(['120.00 K', '30.00 K', '-90.00 K', '0.25', 'unknown'])
  })

  it('shows a gap of 0 and an unknown ratio when nothing is planned and nothing was realised', async () => {
    const { wrapper } = await mountLine({ catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: 0 }), revenue: revenueOf(0, 30) })

    expect(figures(wrapper).slice(0, 4)).toEqual(['0', '0', '0', 'unknown'])
  })

  it('shows a real of 0, a gap of -120,000 and a ratio of 0 when nothing was realised against a plan of 120,000', async () => {
    const { wrapper } = await mountLine({ revenue: revenueOf(0, 30) })

    expect(figures(wrapper).slice(0, 4)).toEqual(['120.00 K', '0', '-120.00 K', '0'])
  })

  it('divides the realised sales by the one day of a one day period: 48,000 a day, gap -72,000, ratio 0.4', async () => {
    const { wrapper } = await mountLine({ revenue: revenueOf(48_000, 1) })

    expect(figures(wrapper).slice(0, 4)).toEqual(['120.00 K', '48.00 K', '-72.00 K', '0.4'])
  })

  it('words the line and its figures in French', async () => {
    const { wrapper } = await mountLine({ locale: 'fr' })

    expect(cell(wrapper, 'revenue-plan-line').text()).toContain('Prévu contre réel')
    expect(figures(wrapper)).toEqual(['120,00 K', '30,00 K', '-90,00 K', '0,25', '67,20 K'])
    expect(cell(wrapper, 'revenue-plan-link').text()).toBe('Ouvrir le planificateur')
  })
})
