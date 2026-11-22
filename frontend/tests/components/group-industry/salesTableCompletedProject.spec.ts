import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
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
const SALES_PATH = `/industry/projects/${PROJECT_ID}/sales`

const SABRE_SALE: GroupSale = {
  id: 'sale-sabre',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  venue: 'Jita 4-4',
  channel: 'market',
  soldAt: '2026-10-24T21:05:00+00:00',
  recordedByCharacterName: 'Aria Solberg',
  sellerCharacterId: 2_120_000_001,
  sellerCharacterName: 'Aria Solberg',
  linkedToWalletTransaction: false,
  heldByViewer: true,
  createdAt: '2026-10-24T21:05:00+00:00',
}

async function mountSales(isCompleted: boolean): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint === SALES_PATH ? [SABRE_SALE] : {}))
  const wrapper = mount(SalesTable, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })],
      stubs: { LoadingSpinner: true, ConfirmModal: true },
    },
    props: { projectId: PROJECT_ID, isAdmin: true, canRecord: true, isCompleted },
  })
  await flushPromises()
  return wrapper
}

function buttonLabels(wrapper: VueWrapper): string[] {
  return wrapper.findAll('button').map(button => button.text())
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('sales table of a completed project', () => {
  it('lists the sales without any record, edit or delete action', async () => {
    const wrapper = await mountSales(true)

    expect({
      sale: wrapper.text().includes('Sabre'),
      actions: buttonLabels(wrapper).filter(label => ['Record Sale', 'Edit', 'Delete'].includes(label)),
    }).toEqual({ sale: true, actions: [] })
  })

  it('keeps the record, edit and delete actions of the same sale on a project still open', async () => {
    const wrapper = await mountSales(false)

    expect(buttonLabels(wrapper).filter(label => ['Record Sale', 'Edit', 'Delete'].includes(label))).toEqual(['Record Sale', 'Edit', 'Delete'])
  })
})
