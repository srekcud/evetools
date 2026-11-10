import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import OreSalePanel from '@/components/ledger/OreSalePanel.vue'
import type { OreSaleResponse } from '@/composables/useOreSale'
import { ApiError, apiRequest } from '@/services/api'
import { messageOf } from '../../localeTexts'
import { i18nPlugin } from './miningLedgerHarness'
import { valuationProfileServer } from './miningValuationProfileHarness'
import { PRICED_SALE } from './oreSaleHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPERATION_ID = '0b1c5d1e-5f3a-4c53-9a43-7c2f0f6d0314'
const MYSTERY_ORE_TYPE_ID = 99314001

async function mountOperationPanel(answer: () => Promise<OreSaleResponse>) {
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.mocked(apiRequest).mockImplementation(valuationProfileServer().respondingWith(() => answer()))
  const wrapper = mount(OreSalePanel, { props: { operationId: OPERATION_ID }, global: { plugins: [i18nPlugin(), pinia] } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  localStorage.clear()
})

describe('OreSalePanel of an operation', () => {
  it('asks for the ore sale of its operation and shows the rows of the answer', async () => {
    const wrapper = await mountOperationPanel(() => Promise.resolve(PRICED_SALE))

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint !== '/mining/valuation-profile')).toEqual([`/mining-operations/${OPERATION_ID}/ore-sale`])
    expect(wrapper.findAll('tbody tr[data-type-id]')).toHaveLength(PRICED_SALE.rows.length)
    expect(wrapper.find('[data-ore-sale]').exists()).toBe(true)
  })

  it('says the quantities are not known yet on a conflict, without the load error and without a usable copy button', async () => {
    const wrapper = await mountOperationPanel(() => Promise.reject(new ApiError('not known yet', 409)))

    expect(wrapper.get('[data-not-known-yet]').text()).toBe(messageOf('en', 'ledger.mining.oreSale.notKnownYet'))
    expect(wrapper.find('[data-load-error]').exists()).toBe(false)
    expect(wrapper.get('[data-copy-summary]').attributes('disabled')).toBeDefined()
  })

  it('lists the mined types that could not be converted, by name or by id when the name is missing', async () => {
    const wrapper = await mountOperationPanel(() => Promise.resolve({
      ...PRICED_SALE,
      operationId: OPERATION_ID,
      unconvertedTypes: [{ typeId: 17470, typeName: 'Mystery Ore' }, { typeId: MYSTERY_ORE_TYPE_ID, typeName: null }],
    }))

    const note = wrapper.get('[data-unconverted]').text()
    expect(note).toContain('Mystery Ore')
    expect(note).toContain(String(MYSTERY_ORE_TYPE_ID))
  })

  it('shows no unconverted note when every mined type was converted', async () => {
    const wrapper = await mountOperationPanel(() => Promise.resolve({ ...PRICED_SALE, operationId: OPERATION_ID, unconvertedTypes: [] }))

    expect(wrapper.find('[data-unconverted]').exists()).toBe(false)
  })
})
