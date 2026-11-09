import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupSale } from '@/stores/group-industry/types'
import type { User } from '@/types'
import { completedProject } from './projectDiscordRecapFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const ISHTAR_ID = 12_005
const HECATE_ID = 35_683
const OWNER = { id: 'user-owner', authStatus: 'valid', mainCharacter: null, characters: [], corporationId: 1, corporationName: 'Corp' } as unknown as User

const ISHTAR_SALE = {
  id: 'sale-ishtar',
  typeId: ISHTAR_ID,
  typeName: 'Ishtar',
  quantity: 3,
  unitPrice: 1_500_000,
  totalPrice: 4_500_000,
  venue: null,
  channel: 'market',
  soldAt: '2026-11-01T10:00:00+00:00',
  recordedByCharacterName: 'Owner',
  sellerCharacterId: null,
  sellerCharacterName: null,
  linkedToWalletTransaction: false,
  createdAt: '2026-11-01T10:00:00+00:00',
} as GroupSale

function finalLine(typeId: number, requiredQuantity: number): BomItem {
  return { id: `line-${typeId}`, typeId, requiredQuantity, jobGroup: 'final', isJob: true } as BomItem
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function buttonNamed(scope: VueWrapper | DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

async function mountSales(options: { withItems: boolean; bom?: BomItem[] }): Promise<VueWrapper> {
  setActivePinia(createPinia())
  useAuthStore().user = OWNER
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = completedProject({
    status: 'selling',
    items: options.withItems
      ? [
          { typeId: ISHTAR_ID, typeName: 'Ishtar', meLevel: 0, teLevel: 0, runs: 5 },
          { typeId: HECATE_ID, typeName: 'Hecate', meLevel: 0, teLevel: 0, runs: 2 },
        ]
      : [],
  })
  projectStore.bomItems = options.bom ?? []
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (_endpoint: string, init?: RequestInit) => (init?.method === 'POST' ? ISHTAR_SALE : [ISHTAR_SALE]))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    props: { projectId: PROJECT_ID, isAdmin: true },
  })
  await flushPromises()
  return wrapper
}

function sentPostBodies(): Record<string, unknown>[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([, init]) => init?.method === 'POST')
    .map(([, init]) => JSON.parse(String(init?.body)))
}

describe('SalesTable product picker and sold line', () => {
  it('shows what was sold against what the project produces under the sales', async () => {
    const wrapper = await mountSales({ withItems: true, bom: [finalLine(ISHTAR_ID, 5), finalLine(HECATE_ID, 2)] })

    expect(normalized(wrapper.get(`[data-testid="sold-vs-produced-${ISHTAR_ID}"]`).text())).toContain('3 / 5')
    expect(normalized(wrapper.get(`[data-testid="sold-vs-produced-${HECATE_ID}"]`).text())).toContain('0 / 2')
  })

  it('says the produced quantity is unknown rather than zero while the project has no final line', async () => {
    const wrapper = await mountSales({ withItems: true })

    const line = wrapper.get(`[data-testid="sold-vs-produced-${ISHTAR_ID}"]`)
    expect(line.attributes('data-produced-unknown')).toBe('true')
    expect(normalized(line.text())).not.toContain('/ 0')
  })

  it('chooses the item among the items of the project, with what is left to sell, instead of typing its name', async () => {
    const wrapper = await mountSales({ withItems: true, bom: [finalLine(ISHTAR_ID, 5), finalLine(HECATE_ID, 2)] })
    await buttonNamed(wrapper, 'Record Sale').trigger('click')

    expect(wrapper.find('input[placeholder="e.g. Ishtar"]').exists()).toBe(false)
    const options = wrapper.get<HTMLSelectElement>('[data-testid="sale-item-select"]').findAll('option').map(option => normalized(option.text()))
    expect(options.some(option => option.includes('Ishtar') && option.includes('2'))).toBe(true)
    expect(options.some(option => option.includes('Hecate') && option.includes('2'))).toBe(true)
  })

  it('sends the type id and the name of the chosen item', async () => {
    const wrapper = await mountSales({ withItems: true, bom: [finalLine(ISHTAR_ID, 5)] })
    await buttonNamed(wrapper, 'Record Sale').trigger('click')
    await wrapper.get('[data-testid="sale-item-select"]').setValue(String(ISHTAR_ID))
    await wrapper.find<HTMLInputElement>('input[type="number"][min="0"]').setValue(1_500_000)

    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(sentPostBodies()).toEqual([{ typeId: ISHTAR_ID, typeName: 'Ishtar', quantity: 1, unitPrice: 1_500_000, venue: '', channel: 'market' }])
  })

  it('does not save a sale before an item is chosen', async () => {
    const wrapper = await mountSales({ withItems: true })
    await buttonNamed(wrapper, 'Record Sale').trigger('click')
    await wrapper.find<HTMLInputElement>('input[type="number"][min="0"]').setValue(1_500_000)

    expect(buttonNamed(wrapper, 'Save').attributes('disabled')).toBeDefined()
  })

  it('keeps the free name field for a project whose items are not known', async () => {
    const wrapper = await mountSales({ withItems: false })
    await buttonNamed(wrapper, 'Record Sale').trigger('click')

    expect(wrapper.find('input[placeholder="e.g. Ishtar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="sale-item-select"]').exists()).toBe(false)
  })
})
