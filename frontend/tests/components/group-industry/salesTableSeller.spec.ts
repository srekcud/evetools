import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
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
const A_MAIN_EVE_CHARACTER_ID = 2_120_000_011
const M_MAIN_EVE_CHARACTER_ID = 2_120_000_021

const TOTAL_LABELS = ['Total', 'Total, my sales', 'Total, mes ventes']

function sale(id: string, quantity: number, unitPrice: number, soldAt: string, seller: [number, string] | null, recorder: string): GroupSale {
  return {
    id,
    typeId: 0,
    typeName: 'Sabre',
    quantity,
    unitPrice,
    totalPrice: quantity * unitPrice,
    venue: 'Jita 4-4',
    soldAt,
    recordedByCharacterName: recorder,
    sellerCharacterId: seller === null ? null : seller[0],
    sellerCharacterName: seller === null ? null : seller[1],
    linkedToWalletTransaction: false,
    createdAt: soldAt,
  }
}

const S1 = sale('sale-s1', 3, 61_250_000, '2026-10-24T21:05:00+00:00', [O_MAIN_EVE_CHARACTER_ID, 'O-Main'], 'O-Main')
const S2 = sale('sale-s2', 2, 60_000_000, '2026-10-23T19:10:00+00:00', [O_ALT_EVE_CHARACTER_ID, 'O-Alt'], 'O-Main')
const S3 = sale('sale-s3', 1, 62_000_000, '2026-10-22T11:20:00+00:00', [A_MAIN_EVE_CHARACTER_ID, 'A-Main'], 'A-Main')
const S4 = sale('sale-s4', 1, 59_500_000, '2026-10-21T08:00:00+00:00', null, 'O-Main')

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

function account(id: string, characters: Character[]): User {
  return {
    id,
    authStatus: 'valid',
    mainCharacter: characters[0],
    characters,
    corporationId: 98_000_001,
    corporationName: 'Sabre Corp',
  }
}

const OWNER_O = account('user-o', [character(O_MAIN_EVE_CHARACTER_ID, 'O-Main', true), character(O_ALT_EVE_CHARACTER_ID, 'O-Alt', false)])
const ADMIN_A = account('user-a', [character(A_MAIN_EVE_CHARACTER_ID, 'A-Main', true)])
const MEMBER_M = account('user-m', [character(M_MAIN_EVE_CHARACTER_ID, 'M-Main', true)])

async function mountSalesAs(reader: User, isAdmin: boolean, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  useAuthStore().user = reader
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    props: { projectId: PROJECT_ID, isAdmin },
  })
  await flushPromises()
  return wrapper
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function columnIndex(wrapper: VueWrapper, header: string): number {
  const index = wrapper.findAll('thead th').findIndex(cell => normalized(cell.text()) === header)
  if (index === -1) throw new Error(`no column "${header}"`)
  return index
}

function bodyRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr')
}

function isTotalRow(row: DOMWrapper<Element>): boolean {
  const firstCell = row.find('td')
  return firstCell.exists() && TOTAL_LABELS.includes(normalized(firstCell.text()))
}

function saleRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return bodyRows(wrapper).filter(row => !isTotalRow(row))
}

function totalRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return bodyRows(wrapper).filter(isTotalRow)
}

function cellsOfColumn(rows: DOMWrapper<Element>[], index: number): string[] {
  return rows.map(row => normalized(row.findAll('td')[index].text()))
}

function sellersShown(wrapper: VueWrapper): string[] {
  return cellsOfColumn(saleRows(wrapper), columnIndex(wrapper, 'Seller'))
}

function totalRowShown(wrapper: VueWrapper): string[] {
  return totalRows(wrapper).map(row => {
    const cells = row.findAll('td')
    return [
      normalized(cells[0].text()),
      normalized(cells[columnIndex(wrapper, 'Quantity')].text()),
      normalized(cells[columnIndex(wrapper, 'Revenue')].text()),
    ].join(' | ')
  })
}

function mySalesToggle(wrapper: VueWrapper, label: string): DOMWrapper<HTMLInputElement> {
  const toggleLabel = wrapper.findAll('label').find(candidate => normalized(candidate.text()) === label)
  if (toggleLabel === undefined) throw new Error(`no label "${label}"`)
  const target = toggleLabel.attributes('for')
  const toggle = target === undefined
    ? toggleLabel.find<HTMLInputElement>('input[type="checkbox"]')
    : wrapper.find<HTMLInputElement>(`input[type="checkbox"][id="${target}"]`)
  if (!toggle.exists()) throw new Error(`no checkbox for "${label}"`)
  return toggle
}

