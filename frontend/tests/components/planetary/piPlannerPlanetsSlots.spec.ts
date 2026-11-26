import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import type { PlannerAccount } from '@/utils/planetaryPlanets'
import {
  ALMA_CHARACTER_ID,
  AQUEOUS_LIQUIDS,
  BRAN_CHARACTER_ID,
  BROADCAST_NODE,
  PLANNER_ACCOUNT,
  PLANNER_CATALOG,
  PLANNER_MARKET,
  REACTIVE_METALS,
  ROBOTICS,
  TOXIC_METALS,
  WATER,
  withExtractionYield,
  withMaxPlanets,
} from '../../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountTab(catalog: PlannerCatalog = PLANNER_CATALOG, account: PlannerAccount = PLANNER_ACCOUNT) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...account }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
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

async function addProduction(wrapper: Wrapper, typeId: number, targetPerDay: string) {
  await wrapper.get('[data-testid="planner-add-select"]').setValue(String(typeId))
  await flushPromises()
  const inputs = wrapper.findAll('[data-testid="planner-target-input"]')
  await inputs[inputs.length - 1].setValue(targetPerDay)
  await flushPromises()
}

async function addRoboticsThenBroadcastNode(wrapper: Wrapper) {
  await addProduction(wrapper, ROBOTICS, '72')
  await addProduction(wrapper, BROADCAST_NODE, '144')
}

async function makeP1(wrapper: Wrapper) {
  for (const typeId of [WATER, REACTIVE_METALS, TOXIC_METALS]) {
    await wrapper.get(`[data-testid="planner-tree-row"][data-type-id="${typeId}"] [data-choice="produce"]`).trigger('click')
  }
  await flushPromises()
}

function planetRow(wrapper: Wrapper, key: string) {
  const row = wrapper.get(`[data-testid="planets-row"][data-key="${key}"]`)
  return { row, cell: (testId: string) => row.get(`[data-testid="${testId}"]`) }
}

function dataOf(wrapper: Wrapper, testId: string): Record<string, string> {
  return wrapper.get(`[data-testid="${testId}"]`).attributes()
}

function slotBar(wrapper: Wrapper) {
  const segments = wrapper.get('[data-testid="planets-slots"] > div.h-3').findAll('span')
  return segments.map(segment => ({
    title: segment.attributes('title'),
    isFree: segment.classes().includes('bg-emerald-500/60'),
    width: Number.parseFloat((segment.element as HTMLElement).style.width),
  }))
}

enableAutoUnmount(afterEach)

describe('PiPlannerPlanets slots bar', () => {
  it('sizes each production segment on its own added planets over the maximum of the account, with the free segment for the rest', async () => {
    const wrapper = await mountTab()

    await addRoboticsThenBroadcastNode(wrapper)

    const segments = slotBar(wrapper)
    expect(segments.map(segment => segment.title)).toEqual(['Robotics: 2', 'Broadcast Node: 5', undefined])
    expect(segments.map(segment => segment.isFree)).toEqual([false, false, true])
    expect(segments[0].width).toBeCloseTo(20, 6)
    expect(segments[1].width).toBeCloseTo(50, 6)
    expect(segments[2].width).toBeCloseTo(30, 6)
    expect(dataOf(wrapper, 'planets-slots')['data-free']).toBe('3')
  })

  it('has no free segment when the maximum is unknown and scales the production segments on the total of the plan', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, withMaxPlanets(PLANNER_ACCOUNT, { [BRAN_CHARACTER_ID]: null }))

    await addRoboticsThenBroadcastNode(wrapper)

    const segments = slotBar(wrapper)
    expect(segments.map(segment => [segment.title, segment.isFree])).toEqual([['Robotics: 2', false], ['Broadcast Node: 5', false]])
    expect(segments[0].width).toBeCloseTo((2 / 7) * 100, 6)
    expect(segments[1].width).toBeCloseTo((5 / 7) * 100, 6)
    expect(dataOf(wrapper, 'planets-slots')['data-free']).toBe('unknown')
  })

  it('scales on the total of the plan when the maximum is below it, with an empty free segment', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 2, [BRAN_CHARACTER_ID]: 2 }))

    await addRoboticsThenBroadcastNode(wrapper)

    const segments = slotBar(wrapper)
    expect(segments.map(segment => [segment.title, segment.isFree])).toEqual([['Robotics: 2', false], ['Broadcast Node: 5', false], [undefined, true]])
    expect(segments[0].width).toBeCloseTo((2 / 7) * 100, 6)
    expect(segments[1].width).toBeCloseTo((5 / 7) * 100, 6)
    expect(segments[2].width).toBe(0)
  })
})

