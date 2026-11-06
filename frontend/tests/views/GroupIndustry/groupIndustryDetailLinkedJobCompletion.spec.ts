import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useSyncStore } from '@/stores/sync'
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

const FEROX_BATCH: GroupProject = {
  id: PROJECT_ID,
  name: 'Ferox batch',
  status: 'draft',
  shortLinkCode: 'ferox318',
  containerName: null,
  ownerCharacterName: 'Brann Holt',
  ownerCorporationId: 98_000_003,
  membersCount: 1,
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
  private: true,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  minimumOutsideSaving: 5,
}

let detailFetch: ReturnType<typeof vi.spyOn>
let bomFetch: ReturnType<typeof vi.spyOn>

async function mountDetailOf(project: GroupProject): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  detailFetch = vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  bomFetch = vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchCockpit').mockResolvedValue(undefined as never)
  projectStore.currentProject = project
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

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async () => ({}))
})

function linkedJobProgress(projectId: string) {
  return {
    syncType: 'industry-project',
    status: 'notification' as never,
    progress: null,
    message: 'Ferox job delivered',
    data: { projectId },
    timestamp: '2026-11-06T10:00:00+00:00',
  }
}

describe('group project detail linked job completion', () => {
  it('reloads the project and its BOM when a linked job of this project completes', async () => {
    await mountDetailOf(FEROX_BATCH)
    detailFetch.mockClear()
    bomFetch.mockClear()

    useSyncStore().syncStatus['industry-project'] = linkedJobProgress(PROJECT_ID)
    await flushPromises()

    expect(detailFetch).toHaveBeenCalledWith(PROJECT_ID)
    expect(bomFetch).toHaveBeenCalledWith(PROJECT_ID)
  })

  it('does not reload when the completed job belongs to another project', async () => {
    await mountDetailOf(FEROX_BATCH)
    detailFetch.mockClear()
    bomFetch.mockClear()

    useSyncStore().syncStatus['industry-project'] = linkedJobProgress('project-other-batch')
    await flushPromises()

    expect(detailFetch).not.toHaveBeenCalled()
    expect(bomFetch).not.toHaveBeenCalled()
  })
})
