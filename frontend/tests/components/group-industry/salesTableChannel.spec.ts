import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { GroupSale } from '@/stores/group-industry/types'
import type { User } from '@/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-hammerhead'

function sale(id: string, channel: GroupSale['channel'], totalPrice: number): GroupSale {
  return {
    id,
    typeId: 0,
    typeName: 'Hammerhead II',
    quantity: 1,
    unitPrice: totalPrice,
    totalPrice,
    venue: null,
    channel,
    soldAt: '2026-11-01T10:00:00+00:00',
    recordedByCharacterName: 'Owner',
    sellerCharacterId: null,
    sellerCharacterName: null,
    linkedToWalletTransaction: false,
    createdAt: '2026-11-01T10:00:00+00:00',
  }
}

const MARKET_SALE = sale('sale-market', 'market', 37_760_000)
const CONTRACT_SALE = sale('sale-contract', 'contract', 13_650_000)

const OWNER = { id: 'user-owner', authStatus: 'valid', mainCharacter: null, characters: [], corporationId: 1, corporationName: 'Corp' } as unknown as User

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

async function mountSales(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  useAuthStore().user = OWNER
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, fr } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    props: { projectId: PROJECT_ID, isAdmin: true },
  })
  await flushPromises()
  return wrapper
}

function channelCells(wrapper: VueWrapper, header: string): string[] {
  const index = wrapper.findAll('thead th').findIndex(cell => normalized(cell.text()) === header)
  if (index === -1) throw new Error(`no column "${header}"`)
  return wrapper.findAll('tbody tr')
    .filter(row => row.findAll('td').length > 3 && !normalized(row.find('td').text()).startsWith('Total'))
    .map(row => normalized(row.findAll('td')[index].text()))
}

function channelField(wrapper: VueWrapper, label: string): DOMWrapper<HTMLSelectElement> {
  const fieldLabel = wrapper.findAll('label').find(candidate => normalized(candidate.text()) === label)
  if (fieldLabel === undefined) throw new Error(`no label "${label}"`)
  return wrapper.find<HTMLSelectElement>(`select[id="${fieldLabel.attributes('for')}"]`)
}

function buttonNamed(scope: VueWrapper | DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

function sentBodies(method: 'POST' | 'PATCH'): Record<string, unknown>[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([, options]) => options?.method === method)
    .map(([, options]) => JSON.parse(String(options?.body)))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (_endpoint: string, options?: RequestInit) => {
    if (options?.method === 'POST') return sale('sale-recorded', 'market', 1)
    if (options?.method === 'PATCH') return MARKET_SALE
    return [MARKET_SALE, CONTRACT_SALE]
  })
})

describe('SalesTable channel', () => {
  it('shows the channel of each sale in English', async () => {
    const wrapper = await mountSales()

    expect(channelCells(wrapper, 'Channel')).toEqual(['Market order', 'Contract'])
  })

  it('shows the channel of each sale in French', async () => {
    const wrapper = await mountSales('fr')

    expect(channelCells(wrapper, 'Canal')).toEqual(['Ordre de marché', 'Contrat'])
  })

  it('records a sale on the market unless the contract channel is chosen', async () => {
    const wrapper = await mountSales()
    await buttonNamed(wrapper, 'Record Sale').trigger('click')
    await wrapper.find<HTMLInputElement>('input[placeholder="e.g. Ishtar"]').setValue('Hammerhead II')
    await wrapper.find<HTMLInputElement>('input[type="number"][min="0"]').setValue(13_650_000)

    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()
    expect(sentBodies('POST')[0].channel).toBe('market')

    await buttonNamed(wrapper, 'Record Sale').trigger('click')
    await wrapper.find<HTMLInputElement>('input[placeholder="e.g. Ishtar"]').setValue('Hammerhead II')
    await wrapper.find<HTMLInputElement>('input[type="number"][min="0"]').setValue(13_650_000)
    await channelField(wrapper, 'Channel').setValue('contract')
    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(sentBodies('POST')[1].channel).toBe('contract')
  })

  it('sends the channel of a corrected sale only when it changed', async () => {
    const wrapper = await mountSales()
    const [marketRow] = wrapper.findAll('tbody tr')
    await buttonNamed(marketRow, 'Edit').trigger('click')
    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(sentBodies('PATCH')[0]).not.toHaveProperty('channel')

    const [correctedRow] = wrapper.findAll('tbody tr')
    await buttonNamed(correctedRow, 'Edit').trigger('click')
    await channelField(wrapper, 'Channel').setValue('contract')
    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(sentBodies('PATCH')[1].channel).toBe('contract')
  })
})
