import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ValidationError, apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import type { CorporationMiningExtraction, CorporationMiningExtractionsResponse } from '@/composables/useCorporationMiningExtractions'
import { i18nPlugin } from './miningLedgerHarness'
import {
  CORPORATION_A,
  EXPLODED_CHUNK,
  LONG_MOON_CHUNK,
  LONG_MOON_NAME,
  NOW,
  NO_PRICE_CHUNK,
  NO_SCAN_CHUNK,
  VALUED_CHUNK,
  corporation,
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

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountTab(chunks: CorporationMiningExtraction[], recordComposition: () => Promise<unknown> = () => Promise.resolve(null)) {
  setActivePinia(createPinia())
  const loaded: CorporationMiningExtractionsResponse = response(corporation({ extractions: chunks }))
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === EXTRACTIONS_ENDPOINT) return Promise.resolve(loaded)
    if (endpoint === COMPOSITIONS_ENDPOINT) return recordComposition()
    return Promise.resolve(null)
  })
  const wrapper = mount(MoonChunksTab, { global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  return wrapper
}

type TabWrapper = Awaited<ReturnType<typeof mountTab>>

function present(wrapper: TabWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

function valueCell(wrapper: TabWrapper, chunk: CorporationMiningExtraction) {
  return present(wrapper, `[data-extraction-id="${chunk.id}"] [data-cell="value"]`)
}

function compact(text: string): string {
  return text.replace(/[\s,.\u00a0\u202f]/g, '')
}

async function openDetail(wrapper: TabWrapper, chunk: CorporationMiningExtraction) {
  await present(wrapper, `[data-extraction-id="${chunk.id}"] [data-toggle-detail]`).trigger('click')
  return present(wrapper, `[data-detail="${chunk.id}"]`)
}

function requests(): Array<{ endpoint: string; method: string; body: unknown }> {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => ({
    endpoint,
    method: options?.method?.toUpperCase() ?? 'GET',
    body: typeof options?.body === 'string' ? JSON.parse(options.body) : null,
  }))
}

describe('MoonChunksTab chunk value', () => {
  it('says the value is unknown and asks for the scan of the moon instead of showing zero', async () => {
    const wrapper = await mountTab([NO_SCAN_CHUNK])
    const cell = valueCell(wrapper, NO_SCAN_CHUNK)

    expect(cell.attributes('data-value-status')).toBe('unknown')
    expect(cell.attributes('data-unknown-reason')).toBe('no_scan')
    expect(cell.text().length).toBeGreaterThan(0)
    expect(cell.text()).not.toMatch(/\d/)
    expect(cell.text()).not.toContain('moonChunks.')
  })

  it('tells a missing price apart from a missing scan and never shows zero either', async () => {
    const wrapper = await mountTab([NO_SCAN_CHUNK, NO_PRICE_CHUNK])
    const noScan = valueCell(wrapper, NO_SCAN_CHUNK)
    const noPrice = valueCell(wrapper, NO_PRICE_CHUNK)

    expect(noPrice.attributes('data-unknown-reason')).toBe('no_price')
    expect(noPrice.text()).not.toBe(noScan.text())
    expect(noPrice.text()).not.toMatch(/\d/)
    expect(noPrice.text()).not.toContain('moonChunks.')
  })

  it('shows the estimated value and the share of each participant at six on the row of a known chunk', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])
    const cell = valueCell(wrapper, VALUED_CHUNK)

    expect(cell.attributes('data-value-status')).toBe('known')
    expect(compact(cell.text())).toContain('40700000')
    expect(compact(cell.text())).toContain('6783333')
    expect(cell.text()).not.toContain('moonChunks.')
  })

  it('shows no estimated value for an exploded chunk', async () => {
    const wrapper = await mountTab([EXPLODED_CHUNK])

    expect(wrapper.find(`[data-extraction-id="${EXPLODED_CHUNK.id}"] [data-value-status]`).exists()).toBe(false)
  })

  it('shows in the detail of a known chunk the total, the shares at four, six and eight, the ore table and the date of the scan', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])
    const detail = await openDetail(wrapper, VALUED_CHUNK)
    const textOf = (selector: string) => compact(present(wrapper, `[data-detail="${VALUED_CHUNK.id}"] ${selector}`).text())

    expect(textOf('[data-detail-total]')).toContain('40700000')
    expect([4, 6, 8].map(count => textOf(`[data-share="${count}"]`))).toEqual([
      expect.stringContaining('10175000'),
      expect.stringContaining('6783333'),
      expect.stringContaining('5087500'),
    ])
    expect(['45492', '45501'].map(typeId => textOf(`[data-line-type-id="${typeId}"]`))).toEqual([
      expect.stringMatching(/Bitumens.*40.*74000.*7400000/),
      expect.stringMatching(/Chromite.*60.*111000.*33300000/),
    ])
    expect(present(wrapper, `[data-detail="${VALUED_CHUNK.id}"] [data-scan-recorded-at]`).text()).toContain('2026-11-04 09:30')
    expect(detail.find('textarea[data-scan-input]').exists()).toBe(false)
  })

  it('shows the volume as the hours of extraction times the extraction rate', async () => {
    const wrapper = await mountTab([VALUED_CHUNK])
    await openDetail(wrapper, VALUED_CHUNK)

    const formula = compact(present(wrapper, `[data-detail="${VALUED_CHUNK.id}"] [data-formula]`).text())

    expect(formula).toContain('74')
    expect(formula).toContain('25000')
    expect(formula).toContain('1850000')
  })

  it('offers the paste zone at once without a scan and on demand for a moon already scanned', async () => {
    const wrapper = await mountTab([NO_SCAN_CHUNK, VALUED_CHUNK])

    const unscanned = await openDetail(wrapper, NO_SCAN_CHUNK)
    const scanned = await openDetail(wrapper, VALUED_CHUNK)
    const before = scanned.find('textarea[data-scan-input]').exists()
    await present(wrapper, `[data-detail="${VALUED_CHUNK.id}"] [data-repaste]`).trigger('click')

    expect(unscanned.find('textarea[data-scan-input]').exists()).toBe(true)
    expect(unscanned.find('button[data-record-composition]').exists()).toBe(true)
    expect(before).toBe(false)
    expect(scanned.find('textarea[data-scan-input]').exists()).toBe(true)
  })

  it('sends the pasted scan for the corporation and the moon then reloads the extractions', async () => {
    const wrapper = await mountTab([NO_SCAN_CHUNK])
    const detail = await openDetail(wrapper, NO_SCAN_CHUNK)

    await detail.get('textarea[data-scan-input]').setValue(PASTED_SCAN)
    await detail.get('button[data-record-composition]').trigger('click')
    await flushPromises()

    expect(requests()).toEqual([
      { endpoint: EXTRACTIONS_ENDPOINT, method: 'GET', body: null },
      { endpoint: COMPOSITIONS_ENDPOINT, method: 'POST', body: { corporationId: CORPORATION_A, moonId: NO_SCAN_CHUNK.moonId, scan: PASTED_SCAN } },
      { endpoint: EXTRACTIONS_ENDPOINT, method: 'GET', body: null },
    ])
  })

  it('shows each refusal of the scan on its line, keeps the paste and does not reload the extractions', async () => {
    const refusal = new ValidationError({
      '@type': 'ConstraintViolationList',
      status: 422,
      description: 'scan[2]: Unknown ore name',
      detail: 'scan[2]: Unknown ore name',
      violations: [
        { propertyPath: 'scan[2]', message: 'Line 2: unknown ore name "Unobtainium"' },
        { propertyPath: 'scan', message: 'The shares must add up to 100 %' },
      ],
    })
    const wrapper = await mountTab([NO_SCAN_CHUNK], () => Promise.reject(refusal))
    const detail = await openDetail(wrapper, NO_SCAN_CHUNK)

    await detail.get('textarea[data-scan-input]').setValue(PASTED_SCAN)
    await detail.get('button[data-record-composition]').trigger('click')
    await flushPromises()

    expect(present(wrapper, '[data-scan-error="2"]').text()).toContain('Unobtainium')
    expect(present(wrapper, '[data-scan-error="scan"]').text()).toContain('100 %')
    expect((detail.get('textarea[data-scan-input]').element as HTMLTextAreaElement).value).toBe(PASTED_SCAN)
    expect(requests().filter(request => request.method === 'GET')).toHaveLength(1)
  })

  it('shows the full name of a long moon in the detail next to the value line of its row', async () => {
    const wrapper = await mountTab([LONG_MOON_CHUNK])

    const detail = await openDetail(wrapper, LONG_MOON_CHUNK)

    expect(valueCell(wrapper, LONG_MOON_CHUNK).attributes('data-value-status')).toBe('unknown')
    expect(detail.get('[data-detail-moon]').text()).toBe(LONG_MOON_NAME)
  })
})
