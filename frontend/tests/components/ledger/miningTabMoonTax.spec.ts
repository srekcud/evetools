import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
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

describe('MiningTab moon tax sub-tab', () => {
  it('opens the moon tax screens from the mining tab', async () => {
    const wrapper = await mountTab()
    const moonTaxView = wrapper.find('[data-mining-view="moonTax"]')
    expect(moonTaxView.exists()).toBe(true)
    expect(wrapper.find('[data-moon-tax-tab]').exists()).toBe(false)

    await moonTaxView.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-moon-tax-tab]').exists()).toBe(true)
    expect(moonTaxView.attributes('aria-pressed')).toBe('true')
  })
})
