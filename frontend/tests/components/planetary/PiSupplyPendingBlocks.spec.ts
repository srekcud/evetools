import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import { usePlanetaryStore } from '@/stores/planetary'
import type { PendingOutput } from '@/utils/planetaryPickup'
import {
  coolantFromAmarr,
  coolantFromPerimeter,
  homePlasmoidsFromJitaVII,
  mechanicalPartsFromAmarr,
  pendingStockResponse,
  unknownFertilizerFromAmarrVIII,
} from '../../planetaryPendingStockFixtures'
import {
  CONSUMER_ELECTRONICS,
  COOLANT,
  FERTILIZER,
  MECHANICAL_PARTS,
  referenceLaunchpad,
  supplyResponse,
} from '../../planetarySupplyFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const STALE_AGE_SECONDS = 100_800

async function mountTab(outputs: PendingOutput[], complete = true, failure: Error | null = null) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary/input-needs') return Promise.resolve(supplyResponse([referenceLaunchpad()]))
    if (endpoint === '/planetary/pending-stock') {
      return failure ? Promise.reject(failure) : Promise.resolve(pendingStockResponse(outputs, complete))
    }
    return new Promise(() => {})
  })
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

function pickupRow(wrapper: Wrapper, typeId: number) {
  return wrapper.get(`[data-testid="pi-pickup-row"][data-type-id="${typeId}"]`)
}

function pickupNumbers(wrapper: Wrapper, typeId: number): string[] {
  const row = pickupRow(wrapper, typeId)
  return ['needed', 'amount', 'remaining'].map(column => numbersOf(row.get(`[data-testid="pi-pickup-${column}"]`).text()))
}

function bringBackRows(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="pi-bringback-row"]')
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

enableAutoUnmount(afterEach)

describe('PiSupplyTab pickup block', () => {
  it('lists each needed type with what is needed, picked up and left, its source planets and any unknown quantity', async () => {
    const wrapper = await mountTab([
      coolantFromAmarr(),
      coolantFromPerimeter(),
      mechanicalPartsFromAmarr(),
      unknownFertilizerFromAmarrVIII(),
      homePlasmoidsFromJitaVII(),
    ])

    expect(wrapper.findAll('[data-testid="pi-pickup-row"]')).toHaveLength(4)
    expect(pickupNumbers(wrapper, COOLANT)).toEqual(['11520', '5500', '6020'])
    expect(pickupNumbers(wrapper, MECHANICAL_PARTS)).toEqual(['11520', '3000', '8520'])
    expect(pickupNumbers(wrapper, CONSUMER_ELECTRONICS)).toEqual(['11520', '0', '11520'])
    expect(pickupNumbers(wrapper, FERTILIZER)).toEqual(['11520', '0', '11520'])

    const coolantSources = pickupRow(wrapper, COOLANT).findAll('[data-testid="pi-pickup-source"]')
    expect(coolantSources.map(source => [
      source.get('[data-testid="pi-pickup-source-planet"]').text(),
      numbersOf(source.get('[data-testid="pi-pickup-source-quantity"]').text()),
      numbersOf(source.get('[data-testid="pi-pickup-source-volume"]').text()),
    ])).toEqual([['Amarr III', '4000', '760'], ['Perimeter II', '1500', '285']])
    expect(pickupRow(wrapper, CONSUMER_ELECTRONICS).findAll('[data-testid="pi-pickup-source"]')).toHaveLength(0)

    const unknownSources = pickupRow(wrapper, FERTILIZER).findAll('[data-testid="pi-pickup-unknown-source"]')
    expect(unknownSources).toHaveLength(1)
    expect(unknownSources[0].text()).toContain('Amarr VIII')
    expect(wrapper.find('[data-testid="pi-pickup-unknown-note"]').exists()).toBe(true)

    const unknownRow = bringBackRows(wrapper).find(row => row.text().includes('Amarr VIII'))
    expect(unknownRow?.find('[data-testid="pi-bringback-unknown"]').exists()).toBe(true)
    expect(unknownRow?.get('[data-testid="pi-bringback-quantity"]').text()).not.toMatch(/\d/)
  })
})