async function showOnlyMySales(wrapper: VueWrapper, label = 'My sales'): Promise<void> {
  await mySalesToggle(wrapper, label).setValue(true)
}

function sellerField(wrapper: VueWrapper): DOMWrapper<HTMLSelectElement> {
  const fieldLabel = wrapper.findAll('label').find(candidate => normalized(candidate.text()) === 'Seller')
  if (fieldLabel === undefined) throw new Error('no label "Seller" in the sale form')
  const target = fieldLabel.attributes('for')
  const field = target === undefined
    ? fieldLabel.find<HTMLSelectElement>('select')
    : wrapper.find<HTMLSelectElement>(`select[id="${target}"]`)
  if (!field.exists()) throw new Error('no seller select in the sale form')
  return field
}

function sellerOptions(wrapper: VueWrapper): string[] {
  return sellerField(wrapper).findAll('option').map(option => normalized(option.text()))
}

function selectedSeller(wrapper: VueWrapper): string {
  const field = sellerField(wrapper).element
  return normalized(field.options[field.selectedIndex].text)
}

async function chooseSeller(wrapper: VueWrapper, name: string): Promise<void> {
  const field = sellerField(wrapper)
  const option = field.findAll<HTMLOptionElement>('option').find(candidate => normalized(candidate.text()) === name)
  if (option === undefined) throw new Error(`no seller option "${name}"`)
  option.element.selected = true
  await field.trigger('change')
}

function buttonNamed(scope: VueWrapper | DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

async function openRecordForm(wrapper: VueWrapper): Promise<void> {
  await buttonNamed(wrapper, 'Record Sale').trigger('click')
}

async function startCorrectionOf(wrapper: VueWrapper, saleIndex: number): Promise<void> {
  await buttonNamed(saleRows(wrapper)[saleIndex], 'Edit').trigger('click')
}

async function save(wrapper: VueWrapper): Promise<void> {
  await buttonNamed(wrapper, 'Save').trigger('click')
  await flushPromises()
}

function sentBodies(method: 'POST' | 'PATCH'): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([, options]) => options?.method === method)
    .map(([, options]) => JSON.parse(String(options?.body)))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'POST') return sale('sale-recorded', 3, 61_250_000, '2026-10-25T10:00:00+00:00', null, 'O-Main')
    if (options?.method === 'PATCH') return [S1, S2, S3, S4].find(listed => endpoint === `${SALES_PATH}/${listed.id}`)
    return [S1, S2, S3, S4]
  })
})

describe('SalesTable seller column', () => {
  it('shows the seller of each sale and Unknown seller when the seller is unknown', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    expect(sellersShown(wrapper)).toEqual(['O-Main', 'O-Alt', 'A-Main', 'Unknown seller'])
  })

  it('shows the seller column to a member who cannot record sales', async () => {
    const wrapper = await mountSalesAs(MEMBER_M, false)

    expect(sellersShown(wrapper)).toEqual(['O-Main', 'O-Alt', 'A-Main', 'Unknown seller'])
  })

  it('names the column and the unknown seller in French', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true, 'fr')

    expect(cellsOfColumn(saleRows(wrapper), columnIndex(wrapper, 'Vendeur'))).toEqual(['O-Main', 'O-Alt', 'A-Main', 'Vendeur inconnu'])
  })
})

describe('SalesTable my sales filter', () => {
  it('lists the four sales of the project with the project total while the filter is off', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    expect([saleRows(wrapper).length, totalRowShown(wrapper)]).toEqual([4, ['Total | 7 | 425,250,000']])
  })

  it('keeps for the owner the sales of his main and of his alt, with their total', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    await showOnlyMySales(wrapper)

    expect([sellersShown(wrapper), totalRowShown(wrapper)]).toEqual([['O-Main', 'O-Alt'], ['Total, my sales | 5 | 303,750,000']])
  })

  it('keeps for the admin the sale of his character only', async () => {
    const wrapper = await mountSalesAs(ADMIN_A, true)

    await showOnlyMySales(wrapper)

    expect([sellersShown(wrapper), totalRowShown(wrapper)]).toEqual([['A-Main'], ['Total, my sales | 1 | 62,000,000']])
  })

  it('shows a dedicated empty state to a member none of whose characters sold', async () => {
    const wrapper = await mountSalesAs(MEMBER_M, false)

    await showOnlyMySales(wrapper)

    expect([saleRows(wrapper).length, totalRows(wrapper).length]).toEqual([0, 0])
    expect(normalized(wrapper.text())).toContain('No sales by your characters')
    expect(normalized(wrapper.text())).not.toContain('No sales recorded yet')
  })

  it('never counts a sale of unknown seller among the sales of the reader', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    await showOnlyMySales(wrapper)

    expect(sellersShown(wrapper)).not.toContain('Unknown seller')
  })

  it('brings back the four sales and the project total once the filter is turned off', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await showOnlyMySales(wrapper)

    await mySalesToggle(wrapper, 'My sales').setValue(false)

    expect([sellersShown(wrapper), totalRowShown(wrapper)]).toEqual([
      ['O-Main', 'O-Alt', 'A-Main', 'Unknown seller'],
      ['Total | 7 | 425,250,000'],
    ])
  })

  it('filters the displayed rows without removing any sale from the store', async () => {
    const wrapper = await mountSalesAs(MEMBER_M, false)

    await showOnlyMySales(wrapper)

    expect(useGroupDistributionStore().sales.map(listed => listed.id)).toEqual(['sale-s1', 'sale-s2', 'sale-s3', 'sale-s4'])
  })

  it('names the filter and its total in French', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true, 'fr')

    await showOnlyMySales(wrapper, 'Mes ventes')

    expect(totalRows(wrapper).map(row => normalized(row.text()))).toEqual([expect.stringContaining('Total, mes ventes')])
    expect(totalRows(wrapper).map(row => normalized(row.text()))).toEqual([expect.stringContaining('303 750 000')])
  })
})

