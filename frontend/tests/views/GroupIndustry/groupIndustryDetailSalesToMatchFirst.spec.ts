import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { ContractSaleCandidate, GroupProject, GroupSaleSuggestion } from '@/stores/group-industry/types'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'project-sabre-batch' }, query: { tab: 'sales' } })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre-batch'
const SALES_PATH = `/industry/projects/${PROJECT_ID}/sales`
const SALE_SUGGESTIONS_PATH = `/industry/projects/${PROJECT_ID}/sale-suggestions`
const CONTRACT_SUGGESTIONS_PATH = `/industry/projects/${PROJECT_ID}/contract-suggestions`

const SABRE_BATCH: GroupProject = {
  id: PROJECT_ID,
  name: 'Sabre batch',
  status: 'selling',
  shortLinkCode: 'sabre242',
  containerNames: [],
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: 98_000_001,
  membersCount: 3,
  contributionsCount: 0,
  items: [{ typeId: 22_456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
  totalBomValue: 480_000_000,
  fulfillmentPercent: 100,
  approvedContributionValue: 480_000_000,
  brokerFeePercent: 3,
  salesTaxPercent: 3.6,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-19T12:00:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
}

const SABRE_TRANSACTION: GroupSaleSuggestion = {
  transactionId: 'transaction-sabre-1',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 4,
  unitPrice: 48_000_000,
  totalPrice: 192_000_000,
  date: '2026-10-22T09:30:00+00:00',
  sellerCharacterId: 90_000_001,
  sellerCharacterName: 'Aria Solberg',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: [],
}

const SABRE_CONTRACT: ContractSaleCandidate = {
  contractId: 1_000_042,
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 2,
  price: 96_000_000,
  unitPrice: 48_000_000,
  completedAt: '2026-10-23T10:00:00+00:00',
  sellerCharacterId: 90_000_001,
  sellerCharacterName: 'Aria Solberg',
  contractFees: 1_000_000,
}

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function answerWith(transactions: GroupSaleSuggestion[], contracts: ContractSaleCandidate[]): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SALES_PATH) return []
    if (endpoint === SALE_SUGGESTIONS_PATH) return { id: PROJECT_ID, suggestions: transactions, walletReadings: [] }
    if (endpoint === CONTRACT_SUGGESTIONS_PATH) return { projectId: PROJECT_ID, candidates: contracts, unreadableCharacters: [] }
    return {}
  })
}

async function mountSalesTabOfTheSabreBatch(): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchCockpit').mockResolvedValue(undefined as never)
  projectStore.currentProject = SABRE_BATCH
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [i18n()],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        BomTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
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

function headingsOf(wrapper: VueWrapper): string[] {
  return wrapper.findAll('h2').map(heading => heading.text())
}

function position(headings: string[], title: string): number {
  return headings.indexOf(title)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = { tab: 'sales' }
  vi.mocked(apiRequest).mockReset()
})

describe('sections of the Sales tab', () => {
  it('shows the wallet transactions to link before the sales when there are some', async () => {
    answerWith([SABRE_TRANSACTION], [])

    const headings = headingsOf(await mountSalesTabOfTheSabreBatch())

    expect(position(headings, en.groupIndustry.saleSuggestions.title)).toBeGreaterThanOrEqual(0)
    expect(position(headings, en.groupIndustry.saleSuggestions.title)).toBeLessThan(position(headings, 'Sales'))
  })

  it('shows the contracts to link before the sales when there are some', async () => {
    answerWith([], [SABRE_CONTRACT])

    const headings = headingsOf(await mountSalesTabOfTheSabreBatch())

    expect(position(headings, en.groupIndustry.contractSales.title)).toBeGreaterThanOrEqual(0)
    expect(position(headings, en.groupIndustry.contractSales.title)).toBeLessThan(position(headings, 'Sales'))
  })

  it('puts the contracts to link ahead of an empty list of transactions', async () => {
    answerWith([], [SABRE_CONTRACT])

    const headings = headingsOf(await mountSalesTabOfTheSabreBatch())

    expect(position(headings, en.groupIndustry.contractSales.title)).toBeLessThan(position(headings, en.groupIndustry.saleSuggestions.title))
  })

  it('keeps the sales first when nothing is waiting to be linked', async () => {
    answerWith([], [])

    const headings = headingsOf(await mountSalesTabOfTheSabreBatch())

    expect(position(headings, 'Sales')).toBeGreaterThanOrEqual(0)
    expect(position(headings, 'Sales')).toBeLessThan(position(headings, en.groupIndustry.saleSuggestions.title))
    expect(position(headings, en.groupIndustry.saleSuggestions.title)).toBeLessThan(position(headings, en.groupIndustry.contractSales.title))
  })
})
