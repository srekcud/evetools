import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import type { OreValueTableResponse } from '@/composables/useOreValueTable'
import { apiRequest } from '@/services/api'
import {
  PERIMETER_STRUCTURE_ID,
  PERIMETER_STRUCTURE_NAME,
  miningValuationProfile,
  valuationProfileServer,
} from './miningValuationProfileHarness'
import { mountOreValueTab } from './oreValueTabHarness'
import { oreValueRow } from './oreValueTableHarness'
import { PRICED_SALE } from './oreSaleHarness'
import { NPC_STATION_PROFILE } from './reprocessingProfileHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ORE_VALUE_TABLE: OreValueTableResponse = {
  market: 'jita',
  side: 'buy',
  structureId: null,
  reprocessYieldKnown: true,
  rows: [oreValueRow({ rawIskPerM3: 183.7, compressedIskPerM3: 196.1, reprocessedIskPerM3: 133.224, bestOption: 'compressed', bestIskPerM3: 196.1, gapToBestPercent: 0 })],
}

const MARKET_GROUP = '[role="group"][aria-label="Market"]'

const STRUCTURE_SELL_PROFILE = miningValuationProfile({
  market: 'structure',
  side: 'sell',
  form: 'reprocessed',
  structureId: PERIMETER_STRUCTURE_ID,
  structureName: PERIMETER_STRUCTURE_NAME,
})

function serverWith(profile = miningValuationProfile({ structureId: PERIMETER_STRUCTURE_ID, structureName: PERIMETER_STRUCTURE_NAME })) {
  const server = valuationProfileServer(profile)
  vi.mocked(apiRequest).mockImplementation(server.respondingWith(endpoint => {
    if (endpoint.startsWith('/mining/ore-values')) return Promise.resolve(ORE_VALUE_TABLE)
    if (endpoint === '/mining/reprocessing-profile') return Promise.resolve(NPC_STATION_PROFILE)
    if (endpoint.startsWith('/mining/ore-sale')) return Promise.resolve(PRICED_SALE)
    return new Promise(() => {})
  }))
  return server
}

function oreValueQueries(): URLSearchParams[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(endpoint => endpoint.startsWith('/mining/ore-values'))
    .map(endpoint => new URLSearchParams(endpoint.split('?')[1]))
}

type TabWrapper = Awaited<ReturnType<typeof mountOreValueTab>>

function pressedMarketButtons(wrapper: TabWrapper): string[] {
  return wrapper.findAll(`${MARKET_GROUP} button[aria-pressed="true"]`).map(button => button.text())
}

function marketButton(wrapper: TabWrapper, label: string) {
  const button = wrapper.findAll(`${MARKET_GROUP} button`).find(candidate => candidate.text() === label)
  if (button === undefined) throw new Error(`No market button ${label}`)
  return button
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('OreValueTab market and form of the mining valuation profile', () => {
  it('presses the market button of the profile and asks the table without market or side', async () => {
    serverWith(STRUCTURE_SELL_PROFILE)

    const wrapper = await mountOreValueTab()

    expect(pressedMarketButtons(wrapper)).toEqual(['Structure sell'])
    expect(oreValueQueries().length).toBeGreaterThan(0)
    expect(oreValueQueries().map(query => [query.has('market'), query.has('side')])).toEqual(oreValueQueries().map(() => [false, false]))
  })

  it('writes the market and the side of each of the four buttons into the profile', async () => {
    const server = serverWith()
    const wrapper = await mountOreValueTab()

    for (const label of ['Jita sell', 'Structure buy', 'Structure sell', 'Jita buy']) {
      await marketButton(wrapper, label).trigger('click')
      await flushPromises()
    }

    expect(server.state.patches).toEqual([
      { market: 'jita', side: 'sell' },
      { market: 'structure', side: 'buy' },
      { market: 'structure', side: 'sell' },
      { market: 'jita', side: 'buy' },
    ])
    expect(pressedMarketButtons(wrapper)).toEqual(['Jita buy'])
  })

  it('writes the form chosen in the selector into the profile', async () => {
    const server = serverWith()
    const wrapper = await mountOreValueTab()

    const selector = wrapper.find('select[data-valuation-form]')
    expect(selector.exists()).toBe(true)
    const initial = [(selector.element as HTMLSelectElement).value, selector.findAll('option').map(option => option.element.value)]
    await selector.setValue('reprocessed')
    await flushPromises()

    expect(initial).toEqual(['compressed', ['raw', 'compressed', 'reprocessed']])
    expect(server.state.patches).toEqual([{ form: 'reprocessed' }])
  })
})
