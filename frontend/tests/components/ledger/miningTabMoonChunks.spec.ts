import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import { apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import { mountTab, respondWith } from './miningLedgerHarness'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  route.query = {}
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

  it('starts on the moon chunks view when the route asks for it', async () => {
    route.query = { view: 'moonChunks' }

    const wrapper = await mountTab()

    expect(wrapper.findComponent(MoonChunksTab).exists()).toBe(true)
    expect(wrapper.find('[data-mining-view="moonChunks"]').attributes('aria-pressed')).toBe('true')
  })
})
