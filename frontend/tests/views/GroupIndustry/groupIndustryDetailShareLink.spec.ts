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
import { present } from '../../present'

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
const SHARE_BUTTON = '[data-testid="share-link"]'
const REASON = '[data-testid="share-link-reason"]'
const SHORTCUT = '[data-testid="share-link-shortcut"]'
const PRIVATE_EDIT_CHECKBOX = '[data-testid="edit-project-private"]'

const PRIVATE_REASON = {
  en: 'A private project cannot be joined with a link. Make it public to invite people.',
  fr: 'Un projet privé ne peut pas être rejoint par lien. Rendez-le public pour inviter des personnes.',
}
const DRAFT_REASON = {
  en: 'A draft project is not open yet. Publish it to invite people.',
  fr: 'Un projet en brouillon n\'est pas encore ouvert. Publiez-le pour inviter des personnes.',
}

const FEROX_BATCH: GroupProject = {
  id: PROJECT_ID,
  name: 'Ferox batch',
  status: 'draft',
  shortLinkCode: 'ferox318',
  containerNames: [],
  ownerCharacterName: 'Brann Holt',
  ownerCorporationId: 98_000_003,
  membersCount: 2,
  contributionsCount: 0,
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
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
}

async function mountDetailOf(project: GroupProject, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchCockpit').mockResolvedValue(undefined as never)
  projectStore.currentProject = project
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        BomTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ProjectBuyOrBuild: true,
        ProjectStructures: true,
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

function sentPatchBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === PROJECT_PATH && options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

const writeText = vi.fn()

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  writeText.mockReset().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async () => ({}))
})

describe('group project detail invite link availability', () => {
  it.each([
    ['a private draft', { private: true, status: 'draft' }, PRIVATE_REASON.en],
    ['a private published project', { private: true, status: 'published' }, PRIVATE_REASON.en],
    ['a public draft', { private: false, status: 'draft' }, DRAFT_REASON.en],
  ] as const)('disables the share button of %s and gives the reason', async (_case, overrides, reason) => {
    const wrapper = await mountDetailOf({ ...FEROX_BATCH, ...overrides })

    expect(wrapper.get(SHARE_BUTTON).attributes('disabled')).toBeDefined()
    expect(wrapper.get(REASON).text()).toBe(reason)
  })

  it.each(['published', 'in_progress'] as const)('keeps the share button of a public %s project enabled, without reason', async (status) => {
    const wrapper = await mountDetailOf({ ...FEROX_BATCH, private: false, status })

    expect(wrapper.get(SHARE_BUTTON).attributes('disabled')).toBeUndefined()
    expect(wrapper.find(REASON).exists()).toBe(false)
    expect(wrapper.find(SHORTCUT).exists()).toBe(false)
  })

  it('copies the join link of a public published project and nothing for a disabled button', async () => {
    const published = await mountDetailOf({ ...FEROX_BATCH, private: false, status: 'published' })
    await published.get(SHARE_BUTTON).trigger('click')
    await flushPromises()
    expect(writeText.mock.calls).toEqual([[expect.stringContaining('ferox318')]])

    writeText.mockClear()
    const draft = await mountDetailOf(FEROX_BATCH)
    await draft.get(SHARE_BUTTON).trigger('click')
    await flushPromises()
    expect(writeText).not.toHaveBeenCalled()
  })

  it('gives the reason in French', async () => {
    const wrapper = await mountDetailOf({ ...FEROX_BATCH, private: false }, 'fr')

    expect(wrapper.get(REASON).text()).toBe(DRAFT_REASON.fr)
    const privateWrapper = await mountDetailOf(FEROX_BATCH, 'fr')
    expect(privateWrapper.get(REASON).text()).toBe(PRIVATE_REASON.fr)
  })
})

describe('group project detail invite link shortcut', () => {
  it('offers Make public on a private project, which opens the edit modal on the private checkbox', async () => {
    const wrapper = await mountDetailOf(FEROX_BATCH)

    expect(wrapper.get(SHORTCUT).text()).toBe('Make public')
    await wrapper.get(SHORTCUT).trigger('click')
    await flushPromises()

    expect((wrapper.get(PRIVATE_EDIT_CHECKBOX).element as HTMLInputElement).checked).toBe(true)
  })

  it('offers Publish on a public draft, which requests the published status', async () => {
    const wrapper = await mountDetailOf({ ...FEROX_BATCH, private: false })

    expect(wrapper.get(SHORTCUT).text()).toBe('Publish')
    await wrapper.get(SHORTCUT).trigger('click')
    await flushPromises()

    expect(sentPatchBodies()).toEqual([{ status: 'published' }])
  })

  it('shows a plain member the reason without any shortcut', async () => {
    const wrapper = await mountDetailOf({ ...FEROX_BATCH, myRole: 'member' })

    expect(present(wrapper.find(REASON)).text()).toBe(PRIVATE_REASON.en)
    expect(wrapper.find(SHORTCUT).exists()).toBe(false)
  })
})
