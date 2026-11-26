import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import PiRevenuePlanLine from '@/components/planetary/PiRevenuePlanLine.vue'
import { RANKING_PRODUCT_B, withSellPrices } from '../../planetaryPlannerFixtures'
import { TEMPLATE_B_LINE, TEMPLATE_CATALOG, asFollowed, makeTemplate } from '../../planetaryTemplatesFixtures'
import { mountFollowed, revenueOf, type FollowScenario } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const LONG_PLAN_NAME = 'Barren B line of the Outer Rim Consortium, extended production run for the whole alliance logistics wing'

type Mounted = Awaited<ReturnType<typeof mountFollowed>>

async function mountLine(scenario: FollowScenario = {}) {
  return mountFollowed(PiRevenuePlanLine, { catalog: TEMPLATE_CATALOG, templates: [asFollowed(TEMPLATE_B_LINE)], revenue: revenueOf(900_000, 30), ...scenario })
}

function figures(wrapper: Mounted['wrapper']): string[] {
  return ['revenue-plan-planned', 'revenue-plan-real', 'revenue-plan-gap', 'revenue-plan-ratio', 'revenue-plan-profit'].map(testId => wrapper.get(`[data-testid="${testId}"]`).text())
}

function gapColor(wrapper: Mounted['wrapper']): string[] {
  return wrapper.get('[data-testid="revenue-plan-gap"]').classes().filter(name => name.startsWith('text-') && name !== 'text-lg')
}

describe('PiRevenuePlanLine gap colours', () => {
  it('colours a negative gap red: -90,000 a day', async () => {
    const { wrapper } = await mountLine()

    expect(figures(wrapper)[2]).toBe('-90.00 K')
    expect(gapColor(wrapper)).toEqual(['text-red-400'])
  })

  it('colours a positive gap green: 150,000 a day realised against 120,000 planned is +30,000 and a ratio of 1.25', async () => {
    const { wrapper } = await mountLine({ revenue: revenueOf(4_500_000, 30) })

    expect(figures(wrapper).slice(0, 4)).toEqual(['120.00 K', '150.00 K', '30.00 K', '1.25'])
    expect(gapColor(wrapper)).toEqual(['text-emerald-400'])
  })

  it('colours a gap of 0 neutral: 120,000 a day realised against 120,000 planned, ratio 1', async () => {
    const { wrapper } = await mountLine({ revenue: revenueOf(3_600_000, 30) })

    expect(figures(wrapper).slice(0, 4)).toEqual(['120.00 K', '120.00 K', '0', '1'])
    expect(gapColor(wrapper)).toEqual(['text-slate-200'])
  })

  it('colours an unknown gap grey when the selling price is missing', async () => {
    const { wrapper } = await mountLine({ catalog: withSellPrices(TEMPLATE_CATALOG, { [RANKING_PRODUCT_B]: null }) })

    expect(figures(wrapper)[2]).toBe('unknown')
    expect(gapColor(wrapper)).toEqual(['text-slate-500'])
  })
})

describe('PiRevenuePlanLine figures and name', () => {
  it('shows a ratio of 0.333 as 0.33: 1,200,000 realised over 30 days is 40,000 a day against 120,000, gap -80,000', async () => {
    const { wrapper } = await mountLine({ revenue: revenueOf(1_200_000, 30) })

    expect(figures(wrapper).slice(0, 4)).toEqual(['120.00 K', '40.00 K', '-80.00 K', '0.33'])
  })

  it('truncates a long plan name and keeps it whole in the title', async () => {
    const longPlan = makeTemplate('tpl-long', LONG_PLAN_NAME, [{ typeId: RANKING_PRODUCT_B, targetPerDay: 120 }], {}, undefined, '2026-11-23T10:00:00+00:00', true)
    const { wrapper } = await mountLine({ templates: [longPlan] })
    const name = wrapper.get('[data-testid="revenue-plan-name"]')

    expect(name.text()).toBe(LONG_PLAN_NAME)
    expect(name.attributes('title')).toBe(LONG_PLAN_NAME)
    expect(name.classes()).toContain('truncate')
  })

  it('disappears when the followed template is deleted, with no plan left to follow', async () => {
    const { wrapper, store } = await mountLine()

    await store.deleteTemplate('tpl-b-line')
    await flushPromises()

    expect(store.followedTemplate).toBeNull()
    expect(wrapper.find('[data-testid="revenue-plan-line"]').exists()).toBe(false)
  })
})
