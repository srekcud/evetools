import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerLineStations from '@/components/group-industry/ContainerLineStations.vue'
import type { ContainerVerification, StationQuantity, TypeStationQuantities } from '@/stores/group-industry/types'

const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/
const AMARR = { locationId: 100, stationName: 'Amarr VIII' }
const JITA = { locationId: 200, stationName: 'Jita IV' }
const TRITANIUM = 34

enableAutoUnmount(afterEach)

function verification(stockStations: ContainerVerification['stockStations']): ContainerVerification {
  return {
    bomItemId: 'line-1',
    typeId: TRITANIUM,
    typeName: 'Tritanium',
    requiredQuantity: 100,
    containerQuantity: 100,
    consumedQuantity: 0,
    status: 'verified',
    unknownReason: null,
    assetsSyncedAt: null,
    missingContainerNames: [],
    stockStations,
  }
}

function needAt(station: { locationId: number | null; stationName: string | null }, needed: number, consumed = 0): StationQuantity {
  return { ...station, needed, consumed, inProduction: 0, onSite: 0, out: 0, toBring: needed, inTransit: 0, unknownReason: null }
}

function quantities(...stations: StationQuantity[]): TypeStationQuantities {
  return { typeId: TRITANIUM, typeName: 'Tritanium', lineIds: ['line-1'], unknownReason: null, stations }
}

function mountLine(stockStations: ContainerVerification['stockStations'], stationQuantities: TypeStationQuantities | null, locale: 'fr' | 'en' = 'en'): VueWrapper {
  return mount(ContainerLineStations, {
    props: { verification: verification(stockStations), quantities: stationQuantities },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function stock(wrapper: VueWrapper) {
  return wrapper.find('[data-testid="container-line-stations"]')
}

function warning(wrapper: VueWrapper) {
  return wrapper.find('[data-testid="container-line-stations-warning"]')
}

describe('ContainerLineStations (#1476)', () => {
  it('says where the stock is and warns when the job runs at another station', () => {
    const wrapper = mountLine([{ ...JITA, quantity: 100 }], quantities(needAt(AMARR, 100)))

    expect(stock(wrapper).text()).toContain('Jita IV')
    expect(stock(wrapper).text()).toContain('100')
    expect(warning(wrapper).exists()).toBe(true)
    expect(warning(wrapper).text()).toContain('Amarr VIII')
    expect(warning(wrapper).text()).toContain('100')
  })

  it('lists every station holding stock and warns for what the job station still lacks', () => {
    const wrapper = mountLine([{ ...AMARR, quantity: 60 }, { ...JITA, quantity: 40 }], quantities(needAt(AMARR, 100)))

    expect(stock(wrapper).text()).toContain('Amarr VIII')
    expect(stock(wrapper).text()).toContain('Jita IV')
    expect(warning(wrapper).text()).toContain('Amarr VIII')
    expect(warning(wrapper).text()).toContain('40')
  })

  it('subtracts what started jobs already consumed from what the job station needs', () => {
    const wrapper = mountLine([{ ...AMARR, quantity: 60 }, { ...JITA, quantity: 40 }], quantities(needAt(AMARR, 100, 40)))

    expect(warning(wrapper).exists()).toBe(false)
  })

  it('stays silent when the stock is entirely at the station where the job runs', () => {
    const wrapper = mountLine([{ ...AMARR, quantity: 100 }], quantities(needAt(AMARR, 100)))

    expect(stock(wrapper).exists()).toBe(false)
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('lists the stations without a warning when each job station is covered', () => {
    const wrapper = mountLine([{ ...AMARR, quantity: 50 }, { ...JITA, quantity: 50 }], quantities(needAt(AMARR, 50), needAt(JITA, 50)))

    expect(stock(wrapper).exists()).toBe(true)
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('renders nothing when the stock stations are unknown', () => {
    const wrapper = mountLine(null, quantities(needAt(AMARR, 100)))

    expect(stock(wrapper).exists()).toBe(false)
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('renders nothing when there is no stock anywhere', () => {
    const wrapper = mountLine([], quantities(needAt(AMARR, 100)))

    expect(stock(wrapper).exists()).toBe(false)
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('lists a stock whose station is unresolved as unknown and does not warn', () => {
    const wrapper = mountLine([{ locationId: null, stationName: null, quantity: 100 }], quantities(needAt(AMARR, 100)))

    expect(stock(wrapper).text()).toContain('unknown station')
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('does not warn when the station of the job is not resolved', () => {
    const wrapper = mountLine([{ ...JITA, quantity: 100 }], quantities(needAt({ locationId: null, stationName: null }, 100)))

    expect(stock(wrapper).exists()).toBe(true)
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('does not warn when the station quantities are not available', () => {
    const wrapper = mountLine([{ ...JITA, quantity: 100 }], null)

    expect(stock(wrapper).exists()).toBe(true)
    expect(warning(wrapper).exists()).toBe(false)
  })

  it('speaks French without raw translation keys', () => {
    const wrapper = mountLine([{ ...JITA, quantity: 100 }], quantities(needAt(AMARR, 100)), 'fr')

    expect(stock(wrapper).text()).toContain('En stock')
    expect(warning(wrapper).text()).toContain('Pas à la station du job')
    expect(wrapper.text()).not.toMatch(RAW_TRANSLATION_KEY)
  })
})
