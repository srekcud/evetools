import { enableAutoUnmount } from '@vue/test-utils'
import PiFollowedPlanBanner from '@/components/planetary/PiFollowedPlanBanner.vue'
import PiPlannerFactoryGap from '@/components/planetary/PiPlannerFactoryGap.vue'
import { BROADCAST_NODE, TOXIC_METALS, WATER } from '../../planetaryPlannerFixtures'
import { TEMPLATE_CATALOG, TEMPLATE_VANISHED, asFollowed } from '../../planetaryTemplatesFixtures'
import { COOLANT_LINE_TEMPLATE, RING_TEMPLATE, accountWithFactories, mountFollowed } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const RING_ACCOUNT = accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 })

describe('PiFollowedPlanBanner colonies link', () => {
  it('offers Filter the plan\'s colonies and emits filter when it is clicked', async () => {
    const { wrapper } = await mountFollowed(PiFollowedPlanBanner, { templates: [RING_TEMPLATE, COOLANT_LINE_TEMPLATE], account: RING_ACCOUNT })

    const link = wrapper.get('[data-testid="followed-plan-filter"]')
    expect(link.text()).toBe('Filter the plan\'s colonies')
    await link.trigger('click')

    expect(wrapper.emitted('filter')).toHaveLength(1)
  })

  it('words the link in French', async () => {
    const { wrapper } = await mountFollowed(PiFollowedPlanBanner, { locale: 'fr', templates: [RING_TEMPLATE], account: RING_ACCOUNT })

    expect(wrapper.get('[data-testid="followed-plan-filter"]').text()).toBe('Filtrer les colonies du plan')
  })

  it('disables the link and emits nothing when the recipe of the followed plan is missing', async () => {
    const { wrapper } = await mountFollowed(PiFollowedPlanBanner, { catalog: TEMPLATE_CATALOG, templates: [asFollowed(TEMPLATE_VANISHED)], account: RING_ACCOUNT })

    const link = wrapper.get('[data-testid="followed-plan-filter"]')
    expect(link.attributes('disabled')).toBeDefined()
    await link.trigger('click')

    expect(wrapper.emitted('filter')).toBeUndefined()
  })

  it('keeps the Open the plan button emitting open', async () => {
    const { wrapper } = await mountFollowed(PiFollowedPlanBanner, { templates: [RING_TEMPLATE], account: RING_ACCOUNT })

    await wrapper.get('[data-testid="followed-plan-open"]').trigger('click')

    expect(wrapper.emitted('open')).toHaveLength(1)
    expect(wrapper.emitted('filter')).toBeUndefined()
  })
})

describe('PiPlannerFactoryGap colonies link', () => {
  async function mountGap() {
    const mounted = await mountFollowed(PiPlannerFactoryGap, { account: RING_ACCOUNT })
    mounted.store.addProduction(BROADCAST_NODE, 36)
    mounted.store.setChoice(WATER, 'produce')
    mounted.store.setChoice(TOXIC_METALS, 'produce')
    await mounted.wrapper.vm.$nextTick()
    return mounted
  }

  it('offers Colonies filtered on the tier on every tier row', async () => {
    const { wrapper } = await mountGap()

    const links = wrapper.findAll('[data-testid="factory-gap-colonies"]')
    expect(links).toHaveLength(4)
    expect(links.every(link => link.text() === 'Colonies filtered on the tier')).toBe(true)
  })

  it('emits show-colonies with the tier of the row whose link is clicked', async () => {
    const { wrapper } = await mountGap()

    await wrapper.get('[data-testid="factory-gap-row"][data-tier="P2"] [data-testid="factory-gap-colonies"]').trigger('click')
    await wrapper.get('[data-testid="factory-gap-row"][data-tier="P4"] [data-testid="factory-gap-colonies"]').trigger('click')

    expect(wrapper.emitted('show-colonies')).toEqual([['P2'], ['P4']])
  })
})
