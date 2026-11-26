import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import type { PlannerAccount } from '@/utils/planetaryPlanets'
import {
  ALMA_CHARACTER_ID,
  AQUEOUS_LIQUIDS,
  BASE_METALS,
  BRAN_CHARACTER_ID,
  BROADCAST_NODE,
  HEAVY_METALS,
  LONG_TYPE_NAME,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
  REACTIVE_METALS,
  withMaxPlanets,
  withTypeName,
  withTypeVolume,
} from '../../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountTab(catalog: PlannerCatalog = PLANNER_CATALOG, account: PlannerAccount | Error = PLANNER_ACCOUNT) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') {
      if (account instanceof Error) throw account
      return { id: 'account', ...account }
    }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
    if (endpoint === '/planetary/templates') return []
    return { id: 'planner', ...catalog }
  }) as never)
  const wrapper = mount(PiPlannerTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountTab>>

async function addProduction(wrapper: Wrapper, typeId: number, targetPerDay: string | null = null) {
  await wrapper.get('[data-testid="planner-add-select"]').setValue(String(typeId))
  await flushPromises()
  if (targetPerDay !== null) {
    const inputs = wrapper.findAll('[data-testid="planner-target-input"]')
    await inputs[inputs.length - 1].setValue(targetPerDay)
    await flushPromises()
  }
}

async function makeP1(wrapper: Wrapper) {
  for (const typeId of [WATER, REACTIVE_METALS, TOXIC_METALS]) {
    await wrapper.get(`[data-testid="planner-tree-row"][data-type-id="${typeId}"] [data-choice="produce"]`).trigger('click')
  }
  await flushPromises()
}

function planetRow(wrapper: Wrapper, key: string) {
  const row = wrapper.get(`[data-testid="planets-row"][data-key="${key}"]`)
  return {
    attributes: row.attributes(),
    cell: (testId: string) => row.get(`[data-testid="${testId}"]`),
    row,
  }
}

function dataOf(wrapper: Wrapper, testId: string): Record<string, string> {
  return wrapper.get(`[data-testid="${testId}"]`).attributes()
}

enableAutoUnmount(afterEach)

describe('PiPlannerPlanets table', () => {
  it('lists a row per factory tier with its planet types, its layout in launchpads and installations and its planet count', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, BROADCAST_NODE, '144')

    expect(wrapper.findAll('[data-testid="planets-row"]').map(row => row.attributes('data-key'))).toEqual(['P4', 'P3', 'P2'])
    const p4 = planetRow(wrapper, 'P4')
    expect([p4.attributes['data-role'], p4.attributes['data-status']]).toEqual(['factory', 'ok'])
    expect([p4.cell('planets-layout').attributes('data-launchpads'), p4.cell('planets-layout').attributes('data-factories')]).toEqual(['5', '5'])
    expect(p4.cell('planets-need').text()).toContain('6')
    expect(p4.cell('planets-count').attributes('data-planets')).toBe('2')
    expect(p4.cell('planets-types').text()).toContain('Barren')
    expect(p4.cell('planets-types').text()).toContain('Temperate')
    expect(p4.cell('planets-types').text()).not.toContain('Lava')
    const p2 = planetRow(wrapper, 'P2')
    expect([p2.cell('planets-layout').attributes('data-launchpads'), p2.cell('planets-layout').attributes('data-factories'), p2.cell('planets-count').attributes('data-planets')]).toEqual(['4', '13', '4'])
  })

  it('shows a tier that does not fit as a row without planet count, states the days between rounds and marks the total incomplete', async () => {
    const wrapper = await mountTab(withTypeVolume(PLANNER_CATALOG, BROADCAST_NODE, 10_000))

    await addProduction(wrapper, BROADCAST_NODE, '144')

    const p4 = planetRow(wrapper, 'P4')
    expect(p4.attributes['data-status']).toBe('doesNotFit')
    expect(p4.cell('planets-count').attributes('data-state')).toBe('unknown')
    expect(p4.cell('planets-count').text()).not.toMatch(/\d/)
    expect(p4.cell('planets-layout').text()).toContain('4')
    expect(planetRow(wrapper, 'P2').cell('planets-count').attributes('data-planets')).toBe('4')
    expect(dataOf(wrapper, 'planets-total')).toMatchObject({ 'data-planets': '5', 'data-incomplete': 'true' })
  })

  it('shows an extraction row per raw resource, with the yield of the account marked as such and the P1 factories it feeds', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    await makeP1(wrapper)

    expect(wrapper.findAll('[data-testid="planets-row"]').map(row => row.attributes('data-key'))).toEqual(['P3', 'P2', String(AQUEOUS_LIQUIDS), String(BASE_METALS), String(HEAVY_METALS)])
    const aqueous = planetRow(wrapper, String(AQUEOUS_LIQUIDS))
    expect([aqueous.attributes['data-role'], aqueous.cell('planets-count').attributes('data-planets'), aqueous.cell('planets-yield-input').attributes('data-source')]).toEqual(['extraction', '4', 'account'])
    expect(aqueous.cell('planets-p1-factories').text()).toContain('4')
    expect(aqueous.cell('planets-types').text()).toContain('Barren')
  })

  it('keeps a row of unknown yield without planet count until the player types a valid yield, then recomputes the total', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS)
    await makeP1(wrapper)
    const heavy = () => planetRow(wrapper, String(HEAVY_METALS))
    expect(heavy().cell('planets-count').attributes('data-state')).toBe('unknown')
    expect(heavy().cell('planets-types').text()).not.toMatch(/Barren|Lava|Temperate/)
    expect(dataOf(wrapper, 'planets-total')['data-incomplete']).toBe('true')

    const input = heavy().cell('planets-yield-input')
    for (const invalid of ['0', '-5', 'abc', '']) {
      await input.setValue(invalid)
      await flushPromises()

      expect(input.attributes('aria-invalid')).toBe('true')
      expect(heavy().cell('planets-count').attributes('data-state')).toBe('unknown')
    }

    await input.setValue('6000')
    await flushPromises()

    expect(input.attributes('aria-invalid')).toBe('false')
    expect([heavy().cell('planets-count').attributes('data-state'), heavy().cell('planets-count').attributes('data-planets')]).toEqual(['known', '2'])
    expect([dataOf(wrapper, 'planets-total')['data-planets'], dataOf(wrapper, 'planets-total')['data-incomplete']]).toEqual(['11', 'false'])
  })

  it('truncates a very long resource name inside its row and keeps the full name in the title', async () => {
    const wrapper = await mountTab(withTypeName(PLANNER_CATALOG, AQUEOUS_LIQUIDS, LONG_TYPE_NAME))
    await addProduction(wrapper, ROBOTICS)
    await makeP1(wrapper)

    const name = planetRow(wrapper, String(AQUEOUS_LIQUIDS)).cell('planets-resource-name')

    expect(name.attributes('title')).toBe(LONG_TYPE_NAME)
    expect(name.text()).toContain(LONG_TYPE_NAME)
    expect(name.classes()).toContain('truncate')
  })
})

