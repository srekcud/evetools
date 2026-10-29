import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
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
const SALES_PATH = `/group-industry/projects/${PROJECT_ID}/sales`
const O_MAIN_EVE_CHARACTER_ID = 2_120_000_001
const O_ALT_EVE_CHARACTER_ID = 2_120_000_002

const SALE_OF_T1: GroupSale = {
  id: 'sale-t1',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  venue: null,
  soldAt: '2026-10-24T21:05:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: O_MAIN_EVE_CHARACTER_ID,
  sellerCharacterName: 'O-Main',
  linkedToWalletTransaction: true,
  createdAt: '2026-10-29T08:00:00+00:00',
}

const MANUAL_SALE_V: GroupSale = {
  id: 'sale-v',
  typeId: 0,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  venue: 'Jita 4-4',
  soldAt: '2026-10-24T22:00:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: O_ALT_EVE_CHARACTER_ID,
  sellerCharacterName: 'O-Alt',
  linkedToWalletTransaction: false,
  createdAt: '2026-10-24T22:01:00+00:00',
}

function character(eveCharacterId: number, name: string, isMain: boolean): Character {
  return {
    id: `character-${eveCharacterId}`,
    eveCharacterId,
    name,
    corporationId: 98_000_001,
    corporationName: 'Sabre Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

const OWNER_O: User = {
  id: 'user-o',
  authStatus: 'valid',
  mainCharacter: character(O_MAIN_EVE_CHARACTER_ID, 'O-Main', true),
  characters: [character(O_MAIN_EVE_CHARACTER_ID, 'O-Main', true), character(O_ALT_EVE_CHARACTER_ID, 'O-Alt', false)],
  corporationId: 98_000_001,
  corporationName: 'Sabre Corp',
}

async function mountSalesOfTheOwner(): Promise<VueWrapper> {
  useAuthStore().user = OWNER_O
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    props: { projectId: PROJECT_ID, isAdmin: true },
  })
  await flushPromises()
  return wrapper
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function saleRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr').filter(row => row.findAll('button').length > 0)
}

function buttonNamed(scope: VueWrapper | DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

function fieldLabelled(wrapper: VueWrapper, label: string): DOMWrapper<HTMLInputElement | HTMLSelectElement> {
  const fieldLabel = wrapper.findAll('label').find(candidate => normalized(candidate.text()) === label)
  if (fieldLabel === undefined) throw new Error(`no label "${label}" in the sale form`)
  const target = fieldLabel.attributes('for')
  const field = wrapper.find<HTMLInputElement | HTMLSelectElement>(`[id="${target}"]`)
  if (!field.exists()) throw new Error(`no field for "${label}"`)
  return field
}

const FORM_FIELDS = ['Item Name', 'Quantity', 'Unit Price (ISK)', 'Venue', 'Seller']

function disabledFields(wrapper: VueWrapper): string[] {
  return FORM_FIELDS.filter(label => fieldLabelled(wrapper, label).element.disabled)
}

async function startCorrectionOf(wrapper: VueWrapper, saleIndex: number): Promise<void> {
  await buttonNamed(saleRows(wrapper)[saleIndex], 'Edit').trigger('click')
}

function patchedBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([, options]) => options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PATCH' && endpoint === `${SALES_PATH}/${SALE_OF_T1.id}`) return { ...SALE_OF_T1, quantity: 2, totalPrice: 122_500_000 }
    return [SALE_OF_T1, MANUAL_SALE_V]
  })
})

describe('SalesTable sale linked to a wallet transaction', () => {
  it('marks with a Wallet badge only the sale recorded from a wallet transaction', async () => {
    const wrapper = await mountSalesOfTheOwner()

    expect(saleRows(wrapper).map(row => normalized(row.text()).includes('Wallet'))).toEqual([true, false])
  })

  it('locks the unit price and the seller when correcting a linked sale', async () => {
    const wrapper = await mountSalesOfTheOwner()

    await startCorrectionOf(wrapper, 0)

    expect(disabledFields(wrapper)).toEqual(['Unit Price (ISK)', 'Seller'])
  })

  it('leaves every field free when correcting a manual sale', async () => {
    const wrapper = await mountSalesOfTheOwner()

    await startCorrectionOf(wrapper, 1)

    expect(disabledFields(wrapper)).toEqual([])
  })

  it('sends only the new quantity when correcting the quantity of a linked sale', async () => {
    const wrapper = await mountSalesOfTheOwner()
    await startCorrectionOf(wrapper, 0)

    await fieldLabelled(wrapper, 'Quantity').setValue(2)
    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(patchedBodies()).toEqual([{ quantity: 2 }])
  })
})
