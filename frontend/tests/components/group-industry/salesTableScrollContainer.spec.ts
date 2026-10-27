import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import type { GroupSale } from '@/stores/group-industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-226'

const FEROX_SALE: GroupSale = {
  id: 'sale-ferox',
  typeId: 37_480,
  typeName: 'Ferox',
  quantity: 4,
  unitPrice: 62_000_000,
  totalPrice: 248_000_000,
  venue: 'Jita 4-4',
  soldAt: '2026-10-21T14:00:00+00:00',
  recordedByCharacterName: 'Bob Builder',
  createdAt: '2026-10-21T14:05:00+00:00',
}

const SABRE_SALE: GroupSale = {
  id: 'sale-sabre',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 2,
  unitPrice: 48_500_000,
  totalPrice: 97_000_000,
  venue: null,
  soldAt: '2026-10-22T09:30:00+00:00',
  recordedByCharacterName: null,
  createdAt: '2026-10-22T09:31:00+00:00',
}

async function mountSales() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValue([FEROX_SALE, SABRE_SALE])
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID, isAdmin: false },
  })
  await flushPromises()
  return wrapper
}

describe('SalesTable on a narrow screen', () => {
  it('puts the sales table in a horizontally scrollable container', async () => {
    const wrapper = await mountSales()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})
