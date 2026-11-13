import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'
import type { WorkshopProject } from '@/stores/industry/workshopProjects'
import { workshopProject } from '../../industryWorkshopProjectFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn(), resolve: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const RUNNING_ID = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const SELLING_ID = '7e2a9d14-3c5b-4f86-a0d2-91b4c6e8f2b7'
const DRAFT_ID = '5d3c8b21-6a47-4e90-b1f2-8c9d0e7a4b63'
const COMPLETED_ID = '2f8a6c40-1b93-4d75-8e2a-7c5b9d0f1e34'

function groupProject(id: string, status: GroupProjectStatus): GroupProject {
  return {
    id,
    name: `Project ${status}`,
    status,
    shortLinkCode: `link-${id}`,
    containerNames: [],
    private: true,
    acceptsOutsideFavoriteSystem: false,
    excludesInvention: false,
    minimumOutsideSaving: 500000,
    sellStructureId: null,
    sellStructureName: null,
    notes: null,
    ownerCharacterName: 'Ilyra Castell',
    ownerCorporationId: 98000001,
    membersCount: 1,
    items: [],
    totalBomValue: null,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    brokerFeePercent: null,
    salesTaxPercent: null,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-01T10:00:00+00:00',
    myRole: 'owner',
    myStatus: 'accepted',
  }
}

const GROUP_PROJECTS = [
  groupProject(RUNNING_ID, 'in_progress'),
  groupProject(SELLING_ID, 'selling'),
  groupProject(DRAFT_ID, 'draft'),
  groupProject(COMPLETED_ID, 'completed'),
]

const RUNNING_ROW = workshopProject({ projectId: RUNNING_ID, engagedCapital: 1_500_000, profit: { amount: 900_000, status: 'provisional' } })
const SELLING_ROW = workshopProject({ projectId: SELLING_ID, engagedCapital: 750_000, profit: { amount: -300_000, status: 'provisional' } })
const DRAFT_ROW = workshopProject({ projectId: DRAFT_ID, engagedCapital: 0, profit: { amount: null, status: 'awaiting_sales' } })
const COMPLETED_ROW = workshopProject({ projectId: COMPLETED_ID, engagedCapital: 8_000_000, profit: { amount: 24_460_000, status: 'final' } })

function slotJob(jobId: number, timeLeftSeconds: number) {
  return {
    jobId,
    productTypeId: 1000 + jobId,
    productTypeName: 'Widow',
    activityType: 'manufacturing',
    runs: 2,
    progress: 40,
    timeLeftSeconds,
    facilityName: null,
    startDate: '2026-11-10T08:00:00+00:00',
    endDate: '2026-11-10T20:00:00+00:00',
    project: null,
  }
}

function slotCharacter(characterName: string, manufacturingUsed: number, manufacturingMax: number | null, jobs: ReturnType<typeof slotJob>[]) {
  return {
    characterId: 91000000 + jobs.length,
    characterName,
    isMain: false,
    slots: {
      manufacturing: { used: manufacturingUsed, max: manufacturingMax },
      reaction: { used: 0, max: 10 },
      science: { used: 0, max: 10 },
    },
    skillsUnknown: manufacturingMax === null,
    jobsStale: false,
    jobs,
    freeSlots: [],
  }
}

const ILYRA_RUNNING_JOBS = [slotJob(1, 5400), slotJob(2, 600), slotJob(3, 0)]
const CORIN_RUNNING_JOBS = [slotJob(4, 7200)]

function slotsData(overrides: { unknownSkillsCharacterCount?: number; characters?: ReturnType<typeof slotCharacter>[] } = {}) {
  return {
    globalKpis: {
      manufacturing: { used: 4, max: 16, percent: 25 },
      reaction: { used: 0, max: 20, percent: 0 },
      science: { used: 0, max: 20, percent: 0 },
    },
    characters: overrides.characters ?? [
      slotCharacter('Ilyra Castell', 3, 10, ILYRA_RUNNING_JOBS),
      slotCharacter('Corin Vale', 1, 6, CORIN_RUNNING_JOBS),
    ],
    timeline: [],
    skillsMayBeStale: false,
    unknownSkillsCharacterCount: overrides.unknownSkillsCharacterCount ?? 0,
  }
}

interface Answers {
  workshop?: WorkshopProject[] | Error
  slots?: ReturnType<typeof slotsData> | 'pending'
}

