import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import { mountTab, respondWith } from './miningLedgerHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  respondWith(
    () => new Promise(() => {}),
    () => new Promise(() => {}),
  )
})

describe('MiningTab moon chunks sub-tab', () => {
  it('opens the moon chunks of the corporations from the mining tab', async () => {
    const wrapper = await mountTab()
    const moonChunksView = wrapper.find('[data-mining-view="moonChunks"]')
    expect(moonChunksView.exists()).toBe(true)
    expect(wrapper.findComponent(MoonChunksTab).exists()).toBe(false)

    await moonChunksView.trigger('click')
    await flushPromises()

    expect(wrapper.findComponent(MoonChunksTab).exists()).toBe(true)
    expect(moonChunksView.attributes('aria-pressed')).toBe('true')
  })
})
