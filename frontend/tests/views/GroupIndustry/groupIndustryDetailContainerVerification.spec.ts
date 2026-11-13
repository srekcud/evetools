import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { ContainerVerification, GroupProject } from '@/stores/group-industry/types'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'project-ishtar-batch' }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const VERIFICATION_PATH = `/industry/projects/${PROJECT_ID}/container-verification`

const ISHTAR_BATCH: GroupProject = {
  id: PROJECT_ID,
  name: 'Ishtar batch',
  status: 'in_progress',
  shortLinkCode: 'ishtar206',
  containerName: 'ISHTAR-206',
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: 98_000_001,
  membersCount: 3,
  items: [{ typeId: 12_005, typeName: 'Ishtar', meLevel: 10, teLevel: 20, runs: 2 }],
  totalBomValue: 480_000_000,
  fulfillmentPercent: 50,
  approvedContributionValue: 240_000_000,
  brokerFeePercent: 3,
  salesTaxPercent: 3.6,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-19T12:00:00+00:00',
  myRole: 'member',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
}

const TRITANIUM_VERIFICATION: ContainerVerification = {
  bomItemId: 'bom-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 12_000,
  containerQuantity: 7_000,
  status: 'partial',
  unknownReason: null,
  assetsSyncedAt: null,
}

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function verificationRequests(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === VERIFICATION_PATH).length
}

async function mountDetailOf(project: GroupProject): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockImplementation(async () => {
    projectStore.currentProject = project
  })
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchCockpit').mockResolvedValue(undefined as never)
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [i18n()],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        BomTable: true,
        JobsTable: true,
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

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === VERIFICATION_PATH) return [TRITANIUM_VERIFICATION]
    return {}
  })
})

describe('container verification loaded when the detail view opens', () => {
  it('loads the verification for an accepted plain member and shows the result without a click', async () => {
    const wrapper = await mountDetailOf(ISHTAR_BATCH)

    expect([verificationRequests(), useGroupProjectStore().containerVerification, wrapper.findComponent(ContainerBanner).exists()])
      .toEqual([1, [TRITANIUM_VERIFICATION], true])
  })

  it('loads the verification for an admin as well', async () => {
    await mountDetailOf({ ...ISHTAR_BATCH, myRole: 'admin' })

    expect(verificationRequests()).toBe(1)
  })

  it('does not ask for a verification when the project has no container', async () => {
    await mountDetailOf({ ...ISHTAR_BATCH, containerName: null })

    expect(verificationRequests()).toBe(0)
  })

  it('keeps the verify-all button for owners and admins only', async () => {
    const memberView = await mountDetailOf(ISHTAR_BATCH)
    const memberCanVerify = memberView.findComponent(ContainerBanner).props('canVerify')
    memberView.unmount()

    setActivePinia(createPinia())
    const adminView = await mountDetailOf({ ...ISHTAR_BATCH, myRole: 'admin' })

    expect([memberCanVerify, adminView.findComponent(ContainerBanner).props('canVerify')]).toEqual([false, true])
  })
})
