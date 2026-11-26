import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { AQUEOUS_LIQUIDS, PLANNER_CATALOG, WATER } from '../../planetaryPlannerFixtures'
import { makeTemplate } from '../../planetaryTemplatesFixtures'
import { cellOfTemplate, mountTemplates } from '../../planetaryTemplatesHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const WATER_TEMPLATE = makeTemplate('tpl-water', 'Water only', [{ typeId: WATER, targetPerDay: 1_920 }])

describe('PiPlannerTemplates extraction planets', () => {
  it('counts 2 extraction planets for 12,000 an hour of Aqueous Liquids at the account yield of 6,000 an hour', async () => {
    const { wrapper } = await mountTemplates({ templates: [WATER_TEMPLATE], catalog: PLANNER_CATALOG })

    const planets = cellOfTemplate(wrapper, 'tpl-water', 'template-planets')

    expect(planets.text()).toBe('2')
    expect(planets.attributes('data-state')).toBe('known')
    expect(planets.attributes('data-incomplete')).toBe('false')
  })

  it('counts 3 planets once a yield of 4,000 an hour is typed for Aqueous Liquids, without any new request', async () => {
    const { wrapper, store } = await mountTemplates({ templates: [WATER_TEMPLATE], catalog: PLANNER_CATALOG })

    expect(store.setExtractionYield(AQUEOUS_LIQUIDS, 4_000)).toBe(true)
    await flushPromises()

    const planets = cellOfTemplate(wrapper, 'tpl-water', 'template-planets')
    expect(planets.text()).toBe('3')
    expect(planets.attributes('data-incomplete')).toBe('false')
  })
})
