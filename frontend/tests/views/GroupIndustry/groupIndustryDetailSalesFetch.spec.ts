import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupProject } from '@/stores/group-industry/types'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'project-sabre-batch' }, query: {} })

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
const SALES_PATH = `/group-industry/projects/${PROJECT_ID}/sales`

const SABRE_BATCH: GroupProject = {
  id: PROJECT_ID,
  name: 'Sabre batch',
  status: 'selling',
  shortLinkCode: 'sabre242',
  containerName: null,
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: 98_000_001,
  membersCount: 3,
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
}

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function salesRequests(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SALES_PATH).length
}

async function mountDetailOfTheSabreBatch(): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  projectStore.currentProject = SABRE_BATCH
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [i18n()],
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
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint === SALES_PATH ? [] : {}))
})

describe('sales of a group project fetched by the detail view', () => {
  it('fetches the sales once when the distribution tab is opened', async () => {
    const wrapper = await mountDetailOfTheSabreBatch()
    const distributionTab = wrapper.findAll('button').find(button => button.text() === en.groupIndustry.tabs.distribution)
    if (distributionTab === undefined) throw new Error('no distribution tab')

    await distributionTab.trigger('click')
    await flushPromises()

    expect([wrapper.findComponent(SalesTable).exists(), salesRequests()]).toEqual([true, 1])
  })

  it('fetches the sales once when the view opens on the distribution tab', async () => {
    route.query = { tab: 'distribution' }

    const wrapper = await mountDetailOfTheSabreBatch()

    expect([wrapper.findComponent(SalesTable).exists(), salesRequests()]).toEqual([true, 1])
  })

  it('lets the sales table fetch the sales of its project when it is shown', async () => {
    mount(SalesTable, {
      props: { projectId: PROJECT_ID, isAdmin: false },
      global: { plugins: [i18n()], stubs: { LoadingSpinner: true, ConfirmModal: true } },
    })
    await flushPromises()

    expect(salesRequests()).toBe(1)
  })
})