describe('PiSupplyTab bring-back block', () => {
  it('lists every pending output with colony destinations first and says where an unplaced one goes', async () => {
    const wrapper = await mountTab([
      homePlasmoidsFromJitaVII(),
      coolantFromPerimeter(),
      mechanicalPartsFromAmarr(),
    ])

    const rows = bringBackRows(wrapper)
    expect(rows.map(row => row.get('[data-testid="pi-bringback-planet"]').text())).toEqual(['Amarr III', 'Perimeter II', 'Jita VII'])
    expect(rows.map(row => numbersOf(row.get('[data-testid="pi-bringback-quantity"]').text()))).toEqual(['3000', '1500', '250'])
    expect(rows.map(row => numbersOf(row.get('[data-testid="pi-bringback-volume"]').text()))).toEqual(['1140', '285', '142.5'])
    expect(rows[0].text()).toContain('Mechanical Parts')
    const destinations = rows.map(row => row.get('[data-testid="pi-bringback-destination"]'))
    expect(destinations.map(destination => destination.attributes('data-destination'))).toEqual(['colony', 'colony', 'home'])
    expect(destinations[0].text()).toContain('Jita IV')
    expect(destinations[2].text()).not.toMatch(/home/i)
    expect(destinations[2].text()).not.toContain('Jita IV')
  })

  it('marks stale data in orange with its age in hours and leaves fresh data plain', async () => {
    const wrapper = await mountTab([
      coolantFromAmarr({ dataStale: true, dataAgeSeconds: STALE_AGE_SECONDS }),
      coolantFromPerimeter(),
    ])

    const [staleRow, freshRow] = bringBackRows(wrapper)
    const staleCell = staleRow.get('[data-testid="pi-bringback-quantity"]')
    expect(staleCell.classes().some(name => name.includes('orange'))).toBe(true)
    expect(numbersOf(staleCell.get('[data-testid="pi-bringback-age"]').text())).toBe('28')
    const freshCell = freshRow.get('[data-testid="pi-bringback-quantity"]')
    expect(freshCell.classes().some(name => name.includes('orange'))).toBe(false)
    expect(freshCell.find('[data-testid="pi-bringback-age"]').exists()).toBe(false)
  })

  it('says explicitly that nothing is waiting and still lists the needed types with 0 picked up', async () => {
    const wrapper = await mountTab([])

    expect(wrapper.find('[data-testid="pi-bringback-empty"]').exists()).toBe(true)
    expect(bringBackRows(wrapper)).toHaveLength(0)
    expect(pickupNumbers(wrapper, COOLANT)).toEqual(['11520', '0', '11520'])
  })
})

describe('PiSupplyTab pending stock states', () => {
  it('warns that some schemas are unknown when the pending stock is incomplete and not otherwise', async () => {
    const incomplete = await mountTab([coolantFromAmarr()], false)
    const complete = await mountTab([coolantFromAmarr()])

    expect(incomplete.find('[data-testid="pi-pending-incomplete"]').exists()).toBe(true)
    expect(complete.find('[data-testid="pi-pending-incomplete"]').exists()).toBe(false)
  })

  it('shows the error of the pending stock in its own banner and keeps the refill grid usable', async () => {
    const wrapper = await mountTab([], true, new Error('pending stock unavailable'))

    expect(wrapper.findAllComponents(ErrorBanner).map(banner => banner.props('message'))).toEqual(['pending stock unavailable'])
    expect(wrapper.findAll('[data-testid="pi-supply-row"]')).toHaveLength(1)
    expect(bringBackRows(wrapper)).toHaveLength(0)
  })

  it('reads the pending stock when the tab opens and again when a synchronisation ends', async () => {
    const wrapper = await mountTab([coolantFromAmarr()])
    expect(callsTo('/planetary/pending-stock')).toBe(1)
    const inputNeedsBefore = callsTo('/planetary/input-needs')

    usePlanetaryStore().lastSyncAt = '2026-11-26T10:00:00.000Z'
    await flushPromises()

    expect(callsTo('/planetary/pending-stock')).toBe(2)
    expect(callsTo('/planetary/input-needs')).toBe(inputNeedsBefore + 1)
    expect(wrapper.find('[data-testid="pi-supply-tab"]').exists()).toBe(true)
  })
})
