import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import { usePlanetaryStore } from '@/stores/planetary'
import type { SupplyLaunchpad } from '@/utils/planetarySupply'
import {
  CONSUMER_ELECTRONICS,
  COOLANT,
  FERTILIZER,
  MECHANICAL_PARTS,
  feedOf,
  launchpadOf,
  referenceLaunchpad,
  supplyResponse,
  unknownCapacityLaunchpad,
} from '../../planetarySupplyFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const writeText = vi.fn()

async function mountTab(launchpads: SupplyLaunchpad[], complete = true, failure: Error | null = null) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  if (failure) {
    vi.mocked(apiRequest).mockRejectedValue(failure)
  } else {
    vi.mocked(apiRequest).mockResolvedValue(supplyResponse(launchpads, complete) as never)
  }
  const wrapper = mount(PiSupplyTab, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountTab>>

function numbersOf(text: string): string {
  return text.replace(/[^\d.]/g, '')
}

function rows(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="pi-supply-row"]')
}

function cellNumber(row: ReturnType<typeof rows>[number], typeId: number): string {
  return numbersOf(row.get(`[data-testid="pi-supply-cell"][data-type-id="${typeId}"]`).text())
}

function totalNumber(wrapper: Wrapper, typeId: number): string {
  return numbersOf(wrapper.get(`[data-testid="pi-supply-total-cell"][data-type-id="${typeId}"]`).text())
}

async function setDays(wrapper: Wrapper, days: string) {
  await wrapper.get('[data-testid="pi-supply-days"]').setValue(days)
  await flushPromises()
}

function inputNeedsCalls(): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === '/planetary/input-needs').length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('PiSupplyTab grid', () => {
  it('loads the input needs and proposes 4 days by default', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    expect(inputNeedsCalls()).toBe(1)
    expect((wrapper.get('[data-testid="pi-supply-days"]').element as HTMLInputElement).value).toBe('4')
  })

  it('shows one row per launchpad with the quantity of each input, the m3 to bring and the autonomy', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    expect(rows(wrapper)).toHaveLength(1)
    const row = rows(wrapper)[0]
    expect(row.text()).toContain('Jita IV')
    expect(row.text()).toContain('Launchpad')
    expect([COOLANT, MECHANICAL_PARTS, CONSUMER_ELECTRONICS, FERTILIZER].map(typeId => cellNumber(row, typeId))).toEqual(['11520', '11520', '11520', '11520'])
    expect(numbersOf(row.get('[data-testid="pi-supply-bring"]').text())).toBe('8755.2')
    expect(numbersOf(row.get('[data-testid="pi-supply-autonomy"]').text())).toBe('4.0')
  })

  it('shows the total of each input and the total m3 in the last row', async () => {
    const wrapper = await mountTab([referenceLaunchpad(), launchpadOf({
      pinId: 2_002,
      planetName: 'Perimeter II',
      feeds: [feedOf(COOLANT, { projectedStock: 2000 })],
    })])

    expect(rows(wrapper)).toHaveLength(2)
    expect(totalNumber(wrapper, COOLANT)).toBe('21040')
    expect(totalNumber(wrapper, FERTILIZER)).toBe('11520')
    expect(numbersOf(wrapper.get('[data-testid="pi-supply-total-bring"]').text())).toBe('10564')
  })

  it('shows a type without name as its type id', async () => {
    const wrapper = await mountTab([launchpadOf({ feeds: [feedOf(FERTILIZER, { typeName: null })] })])

    expect(wrapper.get('[data-testid="pi-supply-grid"]').text()).toContain(`#${FERTILIZER}`)
  })

  it('warns that the schema of some factories is unknown when the data is incomplete', async () => {
    const wrapper = await mountTab([referenceLaunchpad()], false)

    expect(wrapper.find('[data-testid="pi-supply-incomplete"]').exists()).toBe(true)
  })

  it('shows no schema warning when the data is complete', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    expect(wrapper.find('[data-testid="pi-supply-incomplete"]').exists()).toBe(false)
  })
})

