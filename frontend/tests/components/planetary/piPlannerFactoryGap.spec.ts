import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import PiPlannerFactoryGap from '@/components/planetary/PiPlannerFactoryGap.vue'
import { BROADCAST_NODE, COOLANT, PLANNER_ACCOUNT, TOXIC_METALS, WATER } from '../../planetaryPlannerFixtures'
import { accountWithFactories, mountFollowed, type FollowScenario } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

type Mounted = Awaited<ReturnType<typeof mountFollowed>>

async function mountGap(scenario: FollowScenario = {}, withRing = true) {
  const mounted = await mountFollowed(PiPlannerFactoryGap, { account: accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 }), ...scenario })
  if (withRing) {
    mounted.store.addProduction(BROADCAST_NODE, 36)
    mounted.store.setChoice(WATER, 'produce')
    mounted.store.setChoice(TOXIC_METALS, 'produce')
    await flushPromises()
  }
  return mounted
}

function rows(wrapper: Mounted['wrapper']) {
  return wrapper.findAll('[data-testid="factory-gap-row"]')
}

function column(wrapper: Mounted['wrapper'], testId: string): string[] {
  return rows(wrapper).map(row => row.get(`[data-testid="${testId}"]`).text())
}

describe('PiPlannerFactoryGap', () => {
  it('titles the box Factories: plan against your colonies', async () => {
    const { wrapper } = await mountGap()

    expect(wrapper.get('[data-testid="factory-gap"]').text()).toContain('Factories: plan against your colonies')
  })

  it('shows the four tiers with the planned factories, the colonies and the gap: P1 2 free, P2 missing 3, P3 ok, P4 ok', async () => {
    const { wrapper } = await mountGap()

    expect(rows(wrapper).map(row => row.attributes('data-tier'))).toEqual(['P1', 'P2', 'P3', 'P4'])
    expect(rows(wrapper).map(row => row.attributes('data-state'))).toEqual(['free', 'missing', 'ok', 'ok'])
    expect(column(wrapper, 'factory-gap-planned')).toEqual(['18', '12', '3', '2'])
    expect(column(wrapper, 'factory-gap-real')).toEqual(['20', '9', '3', '2'])
    expect(column(wrapper, 'factory-gap-text')).toEqual(['2 free', 'missing 3', 'ok', 'ok'])
  })

  it('follows the plan as the player edits it: 1,200 Coolant a day is 10 P2 factories, missing 1 against 9', async () => {
    const { wrapper, store } = await mountGap({}, false)
    store.addProduction(COOLANT, 1_200)
    await flushPromises()

    expect(column(wrapper, 'factory-gap-planned')).toEqual(['0', '10', '0', '0'])
    expect(column(wrapper, 'factory-gap-text')).toEqual(['20 free', 'missing 1', '3 free', '2 free'])
  })

  it('shows ok on the four tiers for an empty plan and colonies without factory', async () => {
    const { wrapper } = await mountGap({ account: PLANNER_ACCOUNT }, false)

    expect(rows(wrapper).map(row => row.attributes('data-state'))).toEqual(['ok', 'ok', 'ok', 'ok'])
    expect(column(wrapper, 'factory-gap-planned')).toEqual(['0', '0', '0', '0'])
    expect(column(wrapper, 'factory-gap-real')).toEqual(['0', '0', '0', '0'])
  })

  it('shows unknown on the four tiers, never ok, while the account cannot be read', async () => {
    const { wrapper } = await mountGap({ account: new Error('account unavailable') })

    expect(rows(wrapper).map(row => row.attributes('data-state'))).toEqual(['unknown', 'unknown', 'unknown', 'unknown'])
    expect(column(wrapper, 'factory-gap-text')).toEqual(['unknown', 'unknown', 'unknown', 'unknown'])
    expect(column(wrapper, 'factory-gap-planned')).toEqual(['18', '12', '3', '2'])
  })

  it('words the box in French', async () => {
    const { wrapper } = await mountGap({ locale: 'fr' })

    expect(wrapper.get('[data-testid="factory-gap"]').text()).toContain('Usines : plan contre tes colonies')
    expect(column(wrapper, 'factory-gap-text')).toEqual(['2 libres', 'manque 3', 'ok', 'ok'])
  })
})
