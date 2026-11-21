import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import JobContainersTable from '@/components/group-industry/JobContainersTable.vue'
import ShareLinkCard from '@/components/group-industry/ShareLinkCard.vue'
import { apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupProject } from '@/stores/group-industry/types'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'project-ferox-batch' }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ferox-batch'
const HEADER_SHARE_BUTTON = '[data-testid="share-link"]'
const COPY_JOIN_LINK_LABEL = 'Copy Join Link'

const FEROX_BATCH: GroupProject = {
  id: PROJECT_ID,
  name: 'Ferox batch',
  status: 'published',
  shortLinkCode: 'ferox318',
  containerNames: [],
  ownerCharacterName: 'Brann Holt',
  ownerCorporationId: 98_000_003,
  membersCount: 3,
  contributionsCount: 1,
  items: [{ typeId: 16_227, typeName: 'Ferox', meLevel: 6, teLevel: 12, runs: 8 }],
  totalBomValue: 215_000_000,
  fulfillmentPercent: 0,
  approvedContributionValue: 0,
  brokerFeePercent: 3.1,
  salesTaxPercent: 3.6,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-26T09:15:00+00:00',
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

async function mountDetailOnTab(tab: string | null): Promise<VueWrapper> {
  route.query = tab === null ? {} : { tab }
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchCockpit').mockResolvedValue(undefined as never)
  vi.spyOn(useGroupDistributionStore(), 'fetchMembers').mockResolvedValue(undefined as never)
  projectStore.currentProject = FEROX_BATCH
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        BomTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ProjectBuyOrBuild: true,
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

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async () => ({}))
})

describe('group project detail shows the invite link once', () => {
  it('keeps the share card as the only invite control of the Team tab', async () => {
    const wrapper = await mountDetailOnTab('members')

    expect(wrapper.findComponent(ShareLinkCard).exists()).toBe(true)
    expect(wrapper.find(HEADER_SHARE_BUTTON).exists()).toBe(false)
    expect(wrapper.text()).not.toContain(COPY_JOIN_LINK_LABEL)
  })

  it('keeps the header invite button on the other tabs', async () => {
    const wrapper = await mountDetailOnTab(null)

    expect(wrapper.find(HEADER_SHARE_BUTTON).exists()).toBe(true)
    expect(wrapper.findComponent(ShareLinkCard).exists()).toBe(false)
  })
})

describe('group project detail shows the job locations in the chain only', () => {
  it('does not repeat the job locations table in the Contributions tab', async () => {
    const wrapper = await mountDetailOnTab('contributions')

    expect(wrapper.findComponent(JobContainersTable).exists()).toBe(false)
  })
})