describe('PiPlannerPlanets total and account', () => {
  it('totals the planets of the plan, the characters they need, the colonies in use and the maximum of the account', async () => {
    const wrapper = await mountTab()

    await addProduction(wrapper, BROADCAST_NODE, '144')

    expect(dataOf(wrapper, 'planets-total')).toMatchObject({ 'data-planets': '7', 'data-incomplete': 'false', 'data-characters': '2' })
    expect(dataOf(wrapper, 'planets-account')).toMatchObject({ 'data-used': '4', 'data-max': '10' })
    expect(dataOf(wrapper, 'planets-slots')).toMatchObject({ 'data-free': '3' })
    expect(wrapper.find('[data-testid="planets-shortfall"]').exists()).toBe(false)
  })

  it('warns of the planets missing when the plan needs more than the account allows', async () => {
    const tightAccount = withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 2, [BRAN_CHARACTER_ID]: 2 })
    const wrapper = await mountTab(PLANNER_CATALOG, tightAccount)

    await addProduction(wrapper, BROADCAST_NODE, '144')

    expect(dataOf(wrapper, 'planets-shortfall')['data-missing']).toBe('3')
  })

  it('shows an unknown maximum and no free planets when a character maximum is unknown', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, withMaxPlanets(PLANNER_ACCOUNT, { [BRAN_CHARACTER_ID]: null }))

    await addProduction(wrapper, BROADCAST_NODE, '144')

    expect(dataOf(wrapper, 'planets-account')).toMatchObject({ 'data-used': '4', 'data-max': 'unknown' })
    expect(wrapper.find('[data-testid="planets-slots-free"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="planets-shortfall"]').exists()).toBe(false)
  })

  it('keeps the table computed on the catalog when the account cannot be read, with an unknown yield and maximum and the error banner', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, new Error('account unavailable'))

    await addProduction(wrapper, ROBOTICS)
    await makeP1(wrapper)

    expect(planetRow(wrapper, 'P2').cell('planets-count').attributes('data-planets')).toBe('1')
    expect(planetRow(wrapper, String(AQUEOUS_LIQUIDS)).cell('planets-count').attributes('data-state')).toBe('unknown')
    expect(dataOf(wrapper, 'planets-account')['data-max']).toBe('unknown')
    expect(wrapper.findComponent(ErrorBanner).props('message')).toBe((en.pi.planner.errors as Record<string, string>).account)
  })
})

describe('PiPlannerProductions planets added', () => {
  it('shows per production the planets it adds and, for the selected one, how its factories split between free slots and new planets', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, BROADCAST_NODE, '120')
    await addProduction(wrapper, BROADCAST_NODE, '60')

    expect(wrapper.findAll('[data-testid="planner-production-planets"]').map(cell => cell.attributes('data-added'))).toEqual(['6', '3'])
    const allocations = wrapper.findAll('[data-testid="planner-production-allocation"]').map(line => [line.attributes('data-tier'), line.attributes('data-on-free'), line.attributes('data-on-new'), line.attributes('data-free-remaining')])
    expect(allocations).toEqual([['P4', '0', '3', '2'], ['P3', '3', '2', '11'], ['P2', '12', '8', '5']])
  })
})