describe('SalesTable seller field', () => {
  it('offers Unknown then the characters of the reader, with Unknown selected, when recording a sale', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    await openRecordForm(wrapper)

    expect([sellerOptions(wrapper), selectedSeller(wrapper)]).toEqual([['Unknown', 'O-Main', 'O-Alt'], 'Unknown'])
  })

  it('records a sale without sellerCharacterId when the seller stays Unknown', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await openRecordForm(wrapper)
    await wrapper.find('input[placeholder="e.g. Ishtar"]').setValue('Sabre')
    await wrapper.findAll('input[type="number"]')[0].setValue(3)
    await wrapper.findAll('input[type="number"]')[1].setValue(61_250_000)

    await save(wrapper)

    expect(sentBodies('POST')).toEqual([{ typeId: 0, typeName: 'Sabre', quantity: 3, unitPrice: 61_250_000, venue: '' }])
  })

  it('records a sale with the EVE character id of the chosen alt', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await openRecordForm(wrapper)
    await wrapper.find('input[placeholder="e.g. Ishtar"]').setValue('Sabre')
    await wrapper.findAll('input[type="number"]')[0].setValue(3)
    await wrapper.findAll('input[type="number"]')[1].setValue(61_250_000)

    await chooseSeller(wrapper, 'O-Alt')
    await save(wrapper)

    expect(sentBodies('POST')).toEqual([
      { typeId: 0, typeName: 'Sabre', quantity: 3, unitPrice: 61_250_000, venue: '', sellerCharacterId: O_ALT_EVE_CHARACTER_ID },
    ])
  })

  it('prefills the correction with the seller of the sale when it is a character of the reader', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    await startCorrectionOf(wrapper, 1)

    expect(selectedSeller(wrapper)).toBe('O-Alt')
  })

  it('prefills the correction with Unknown when the seller is a character of another user', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)

    await startCorrectionOf(wrapper, 2)

    expect(selectedSeller(wrapper)).toBe('Unknown')
  })

  it('sends no sellerCharacterId when the prefilled seller is kept', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await startCorrectionOf(wrapper, 1)

    await save(wrapper)

    expect(sentBodies('PATCH')).toEqual([{}])
  })

  it('sends no sellerCharacterId when the seller of another user stays Unknown while the unit price changes', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await startCorrectionOf(wrapper, 2)
    await wrapper.findAll('input[type="number"]')[1].setValue(61_500_000)

    await save(wrapper)

    expect(sentBodies('PATCH')).toEqual([{ unitPrice: 61_500_000 }])
  })

  it('sends the EVE character id of the reader character chosen for a sale of another user', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await startCorrectionOf(wrapper, 2)

    await chooseSeller(wrapper, 'O-Main')
    await save(wrapper)

    expect(sentBodies('PATCH')).toEqual([{ sellerCharacterId: O_MAIN_EVE_CHARACTER_ID }])
  })

  it('sends the EVE character id of the alt that replaces the main as seller', async () => {
    const wrapper = await mountSalesAs(OWNER_O, true)
    await startCorrectionOf(wrapper, 0)

    await chooseSeller(wrapper, 'O-Alt')
    await save(wrapper)

    expect(sentBodies('PATCH')).toEqual([{ sellerCharacterId: O_ALT_EVE_CHARACTER_ID }])
  })
})
