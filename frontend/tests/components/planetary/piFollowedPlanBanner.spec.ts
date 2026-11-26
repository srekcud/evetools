import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import PiFollowedPlanBanner from '@/components/planetary/PiFollowedPlanBanner.vue'
import { BROADCAST_NODE, COOLANT, TOXIC_METALS, WATER } from '../../planetaryPlannerFixtures'
import { TEMPLATE_CATALOG, TEMPLATE_VANISHED, asFollowed } from '../../planetaryTemplatesFixtures'
import { COOLANT_LINE_TEMPLATE, RING_TEMPLATE, accountWithFactories, mountFollowed, type FollowScenario } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const RING_ACCOUNT = accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 })

function tierRows(wrapper: Awaited<ReturnType<typeof mountFollowed>>['wrapper']) {
  return wrapper.findAll('[data-testid="followed-plan-tier"]')
}

function gapOf(wrapper: Awaited<ReturnType<typeof mountFollowed>>['wrapper'], tier: string): string {
  return wrapper.get(`[data-testid="followed-plan-tier"][data-tier="${tier}"] [data-testid="followed-plan-gap"]`).text()
}

async function mountBanner(scenario: FollowScenario = {}) {
  return mountFollowed(PiFollowedPlanBanner, { templates: [RING_TEMPLATE, COOLANT_LINE_TEMPLATE], account: RING_ACCOUNT, ...scenario })
}

describe('PiFollowedPlanBanner', () => {
  it('shows nothing while no template is followed', async () => {
    const { wrapper } = await mountBanner({ templates: [{ ...RING_TEMPLATE, followed: false }, COOLANT_LINE_TEMPLATE] })

    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
  })

  it('shows the name of the followed plan and the gap of each tier: 2 free in P1, missing 3 in P2, ok in P3 and P4', async () => {
    const { wrapper } = await mountBanner()

    expect(wrapper.get('[data-testid="followed-plan-banner"]').text()).toContain('Followed plan')
    expect(wrapper.get('[data-testid="followed-plan-name"]').text()).toBe('Broadcast ring')
    expect(tierRows(wrapper).map(row => row.attributes('data-tier'))).toEqual(['P1', 'P2', 'P3', 'P4'])
    expect(tierRows(wrapper).map(row => row.attributes('data-state'))).toEqual(['free', 'missing', 'ok', 'ok'])
    expect(['P1', 'P2', 'P3', 'P4'].map(tier => gapOf(wrapper, tier))).toEqual(['2 free', 'missing 3', 'ok', 'ok'])
  })

  it('shows the planned and the built factories of each tier next to its gap', async () => {
    const { wrapper } = await mountBanner()
    const row = (tier: string) => wrapper.get(`[data-testid="followed-plan-tier"][data-tier="${tier}"]`)

    expect(['P1', 'P2', 'P3', 'P4'].map(tier => row(tier).get('[data-testid="followed-plan-planned"]').text())).toEqual(['18', '12', '3', '2'])
    expect(['P1', 'P2', 'P3', 'P4'].map(tier => row(tier).get('[data-testid="followed-plan-real"]').text())).toEqual(['20', '9', '3', '2'])
  })

  it('leaves out the tiers planned at 0 with no colony, and keeps a built tier the plan does not use as free: 4 free in P1', async () => {
    const { wrapper } = await mountBanner({
      templates: [{ ...RING_TEMPLATE, followed: false }, asFollowed(COOLANT_LINE_TEMPLATE)],
      account: accountWithFactories({ P1: 4, P2: 7, P3: 0, P4: 0 }),
    })

    expect(tierRows(wrapper).map(row => row.attributes('data-tier'))).toEqual(['P1', 'P2'])
    expect(gapOf(wrapper, 'P1')).toBe('4 free')
    expect(gapOf(wrapper, 'P2')).toBe('missing 3')
  })

  it('shows every planned tier as unknown, never as ok, while the account cannot be read', async () => {
    const { wrapper } = await mountBanner({ account: new Error('account unavailable') })

    expect(tierRows(wrapper).map(row => row.attributes('data-state'))).toEqual(['unknown', 'unknown', 'unknown', 'unknown'])
    expect(['P1', 'P2', 'P3', 'P4'].map(tier => gapOf(wrapper, tier))).toEqual(['unknown', 'unknown', 'unknown', 'unknown'])
  })

  it('says the recipe was not found and shows no tier when a product of the followed plan left the SDE', async () => {
    const { wrapper } = await mountBanner({ catalog: TEMPLATE_CATALOG, templates: [asFollowed(TEMPLATE_VANISHED)], account: RING_ACCOUNT })

    expect(wrapper.get('[data-testid="followed-plan-name"]').text()).toBe('Vanished recipe')
    expect(wrapper.get('[data-testid="followed-plan-missing"]').text()).toBe('recipe not found')
    expect(tierRows(wrapper)).toHaveLength(0)
  })

  it('loads the followed template into the planner and asks to open it when the player clicks Open the plan', async () => {
    const { wrapper, store } = await mountBanner()

    await wrapper.get('[data-testid="followed-plan-open"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="followed-plan-open"]').text()).toBe('Open the plan')
    expect(wrapper.emitted('open')).toHaveLength(1)
    expect(store.productions).toEqual([{ typeId: BROADCAST_NODE, targetPerDay: 36 }])
    expect(store.choices).toEqual({ [WATER]: 'produce', [TOXIC_METALS]: 'produce' })
    expect(store.productions).not.toContainEqual({ typeId: COOLANT, targetPerDay: 1_200 })
  })

  it('words the banner, the gaps and the button in French', async () => {
    const { wrapper } = await mountBanner({ locale: 'fr' })

    expect(wrapper.get('[data-testid="followed-plan-banner"]').text()).toContain('Plan suivi')
    expect(['P1', 'P2', 'P3', 'P4'].map(tier => gapOf(wrapper, tier))).toEqual(['2 libres', 'manque 3', 'ok', 'ok'])
    expect(wrapper.get('[data-testid="followed-plan-open"]').text()).toBe('Ouvrir le plan')
  })

  it('words an unknown account and a missing recipe in French', async () => {
    const unknown = await mountBanner({ locale: 'fr', account: new Error('account unavailable') })
    expect(gapOf(unknown.wrapper, 'P2')).toBe('inconnu')

    const missing = await mountBanner({ locale: 'fr', catalog: TEMPLATE_CATALOG, templates: [asFollowed(TEMPLATE_VANISHED)] })
    expect(missing.wrapper.get('[data-testid="followed-plan-missing"]').text()).toBe('recette introuvable')
  })
})
