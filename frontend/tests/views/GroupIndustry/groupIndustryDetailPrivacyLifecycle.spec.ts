import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
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
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const PRIVATE_EDIT_CHECKBOX = '[data-testid="edit-project-private"]'

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

async function mountDetailOf(project: GroupProject): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
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

function buttonLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').find(button => button.text() === label)
}

function sentPatchBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === PROJECT_PATH && options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async () => ({}))
})

describe('group project detail privacy', () => {
  it('shows a Private badge in the header of a private project and none on a shared one', async () => {
    const privateBadges = async (project: GroupProject) =>
      (await mountDetailOf(project)).findAll('span').map(span => span.text()).filter(text => text === 'Private')

    expect({
      private: await privateBadges(FEROX_BATCH),
      shared: await privateBadges({ ...FEROX_BATCH, private: false }),
    }).toEqual({ private: ['Private'], shared: [] })
  })

  it('sends the private flag in the PATCH of the edit modal when the owner unchecks it', async () => {
    const wrapper = await mountDetailOf(FEROX_BATCH)
    await wrapper.get(`button[aria-label="${en.groupIndustry.detail.edit}"]`).trigger('click')
    await flushPromises()

    expect((wrapper.get(PRIVATE_EDIT_CHECKBOX).element as HTMLInputElement).checked).toBe(true)
    await wrapper.get(PRIVATE_EDIT_CHECKBOX).setValue(false)
    await buttonLabelled(wrapper, en.common.actions.save)!.trigger('click')
    await flushPromises()

    expect(sentPatchBodies()).toEqual([expect.objectContaining({ private: false })])
  })
})

describe('group project detail leaving the draft status', () => {
  it('offers Publish and Start on a draft project and starts it with a status PATCH', async () => {
    const wrapper = await mountDetailOf(FEROX_BATCH)

    expect(buttonLabelled(wrapper, 'Publish')).toBeDefined()
    await buttonLabelled(wrapper, 'Start')!.trigger('click')
    await flushPromises()

    expect(sentPatchBodies()).toEqual([{ status: 'in_progress' }])
  })
})
