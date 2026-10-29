import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive, ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import type { GroupMemberRole, GroupProject, GroupProjectStatus, GroupSale, GroupSaleSuggestion } from '@/stores/group-industry/types'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'project-sabre' }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SALES_PATH = `/group-industry/projects/${PROJECT_ID}/sales`
const SUGGESTIONS_PATH = `/group-industry/projects/${PROJECT_ID}/sale-suggestions`
const SALE_FROM_WALLET_TRANSACTION_PATH = `/group-industry/projects/${PROJECT_ID}/sales/from-wallet-transaction`
const ALREADY_LINKED = 'This transaction is already linked to a sale.'
const DELETION_REFUSED = 'Cannot delete a sale on a completed project'

const T1_SUGGESTION: GroupSaleSuggestion = {
  transactionId: '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  date: '2026-10-24T21:05:00+00:00',
  sellerCharacterId: 2_120_000_001,
  sellerCharacterName: 'O-Main',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: [],
}

const SALE_V: GroupSale = {
  id: 'sale-v',
  typeId: 0,
  typeName: 'Sabre',
  quantity: 2,
  unitPrice: 60_000_000,
  totalPrice: 120_000_000,
  venue: 'Jita 4-4',
  soldAt: '2026-10-23T19:10:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: null,
  sellerCharacterName: null,
  linkedToWalletTransaction: false,
  createdAt: '2026-10-23T19:11:00+00:00',
}

function sabreProject(status: GroupProjectStatus, myRole: GroupMemberRole): GroupProject {
  return {
    id: PROJECT_ID,
    name: 'Sabre',
    status,
    shortLinkCode: 'sabre241',
    containerName: null,
    ownerCharacterName: 'O-Main',
    ownerCorporationId: 98_000_001,
    membersCount: 3,
    items: [{ typeId: 22_456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
    totalBomValue: 480_000_000,
    fulfillmentPercent: 100,
    approvedContributionValue: 480_000_000,
    brokerFeePercent: 3,
    salesTaxPercent: 4,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-20T10:00:00+00:00',
    myRole,
    myStatus: 'accepted',
  }
}

let recording: () => Promise<unknown>
const syncEvent = ref<GroupProjectEvent | null>(null)

async function mountDistributionTabOf(project: GroupProject): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  projectStore.currentProject = project
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        BomTable: true,
        JobsTable: true,
        ContainerBanner: true,
        ContributeModal: true,
        ContributionTable: true,
        FinancialSummary: true,
        DistributionTable: true,
        MemberTable: true,
        PendingRequestsTable: true,
        ShareLinkCard: true,
        LoadingSpinner: true,
        ConfirmModal: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function occurrencesOnThePage(wrapper: VueWrapper, message: string): number {
  return normalized(wrapper.text()).split(message).length - 1
}

function recordAsSaleButtons(wrapper: VueWrapper) {
  return wrapper.findAll<HTMLButtonElement>('button').filter(button => normalized(button.text()) === 'Record as sale')
}

function suggestionReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SUGGESTIONS_PATH).length
}

async function publish(action: string): Promise<void> {
  syncEvent.value = { action, data: null, timestamp: '2026-10-29T09:00:00+00:00' }
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = { tab: 'distribution' }
  syncEvent.value = null
  recording = async () => {
    throw new ApiError(ALREADY_LINKED, 409)
  }
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === SALES_PATH) return [SALE_V]
    if (endpoint === SUGGESTIONS_PATH) return [T1_SUGGESTION]
    if (endpoint === SALE_FROM_WALLET_TRANSACTION_PATH && options?.method === 'POST') return recording()
    return {}
  })
})

describe('errors of the sales and of their suggestions on the detail view', () => {
  it('shows a refused recording from a wallet transaction once on the page', async () => {
    const wrapper = await mountDistributionTabOf(sabreProject('selling', 'owner'))

    await recordAsSaleButtons(wrapper)[0].trigger('click')
    await flushPromises()

    expect(occurrencesOnThePage(wrapper, ALREADY_LINKED)).toBe(1)
  })

  it('shows an error of the sales table once on the page', async () => {
    const wrapper = await mountDistributionTabOf(sabreProject('selling', 'owner'))

    useGroupDistributionStore().error = DELETION_REFUSED
    await nextTick()

    expect(occurrencesOnThePage(wrapper, DELETION_REFUSED)).toBe(1)
  })
})

describe('sale suggestions refreshed by the sale events', () => {
  it.each(['sale_recorded', 'sale_updated', 'sale_deleted'])('reads the suggestions again for the owner on %s', async (action) => {
    await mountDistributionTabOf(sabreProject('selling', 'owner'))
    const readsBeforeTheEvent = suggestionReads()

    await publish(action)

    expect([readsBeforeTheEvent, suggestionReads()]).toEqual([1, 2])
  })

  it('never asks a plain member for the suggestions he cannot read', async () => {
    await mountDistributionTabOf(sabreProject('selling', 'member'))

    await publish('sale_recorded')

    expect(suggestionReads()).toBe(0)
  })
})

describe('sale suggestions of a completed project', () => {
  it('shows the suggestions to the owner of a completed project with recording disabled', async () => {
    const wrapper = await mountDistributionTabOf(sabreProject('completed', 'owner'))

    expect([
      wrapper.findComponent(SaleSuggestionsPanel).exists(),
      recordAsSaleButtons(wrapper).map(button => button.element.disabled),
    ]).toEqual([true, [true]])
  })

  it('lets the owner of a selling project record a suggestion', async () => {
    const wrapper = await mountDistributionTabOf(sabreProject('selling', 'owner'))

    expect(recordAsSaleButtons(wrapper).map(button => button.element.disabled)).toEqual([false])
  })

  it('shows no suggestion to a plain member of a completed project', async () => {
    const wrapper = await mountDistributionTabOf(sabreProject('completed', 'member'))

    expect([wrapper.findComponent(SaleSuggestionsPanel).exists(), suggestionReads()]).toEqual([false, 0])
  })
})
