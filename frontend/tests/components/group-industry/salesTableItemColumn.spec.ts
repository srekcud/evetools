import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import type { GroupSale } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'

function sale(id: string, typeId: number, typeName: string, quantity: number, unitPrice: number, soldAt: string): GroupSale {
  return {
    id,
    typeId,
    typeName,
    quantity,
    unitPrice,
    totalPrice: quantity * unitPrice,
    venue: 'Jita 4-4',
    channel: 'market',
    soldAt,
    recordedByCharacterName: 'O-Main',
    sellerCharacterId: 2_120_000_001,
    sellerCharacterName: 'O-Main',
    linkedToWalletTransaction: false,
    createdAt: soldAt,
  }
}

const SABRE_SALE = sale('sale-sabre', 22_456, 'Sabre', 3, 61_250_000, '2026-10-24T21:05:00+00:00')
const HERETIC_SALE = sale('sale-heretic', 22_460, 'Heretic', 2, 15_500_000, '2026-10-23T19:10:00+00:00')

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function columnIndex(wrapper: VueWrapper, header: string): number {
  const index = wrapper.findAll('thead th').findIndex(cell => normalized(cell.text()) === header)
  if (index === -1) throw new Error(`no column "${header}"`)
  return index
}

function saleRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr').filter(row => !['Total', 'Total, my sales'].includes(normalized(row.find('td').text())))
}

async function mountSales(): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    props: { projectId: PROJECT_ID, isAdmin: true },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async () => [SABRE_SALE, HERETIC_SALE])
})

describe('SalesTable item column', () => {
  it('shows the product of each sale in an Item column', async () => {
    const wrapper = await mountSales()

    const itemIndex = columnIndex(wrapper, 'Item')

    expect(saleRows(wrapper).map(row => normalized(row.findAll('td')[itemIndex].text()))).toEqual(['Sabre', 'Heretic'])
  })

  it('keeps the quantity of each sale under its own column next to the item', async () => {
    const wrapper = await mountSales()

    columnIndex(wrapper, 'Item')
    const quantityIndex = columnIndex(wrapper, 'Quantity')

    expect(saleRows(wrapper).map(row => normalized(row.findAll('td')[quantityIndex].text()))).toEqual(['3', '2'])
  })

  it('keeps every row as wide as the header, total row included', async () => {
    const wrapper = await mountSales()
    columnIndex(wrapper, 'Item')

    const headerWidth = wrapper.findAll('thead th').length

    expect(wrapper.findAll('tbody tr').map(row => row.findAll('td').length)).toEqual([headerWidth, headerWidth, headerWidth])
  })
})
