import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import type { CorporationMiningExtraction, CorporationMiningExtractionsResponse } from '@/composables/useCorporationMiningExtractions'
import { i18nPlugin } from './miningLedgerHarness'
import {
  CORPORATION_A,
  LONG_MOON_CHUNK,
  LONG_MOON_NAME,
  NOW,
  NO_PRICE_CHUNK,
  NO_SCAN_CHUNK,
  VALUED_CHUNK,
  corporation,
  extraction,
  response,
} from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const EXTRACTIONS_ENDPOINT = '/mining/corporation-extractions'
const COMPOSITIONS_ENDPOINT = '/mining/moon-compositions'
const PASTED_SCAN = 'Bitumens\t40 %\nChromite\t0,6'
const THURSDAY = '2026-11-05'

const EXPLODED_TUESDAY_CHUNK = extraction({
  id: '0195f3a0-2740-7000-8000-000000000305',
  moonId: 40301015,
  extractionStartTime: '2026-10-30T19:00:00+00:00',
  chunkArrivalTime: '2026-11-03T19:00:00+00:00',
  naturalDecayTime: '2026-11-03T22:00:00+00:00',
  status: 'exploded',
  chunkValue: null,
})

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountTab(chunks: CorporationMiningExtraction[]) {
  setActivePinia(createPinia())
  const loaded: CorporationMiningExtractionsResponse = response(corporation({ extractions: chunks }))
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === EXTRACTIONS_ENDPOINT) return Promise.resolve(loaded)
    return Promise.resolve(null)
  })
  const wrapper = mount(MoonChunksTab, { global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  return wrapper
}

type TabWrapper = Awaited<ReturnType<typeof mountTab>>

async function showView(wrapper: TabWrapper, view: 'list' | 'week' | 'month') {
  await wrapper.get(`[data-view="${view}"]`).trigger('click')
}

function present(wrapper: TabWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

function chunkValueLine(wrapper: TabWrapper, chunk: CorporationMiningExtraction) {
  return present(wrapper, `[data-chunk="${chunk.id}"] [data-cell="value"]`)
}

function compact(text: string): string {
  return text.replace(/[\s,.\u00a0\u202f]/g, '')
}

function requests(): Array<{ endpoint: string; method: string; body: unknown }> {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => ({
    endpoint,
    method: options?.method?.toUpperCase() ?? 'GET',
    body: typeof options?.body === 'string' ? JSON.parse(options.body) : null,
  }))
}

describe('MoonChunksTab calendar chunk value', () => {
  it('shows the estimated value and the share at six in the Thursday cell of the week and of the month', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])

    await showView(wrapper, 'week')
    const inWeek = chunkValueLine(wrapper, VALUED_CHUNK)
    const weekText = compact(inWeek.text())
    await showView(wrapper, 'month')
    const inMonth = present(wrapper, `[data-calendar-day="${THURSDAY}"] [data-chunk="${VALUED_CHUNK.id}"] [data-cell="value"]`)

    expect(inWeek.attributes('data-value-status')).toBe('known')
    expect(weekText).toContain('40700000')
    expect(weekText).toContain('6783333')
    expect(inMonth.attributes('data-value-status')).toBe('known')
    expect(compact(inMonth.text())).toBe(weekText)
    expect(weekText).not.toContain('moonChunks.')
  })

  it('says the value is unknown without any figure and tells a missing price apart from a missing scan', async () => {
    const wrapper = await mountTab([NO_SCAN_CHUNK, NO_PRICE_CHUNK])

    await showView(wrapper, 'week')
    const noScan = chunkValueLine(wrapper, NO_SCAN_CHUNK)
    const noPrice = chunkValueLine(wrapper, NO_PRICE_CHUNK)

    expect(noScan.attributes('data-value-status')).toBe('unknown')
    expect(noScan.attributes('data-unknown-reason')).toBe('no_scan')
    expect(noPrice.attributes('data-unknown-reason')).toBe('no_price')
    expect(noPrice.text()).not.toBe(noScan.text())
    expect([noScan.text(), noPrice.text()].join(' ')).not.toMatch(/\d/)
    expect(noScan.text().length).toBeGreaterThan(0)
    expect(noScan.text()).not.toContain('moonChunks.')
  })

  it('shows no value line in the cell of a chunk without an estimate and keeps its time and status', async () => {
    const wrapper = await mountTab([EXPLODED_TUESDAY_CHUNK])

    await showView(wrapper, 'week')
    const cell = present(wrapper, `[data-chunk="${EXPLODED_TUESDAY_CHUNK.id}"]`)

    expect(cell.find('[data-cell="value"]').exists()).toBe(false)
    expect(cell.get('[data-chunk-time]').text()).toBe('19:00')
    expect(cell.attributes('data-status')).toBe('exploded')
  })

  it('opens the detail of the chunk under the calendar from its value line and closes it on a second click', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])
    await showView(wrapper, 'week')

    const toggle = present(wrapper, `[data-chunk="${VALUED_CHUNK.id}"] [data-toggle-detail]`)
    const before = toggle.attributes('aria-expanded')
    await toggle.trigger('click')
    const detail = `[data-detail="${VALUED_CHUNK.id}"]`
    const textOf = (selector: string) => compact(present(wrapper, `${detail} ${selector}`).text())

    expect(before).toBe('false')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(textOf('[data-detail-total]')).toContain('40700000')
    expect([4, 6, 8].map(count => textOf(`[data-share="${count}"]`))).toEqual([
      expect.stringContaining('10175000'),
      expect.stringContaining('6783333'),
      expect.stringContaining('5087500'),
    ])
    expect(textOf('[data-line-type-id="45501"]')).toMatch(/Chromite.*60.*111000.*33300000/)
    expect(present(wrapper, `${detail} [data-scan-recorded-at]`).text()).toContain('2026-11-04 09:30')

    await toggle.trigger('click')

    expect(wrapper.find(detail).exists()).toBe(false)
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })

  it('sends the scan pasted in the calendar detail for the corporation and the moon then reloads the extractions', async () => {
    const wrapper = await mountTab([NO_SCAN_CHUNK])
    await showView(wrapper, 'week')

    await present(wrapper, `[data-chunk="${NO_SCAN_CHUNK.id}"] [data-toggle-detail]`).trigger('click')
    const detail = present(wrapper, `[data-detail="${NO_SCAN_CHUNK.id}"]`)
    await detail.get('textarea[data-scan-input]').setValue(PASTED_SCAN)
    await detail.get('button[data-record-composition]').trigger('click')
    await flushPromises()

    expect(requests()).toEqual([
      { endpoint: EXTRACTIONS_ENDPOINT, method: 'GET', body: null },
      { endpoint: COMPOSITIONS_ENDPOINT, method: 'POST', body: { corporationId: CORPORATION_A, moonId: NO_SCAN_CHUNK.moonId, scan: PASTED_SCAN } },
      { endpoint: EXTRACTIONS_ENDPOINT, method: 'GET', body: null },
    ])
  })

  it('keeps the detail opened in the list open in the calendar', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])

    await present(wrapper, `[data-extraction-id="${VALUED_CHUNK.id}"] [data-toggle-detail]`).trigger('click')
    await showView(wrapper, 'week')

    expect(chunkValueLine(wrapper, VALUED_CHUNK).get('[data-toggle-detail]').attributes('aria-expanded')).toBe('true')
    present(wrapper, `[data-detail="${VALUED_CHUNK.id}"]`)
  })

  it('shows the full name of a long moon in the calendar detail next to an unknown value line', async () => {
    const wrapper = await mountTab([LONG_MOON_CHUNK])
    await showView(wrapper, 'week')

    await present(wrapper, `[data-chunk="${LONG_MOON_CHUNK.id}"] [data-toggle-detail]`).trigger('click')

    expect(chunkValueLine(wrapper, LONG_MOON_CHUNK).attributes('data-value-status')).toBe('unknown')
    expect(present(wrapper, `[data-detail="${LONG_MOON_CHUNK.id}"] [data-detail-moon]`).text()).toBe(LONG_MOON_NAME)
  })

  it('keeps the list value cell and its detail unchanged', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])

    const cell = present(wrapper, `[data-extraction-id="${VALUED_CHUNK.id}"] td[data-cell="value"]`)
    await present(wrapper, `[data-extraction-id="${VALUED_CHUNK.id}"] [data-toggle-detail]`).trigger('click')

    expect(cell.attributes('data-value-status')).toBe('known')
    expect(compact(present(wrapper, `[data-detail="${VALUED_CHUNK.id}"] [data-detail-total]`).text())).toContain('40700000')
  })
})