describe('PiPlannerPlanets slot bounds', () => {
  it('shows no shortfall and no free planet when the plan needs exactly the maximum: 7 planets for a maximum of 7', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 4, [BRAN_CHARACTER_ID]: 3 }))

    await addRoboticsThenBroadcastNode(wrapper)

    expect(dataOf(wrapper, 'planets-total')['data-planets']).toBe('7')
    expect(dataOf(wrapper, 'planets-account')['data-max']).toBe('7')
    expect(dataOf(wrapper, 'planets-slots')['data-free']).toBe('0')
    expect(wrapper.find('[data-testid="planets-shortfall"]').exists()).toBe(false)
  })

  it('shows 3 planets missing and no free planet when the plan needs 7 planets for a maximum of 4', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, withMaxPlanets(PLANNER_ACCOUNT, { [ALMA_CHARACTER_ID]: 2, [BRAN_CHARACTER_ID]: 2 }))

    await addRoboticsThenBroadcastNode(wrapper)

    expect(dataOf(wrapper, 'planets-shortfall')['data-missing']).toBe('3')
    expect(dataOf(wrapper, 'planets-slots')['data-free']).toBe('0')
  })
})

describe('PiPlannerPlanets exact figures', () => {
  it('states the layout of a factory tier with the volume held over the days between rounds and the capacity of its launchpads', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, BROADCAST_NODE, '144')

    const p4 = planetRow(wrapper, 'P4')
    const p2 = planetRow(wrapper, 'P2')

    expect(p4.cell('planets-need').text()).toBe('6 factories')
    expect(p4.cell('planets-layout').findAll('span').map(span => span.text())).toEqual(['5 launchpads + 5 installations', '9,600 m³ held on 50,000 m³ in 4 days'])
    expect(p2.cell('planets-need').text()).toBe('48 factories')
    expect(p2.cell('planets-layout').findAll('span').map(span => span.text())).toEqual(['4 launchpads + 13 installations', '2,918 m³ held on 40,000 m³ in 4 days'])
    expect(p4.cell('planets-count').text()).toBe('2')
  })

  it('states the need, the P1 factories and the yield of the account of an extraction row, as the player reads them', async () => {
    const wrapper = await mountTab()
    await addProduction(wrapper, ROBOTICS, '72')
    await makeP1(wrapper)

    const aqueous = planetRow(wrapper, String(AQUEOUS_LIQUIDS))
    const hint = aqueous.row.findAll('p').filter(paragraph => paragraph.attributes('data-testid') === undefined)

    expect(aqueous.cell('planets-need').text()).toBe('24,000 per hour')
    expect(aqueous.cell('planets-p1-factories').text()).toBe('4 P1 factories run on these planets')
    expect((aqueous.cell('planets-yield-input').element as HTMLInputElement).value).toBe('6000')
    expect(hint.map(paragraph => paragraph.text())).toEqual(['Mean of your colonies'])
    expect(aqueous.cell('planets-count').text()).toBe('4')
  })

  it('treats an account yield of zero as unknown: empty input, no source, no planet count and an incomplete total', async () => {
    const wrapper = await mountTab(PLANNER_CATALOG, withExtractionYield(PLANNER_ACCOUNT, AQUEOUS_LIQUIDS, 0))
    await addProduction(wrapper, ROBOTICS, '72')
    await makeP1(wrapper)

    const aqueous = planetRow(wrapper, String(AQUEOUS_LIQUIDS))

    expect(aqueous.cell('planets-yield-input').attributes('data-source')).toBe('none')
    expect((aqueous.cell('planets-yield-input').element as HTMLInputElement).value).toBe('')
    expect(aqueous.cell('planets-count').attributes('data-state')).toBe('unknown')
    expect(aqueous.cell('planets-count').text()).toBe('unknown')
    expect(dataOf(wrapper, 'planets-total')['data-incomplete']).toBe('true')
  })
})
