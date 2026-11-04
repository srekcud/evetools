import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import { i18nPlugin } from './miningLedgerHarness'
import { NOW, VALUED_CHUNK, corporation, response } from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const EXTRACTIONS_ENDPOINT = '/mining/corporation-extractions'
const COMPOSITIONS_ENDPOINT = '/mining/moon-compositions'
const PASTED_SCAN = 'Bitumens\t40 %\nChromite\t0,6'
const FORBIDDEN_STATUS = 403
const UNAVAILABLE_STATUS = 503

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

async function pasteRefusedWith(status: number) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === EXTRACTIONS_ENDPOINT) return Promise.resolve(response(corporation({ extractions: [VALUED_CHUNK] })))
    if (endpoint === COMPOSITIONS_ENDPOINT) return Promise.reject(new ApiError('refused', status))
    return Promise.resolve(null)
  })
  const wrapper = mount(MoonChunksTab, { global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  await wrapper.get(`[data-extraction-id="${VALUED_CHUNK.id}"] [data-toggle-detail]`).trigger('click')
  await wrapper.get(`[data-detail="${VALUED_CHUNK.id}"] [data-repaste]`).trigger('click')
  const detail = wrapper.get(`[data-detail="${VALUED_CHUNK.id}"]`)
  await detail.get('textarea[data-scan-input]').setValue(PASTED_SCAN)
  await detail.get('button[data-record-composition]').trigger('click')
  await flushPromises()
  return { wrapper, detail }
}

describe('MoonCompositionPasteZone refusal of a replacement', () => {
  it('tells a member who may not replace the scan, keeps the paste and does not reload the extractions', async () => {
    const { wrapper, detail } = await pasteRefusedWith(FORBIDDEN_STATUS)

    const message = detail.get('[data-scan-failure="forbidden"]')

    expect(message.text().length).toBeGreaterThan(0)
    expect(message.text()).not.toContain('moonChunks.')
    expect((detail.get('textarea[data-scan-input]').element as HTMLTextAreaElement).value).toBe(PASTED_SCAN)
    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === EXTRACTIONS_ENDPOINT)).toHaveLength(1)
    expect(wrapper.find('[data-scan-failure="failure"]').exists()).toBe(false)
  })

  it('tells apart unreadable corporation roles from a refusal and from a generic failure', async () => {
    const { detail } = await pasteRefusedWith(UNAVAILABLE_STATUS)

    const message = detail.get('[data-scan-failure="roles_unavailable"]')

    expect(message.text().length).toBeGreaterThan(0)
    expect(message.text()).not.toContain('moonChunks.')
    expect(detail.find('[data-scan-failure="forbidden"]').exists()).toBe(false)
  })
})
