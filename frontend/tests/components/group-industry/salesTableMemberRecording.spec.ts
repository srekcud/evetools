import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { GroupSale } from '@/stores/group-industry/types'
import type { Character, User } from '@/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SALES_PATH = `/industry/projects/${PROJECT_ID}/sales`
const M_MAIN_EVE_CHARACTER_ID = 2_120_000_021
const O_MAIN_EVE_CHARACTER_ID = 2_120_000_001

function sale(id: string, seller: [number, string], heldByViewer: boolean): GroupSale {
  return {
    id,
    typeId: 22_456,
    typeName: 'Sabre',
    quantity: 3,
    unitPrice: 61_250_000,
    totalPrice: 183_750_000,
    venue: 'Jita 4-4',
    channel: 'market',
    soldAt: '2026-10-24T21:05:00+00:00',
    recordedByCharacterName: seller[1],
    sellerCharacterId: seller[0],
    sellerCharacterName: seller[1],
    linkedToWalletTransaction: false,
    heldByViewer,
    createdAt: '2026-10-24T21:05:00+00:00',
  }
}

const SOLD_BY_THE_MEMBER = sale('sale-mine', [M_MAIN_EVE_CHARACTER_ID, 'M-Main'], true)
const SOLD_BY_THE_OWNER = sale('sale-owner', [O_MAIN_EVE_CHARACTER_ID, 'O-Main'], false)

function member(): User {
  const main: Character = {
    id: 'character-m',
    eveCharacterId: M_MAIN_EVE_CHARACTER_ID,
    name: 'M-Main',
    corporationId: 98_000_001,
    corporationName: 'Sabre Corp',
    allianceId: null,
    allianceName: null,
    isMain: true,
    hasValidToken: true,
    lastSyncAt: null,
  }
  return { id: 'user-m', authStatus: 'valid', mainCharacter: main, characters: [main], corporationId: 98_000_001, corporationName: 'Sabre Corp' }
}

async function mountSales(props: { isAdmin: boolean; canRecord: boolean }, sales: GroupSale[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint === SALES_PATH ? sales : {}))
  useAuthStore().user = member()
  const wrapper = mount(SalesTable, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })],
      stubs: { LoadingSpinner: true, ConfirmModal: true },
    },
    props: { projectId: PROJECT_ID, ...props },
  })
  await flushPromises()
  return wrapper
}

function rowActions(wrapper: VueWrapper): string[][] {
  return wrapper.findAll('tbody tr')
    .filter(row => row.text().includes('Sabre'))
    .map(row => row.findAll('button').map(button => button.text()))
}

function recordSaleButtons(wrapper: VueWrapper): number {
  return wrapper.findAll('button').filter(button => button.text() === 'Record Sale').length
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('sales table for a member who is neither owner nor admin', () => {
  it('lets an accepted member record a sale', async () => {
    const wrapper = await mountSales({ isAdmin: false, canRecord: true }, [])

    expect(recordSaleButtons(wrapper)).toBe(1)
  })

  it('offers edit and delete on the sale held by the member only', async () => {
    const wrapper = await mountSales({ isAdmin: false, canRecord: true }, [SOLD_BY_THE_MEMBER, SOLD_BY_THE_OWNER])

    expect(rowActions(wrapper)).toEqual([['Edit', 'Delete'], []])
  })

  it('shows neither the record button nor the row actions to someone who may not record', async () => {
    const wrapper = await mountSales({ isAdmin: false, canRecord: false }, [SOLD_BY_THE_MEMBER, SOLD_BY_THE_OWNER])

    expect([recordSaleButtons(wrapper), rowActions(wrapper)]).toEqual([0, [[], []]])
  })

  it('keeps edit and delete on every sale for a manager', async () => {
    const wrapper = await mountSales({ isAdmin: true, canRecord: true }, [SOLD_BY_THE_MEMBER, SOLD_BY_THE_OWNER])

    expect([recordSaleButtons(wrapper), rowActions(wrapper)]).toEqual([1, [['Edit', 'Delete'], ['Edit', 'Delete']]])
  })
})
