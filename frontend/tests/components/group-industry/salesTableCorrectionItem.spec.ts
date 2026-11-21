import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupSale } from '@/stores/group-industry/types'
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

function saleOf(overrides: Partial<GroupSale>): GroupSale {
  return {
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
    ...overrides,
  }
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function buttonNamed(scope: VueWrapper | DOMWrapper<Element>, name: string): DOMWrapper<HTMLButtonElement> {
  const button = scope.findAll<HTMLButtonElement>('button').find(candidate => normalized(candidate.text()) === name)
  if (button === undefined) throw new Error(`no button "${name}"`)
  return button
}

async function mountSalesOf(sale: GroupSale): Promise<VueWrapper> {
  setActivePinia(createPinia())
  useAuthStore().user = OWNER
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = completedProject({
    status: 'selling',
    items: [
      { typeId: ISHTAR_ID, typeName: 'Ishtar', meLevel: 0, teLevel: 0, runs: 5 },
      { typeId: HECATE_ID, typeName: 'Hecate', meLevel: 0, teLevel: 0, runs: 2 },
    ],
  })
  projectStore.bomItems = []
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (_endpoint: string, init?: RequestInit) => (init?.method === 'PATCH' ? sale : [sale]))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(SalesTable, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    props: { projectId: PROJECT_ID, isAdmin: true },
  })
  await flushPromises()
  return wrapper
}

async function startCorrection(wrapper: VueWrapper): Promise<void> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.findAll('button').length > 0)
  if (row === undefined) throw new Error('no sale row')
  await buttonNamed(row, 'Edit').trigger('click')
}

function patchedBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([, init]) => init?.method === 'PATCH')
    .map(([, init]) => JSON.parse(String(init?.body)))
}

describe('SalesTable correction of the item of a sale', () => {
  it('offers the items of the project with the item of the sale selected', async () => {
    const wrapper = await mountSalesOf(saleOf({}))
    await startCorrection(wrapper)

    const select = wrapper.get<HTMLSelectElement>('[data-testid="sale-item-select"]')
    expect(select.element.value).toBe(String(ISHTAR_ID))
    expect(select.findAll('option').map(option => normalized(option.text())).some(option => option.includes('Hecate'))).toBe(true)
  })

  it('sends the type id and the name of the item chosen in the correction', async () => {
    const wrapper = await mountSalesOf(saleOf({}))
    await startCorrection(wrapper)
    await wrapper.get('[data-testid="sale-item-select"]').setValue(String(HECATE_ID))

    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(patchedBodies()).toEqual([{ typeId: HECATE_ID, typeName: 'Hecate' }])
  })

  it('sends no item when the correction keeps the item of the sale', async () => {
    const wrapper = await mountSalesOf(saleOf({}))
    await startCorrection(wrapper)
    await wrapper.get<HTMLInputElement>('input[type="number"][min="1"]').setValue(2)

    await buttonNamed(wrapper, 'Save').trigger('click')
    await flushPromises()

    expect(patchedBodies()).toEqual([{ quantity: 2 }])
  })

  it('preselects the item matching the name of a sale recorded without a type id', async () => {
    const wrapper = await mountSalesOf(saleOf({ typeId: 0 }))
    await startCorrection(wrapper)

    expect(wrapper.get<HTMLSelectElement>('[data-testid="sale-item-select"]').element.value).toBe(String(ISHTAR_ID))
  })

  it('keeps the item of a sale linked to a wallet transaction fixed', async () => {
    const wrapper = await mountSalesOf(saleOf({ linkedToWalletTransaction: true }))
    await startCorrection(wrapper)

    expect(wrapper.find('[data-testid="sale-item-select"]').exists()).toBe(false)
  })
})