async function mountWorkshop({ workshop = [RUNNING_ROW, SELLING_ROW, DRAFT_ROW, COMPLETED_ROW], slots = slotsData() }: Answers = {}): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/industry/actions') return []
    if (endpoint === '/industry/slots') return slots === 'pending' ? new Promise(() => {}) : slots
    if (endpoint === '/industry/projects') return GROUP_PROJECTS
    if (endpoint === '/industry/workshop-projects') {
      if (workshop instanceof Error) throw workshop
      return workshop
    }
    return new Promise(() => {})
  }) as never)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustryWorkshop, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function card(wrapper: VueWrapper, name: 'jobs' | 'slots' | 'capital' | 'profit') {
  return wrapper.get(`[data-testid="workshop-kpi-${name}"]`)
}

function isUnknown(cardWrapper: ReturnType<typeof card>): boolean {
  return cardWrapper.find('[title="Unknown value"]').exists()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('IndustryWorkshop KPI cards', () => {
  it('shows the four cards in the order of the mockup', async () => {
    const wrapper = await mountWorkshop()

    const ids = wrapper.findAll('[data-testid^="workshop-kpi-"]').map(element => element.attributes('data-testid'))
    expect(ids).toStrictEqual(['workshop-kpi-jobs', 'workshop-kpi-slots', 'workshop-kpi-capital', 'workshop-kpi-profit'])
  })

  it('counts the jobs still running on every character and leaves out the jobs ready to deliver', async () => {
    const wrapper = await mountWorkshop()

    expect(card(wrapper, 'jobs').text()).toContain('3')
    expect(card(wrapper, 'jobs').text()).not.toContain('4')
  })

  it('shows the free manufacturing slots out of the maximum with the free slots of each character', async () => {
    const wrapper = await mountWorkshop()

    const text = card(wrapper, 'slots').text()
    expect(text).toContain('12')
    expect(text).toContain('/ 16')
    expect(text).toContain('Ilyra Castell 7')
    expect(text).toContain('Corin Vale 5')
  })

  it('shows the free slots of a character with unknown skills as unknown and warns that the totals are partial', async () => {
    const slots = slotsData({
      unknownSkillsCharacterCount: 1,
      characters: [slotCharacter('Ilyra Castell', 3, 10, []), slotCharacter('Dorn Achar', 0, null, [])],
    })
    const wrapper = await mountWorkshop({ slots })

    const slotsCard = card(wrapper, 'slots')
    expect(slotsCard.text()).toContain('Ilyra Castell 7')
    expect(slotsCard.text()).toContain('Dorn Achar')
    expect(slotsCard.text()).not.toContain('Dorn Achar 0')
    expect(isUnknown(slotsCard)).toBe(true)
    expect(slotsCard.text()).toContain('1 character(s) with unknown skills are excluded')
  })

  it('shows the jobs and the slots as unknown while the slots are not loaded', async () => {
    const wrapper = await mountWorkshop({ slots: 'pending' })

    expect([isUnknown(card(wrapper, 'jobs')), isUnknown(card(wrapper, 'slots'))]).toStrictEqual([true, true])
  })

  it('sums the capital engaged of the projects that are not completed', async () => {
    const wrapper = await mountWorkshop()

    expect(card(wrapper, 'capital').text()).toContain('2.25 M')
    expect(card(wrapper, 'capital').text()).not.toContain('10.25 M')
  })

  it('sums the known profit of the active projects with its sign and counts the projects without a figure', async () => {
    const wrapper = await mountWorkshop()

    const profitCard = card(wrapper, 'profit')
    expect(profitCard.text()).toContain('+600.00 K')
    expect(profitCard.text()).toContain('1 project without a value')
    expect(profitCard.html()).toContain('text-emerald-400')
  })

  it('shows a negative expected profit in red', async () => {
    const wrapper = await mountWorkshop({ workshop: [SELLING_ROW, COMPLETED_ROW] })

    const profitCard = card(wrapper, 'profit')
    expect(profitCard.text()).toContain('-300.00 K')
    expect(profitCard.html()).toContain('text-red-400')
  })

  it('shows the profit as unknown and never as zero when no active project has a known profit', async () => {
    const wrapper = await mountWorkshop({ workshop: [DRAFT_ROW, COMPLETED_ROW] })

    const profitCard = card(wrapper, 'profit')
    expect(isUnknown(profitCard)).toBe(true)
    expect(profitCard.text()).not.toContain('0.00')
    expect(profitCard.text()).toContain('1 project without a value')
  })

  it('shows the capital as unknown and never as zero when the figures cannot be loaded', async () => {
    const wrapper = await mountWorkshop({ workshop: new Error('Workshop service unavailable') })

    const capitalCard = card(wrapper, 'capital')
    expect(isUnknown(capitalCard)).toBe(true)
    expect(isUnknown(card(wrapper, 'profit'))).toBe(true)
    expect(capitalCard.text()).not.toContain('0.00')
  })
})