describe('PiSupplyTab capacity', () => {
  it('warns about the planet that cannot hold 7 days and colors its autonomy', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    await setDays(wrapper, '7')

    const warnings = wrapper.findAll('[data-testid="pi-supply-capacity-warning"]')
    expect(warnings).toHaveLength(1)
    expect(warnings[0].text()).toContain('Jita IV')
    expect(warnings[0].text()).toContain('7')
    expect(rows(wrapper)[0].get('[data-testid="pi-supply-autonomy"]').classes().some(name => name.includes('orange'))).toBe(true)
    expect(cellNumber(rows(wrapper)[0], COOLANT)).toBe('13158')
  })

  it('shows no capacity warning when 4 days fit everywhere', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    expect(wrapper.find('[data-testid="pi-supply-capacity-warning"]').exists()).toBe(false)
    expect(rows(wrapper)[0].get('[data-testid="pi-supply-autonomy"]').classes().some(name => name.includes('orange'))).toBe(false)
  })
})

describe('PiSupplyTab unknown rows', () => {
  it('shows an unknown row without quantity, counts it as excluded and leaves it out of the totals', async () => {
    const wrapper = await mountTab([referenceLaunchpad(), unknownCapacityLaunchpad()])

    expect(rows(wrapper)).toHaveLength(2)
    expect(rows(wrapper)[1].findAll('[data-testid="pi-supply-unknown"]').length).toBeGreaterThan(0)
    expect(rows(wrapper)[1].findAll('[data-testid="pi-supply-cell"]').filter(cell => /\d/.test(cell.text()))).toEqual([])
    expect(wrapper.get('[data-testid="pi-supply-excluded"]').text()).toContain('1')
    expect(totalNumber(wrapper, COOLANT)).toBe('11520')
  })

  it('shows no exclusion message when every row is known', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    expect(wrapper.find('[data-testid="pi-supply-excluded"]').exists()).toBe(false)
  })
})

describe('PiSupplyTab copy', () => {
  it('copies the totals as a multibuy list', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    await wrapper.get('[data-testid="pi-supply-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    expect(writeText).toHaveBeenCalledWith('Coolant\t11520\nMechanical Parts\t11520\nConsumer Electronics\t11520\nFertilizer\t11520')
  })

  it('leaves a type without name out of the copy and says so', async () => {
    const wrapper = await mountTab([launchpadOf({ feeds: [feedOf(COOLANT), feedOf(FERTILIZER, { typeName: null })] })])

    await wrapper.get('[data-testid="pi-supply-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('Coolant\t11520')
    expect(wrapper.find('[data-testid="pi-supply-unnamed"]').exists()).toBe(true)
  })

  it('has no unnamed type message when every type has a name', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    expect(wrapper.find('[data-testid="pi-supply-unnamed"]').exists()).toBe(false)
  })

  it('disables the copy button without any launchpad to fill', async () => {
    const wrapper = await mountTab([launchpadOf({ feeds: [] })])

    expect(wrapper.get('[data-testid="pi-supply-copy"]').attributes('disabled')).toBeDefined()
  })
})

describe('PiSupplyTab states', () => {
  it('explains that no launchpad feeds a factory instead of showing an empty grid', async () => {
    const wrapper = await mountTab([launchpadOf({ feeds: [] })])

    expect(wrapper.find('[data-testid="pi-supply-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="pi-supply-grid"]').exists()).toBe(false)
  })

  it('shows the error of a failing read in an error banner and no empty grid', async () => {
    const wrapper = await mountTab([], true, new Error('input needs unavailable'))

    expect(wrapper.findComponent(ErrorBanner).props('message')).toBe('input needs unavailable')
    expect(wrapper.find('[data-testid="pi-supply-grid"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="pi-supply-empty"]').exists()).toBe(false)
  })

  it('shows no row and says the days are invalid for 0 days', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])

    await setDays(wrapper, '0')

    expect(rows(wrapper)).toHaveLength(0)
    expect(wrapper.find('[data-testid="pi-supply-days-invalid"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="pi-supply-copy"]').attributes('disabled')).toBeDefined()
  })

  it('shows the rows again once the days are valid', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])
    await setDays(wrapper, '0')

    await setDays(wrapper, '4')

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.find('[data-testid="pi-supply-days-invalid"]').exists()).toBe(false)
  })

  it('reads the input needs again when a synchronisation ends', async () => {
    const wrapper = await mountTab([referenceLaunchpad()])
    const callsBefore = inputNeedsCalls()

    usePlanetaryStore().lastSyncAt = '2026-11-26T10:00:00.000Z'
    await flushPromises()

    expect(inputNeedsCalls()).toBe(callsBefore + 1)
    expect(wrapper.find('[data-testid="pi-supply-tab"]').exists()).toBe(true)
  })
})
