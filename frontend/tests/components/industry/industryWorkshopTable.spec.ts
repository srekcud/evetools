import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject, GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import { useIndustryStore } from '@/stores/industry'
import type { WorkshopProject } from '@/stores/industry/workshopProjects'
import { useSyncStore } from '@/stores/sync'
import { phase, workshopMember, workshopProject } from '../../industryWorkshopProjectFixtures'

const routerPush = vi.fn()
const routerResolve = vi.fn((location: { params: { id: string } }) => ({ href: `/industry/projects/${location.params.id}` }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ push: routerPush, replace: vi.fn(), resolve: routerResolve })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ACTIONS_ENDPOINT = '/industry/actions'
const SLOTS_ENDPOINT = '/industry/slots'
const PROJECTS_ENDPOINT = '/industry/projects'
const WORKSHOP_PROJECTS_ENDPOINT = '/industry/workshop-projects'

const SABRE_PROJECT_ID = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const WIDOW_PROJECT_ID = '7e2a9d14-3c5b-4f86-a0d2-91b4c6e8f2b7'
const HECATE_PROJECT_ID = '5d3c8b21-6a47-4e90-b1f2-8c9d0e7a4b63'
const ISHTAR_PROJECT_ID = '2f8a6c40-1b93-4d75-8e2a-7c5b9d0f1e34'

const ILYRA_CHARACTER_ID = 91000001
const CORIN_CHARACTER_ID = 91000002
const DORN_CHARACTER_ID = 91000004
const ELIA_CHARACTER_ID = 91000005
const FARA_CHARACTER_ID = 91000006
const GALE_CHARACTER_ID = 91000007

const SABRE_ROW = 0
const WIDOW_ROW = 1
const HECATE_ROW = 2
const ISHTAR_ROW = 3

const MAXIMUM_AVATARS = 5
const REMAINING_DURATION = '1h 30m'

function groupProject(id: string, name: string, status: GroupProjectStatus, overrides: Partial<GroupProject>): GroupProject {
  return {
    id,
    name,
    status,
    shortLinkCode: `link-${id}`,
    containerName: null,
    private: true,
    acceptsOutsideFavoriteSystem: false,
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
    myRole: 'owner' as GroupMemberRole,
    myStatus: 'accepted',
    ...overrides,
  }
}

const WIDOW_DRAFT = groupProject(WIDOW_PROJECT_ID, 'Widow draft', 'draft', {
  items: [{ typeId: 22436, typeName: 'Widow', meLevel: 10, teLevel: 20, runs: 4 }],
  createdAt: '2026-10-05T10:00:00+00:00',
})
const HECATE_IN_PROGRESS = groupProject(HECATE_PROJECT_ID, 'Hecate in progress', 'in_progress', {
  membersCount: 7,
  myRole: 'admin',
  createdAt: '2026-10-01T10:00:00+00:00',
})
const ISHTAR_COMPLETED = groupProject(ISHTAR_PROJECT_ID, 'Ishtar completed', 'completed', {
  items: [{ typeId: 12005, typeName: 'Ishtar', meLevel: 8, teLevel: 16, runs: 2 }],
  createdAt: '2026-10-20T10:00:00+00:00',
})
const SABRE_SELLING = groupProject(SABRE_PROJECT_ID, 'Sabre selling', 'selling', {
  membersCount: 2,
  myRole: 'member',
  items: [
    { typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 5 },
    { typeId: 17715, typeName: 'Gila', meLevel: 6, teLevel: 12, runs: 2 },
  ],
  createdAt: '2026-10-10T10:00:00+00:00',
})

const GROUP_PROJECTS = [WIDOW_DRAFT, HECATE_IN_PROGRESS, ISHTAR_COMPLETED, SABRE_SELLING]

const SABRE_WORKSHOP = workshopProject({
  projectId: SABRE_PROJECT_ID,
  members: [workshopMember('Ilyra Castell', ILYRA_CHARACTER_ID, 'owner'), workshopMember('Brann Holt', null, 'member')],
  corporationName: 'Hedion University',
  phases: [
    phase('prepare', 'done', 2, 2),
    phase('supply', 'done', 5, 5),
    phase('produce', 'done', 7, 7),
    phase('sell', 'current', 2, 5),
    phase('close', 'upcoming', 0, 1),
  ],
  profit: { amount: 1_200_000, status: 'provisional' },
})
const WIDOW_WORKSHOP = workshopProject({
  projectId: WIDOW_PROJECT_ID,
  members: [workshopMember('Ilyra Castell', ILYRA_CHARACTER_ID, 'owner')],
  phases: [
    phase('prepare', 'current', 0, 1),
    phase('supply', 'upcoming', 0, 4),
    phase('produce', 'upcoming', 0, 2),
    phase('sell', 'upcoming', 0, 4),
    phase('close', 'upcoming', 0, 1),
  ],
  profit: { amount: null, status: 'awaiting_sales' },
})
const HECATE_WORKSHOP = workshopProject({
  projectId: HECATE_PROJECT_ID,
  members: [
    workshopMember('Ilyra Castell', ILYRA_CHARACTER_ID, 'owner'),
    workshopMember('Corin Vale', CORIN_CHARACTER_ID, 'admin'),
    workshopMember('Brann Holt', null, 'member'),
    workshopMember('Dorn Achar', DORN_CHARACTER_ID, 'member'),
    workshopMember('Elia Varr', ELIA_CHARACTER_ID, 'member'),
    workshopMember('Fara Nyx', FARA_CHARACTER_ID, 'member'),
    workshopMember('Gale Orm', GALE_CHARACTER_ID, 'member'),
  ],
  phases: [
    phase('prepare', 'done', 1, 1),
    phase('supply', 'blocked', 0, 3, { code: 'materials_missing', count: 3 }),
    phase('produce', 'current', 1, 2),
    phase('sell', 'upcoming', 0, 4),
    phase('close', 'upcoming', 0, 1),
  ],
  profit: { amount: -300_000, status: 'provisional' },
})
const ISHTAR_WORKSHOP = workshopProject({
  projectId: ISHTAR_PROJECT_ID,
  members: [workshopMember('Ilyra Castell', ILYRA_CHARACTER_ID, 'owner')],
  phases: [
    phase('prepare', 'done', 1, 1),
    phase('supply', 'done', 3, 3),
    phase('produce', 'done', 2, 2),
    phase('sell', 'done', 2, 2),
    phase('close', 'done', 1, 1),
  ],
  profit: { amount: 24_460_000, status: 'final' },
})

const WORKSHOP_ROWS = [SABRE_WORKSHOP, WIDOW_WORKSHOP, HECATE_WORKSHOP, ISHTAR_WORKSHOP]

function timelineJob(jobId: number, productTypeName: string, endDate: string) {
  return {
    jobId,
    characterName: `Pilot ${productTypeName}`,
    productTypeId: 1000 + (jobId % 100),
    productTypeName,
    activityType: 'manufacturing',
    runs: 2,
    timeLeftSeconds: 5400,
    endDate,
  }
}

interface Answers {
  workshop?: WorkshopProject[] | Error | 'pending'
  timeline?: ReturnType<typeof timelineJob>[]
}

function answerWith({ workshop = WORKSHOP_ROWS, timeline = [] }: Answers): void {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === ACTIONS_ENDPOINT) return []
    if (endpoint === SLOTS_ENDPOINT) return { characters: [], timeline }
    if (endpoint === PROJECTS_ENDPOINT) return GROUP_PROJECTS
    if (endpoint === WORKSHOP_PROJECTS_ENDPOINT) {
      if (workshop === 'pending') return new Promise(() => {})
      if (workshop instanceof Error) throw workshop
      return workshop
    }
    return new Promise(() => {})
  }) as never)
}

async function mountWorkshop(answers: Answers = {}): Promise<VueWrapper> {
  answerWith(answers)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustryWorkshop, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function testIds(wrapper: VueWrapper, testId: string) {
  return wrapper.findAll(`[data-testid="${testId}"]`)
}

function projectRows(wrapper: VueWrapper) {
  return testIds(wrapper, 'workshop-project-row')
}

function inEveryRow(wrapper: VueWrapper, testId: string): boolean[] {
  return projectRows(wrapper).map(row => row.find(`[data-testid="${testId}"]`).exists())
}

function twoDigits(value: number): string {
  return String(value).padStart(2, '0')
}

function localTimeOf(isoDate: string): string {
  const date = new Date(isoDate)
  return `${twoDigits(date.getHours())}:${twoDigits(date.getMinutes())}`
}

function workshopProjectsRequestCount(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === WORKSHOP_PROJECTS_ENDPOINT).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  routerPush.mockClear()
  vi.mocked(apiRequest).mockReset()
})

describe('IndustryWorkshop project team', () => {
  it('shows a portrait avatar per member named by its title, the initial when the character is unknown, and at most five avatars then a +N counter', async () => {
    const wrapper = await mountWorkshop()
    const rows = projectRows(wrapper)
    const sabreAvatars = rows[SABRE_ROW].findAll('[data-testid="workshop-member-avatar"]')
    const hecateAvatars = rows[HECATE_ROW].findAll('[data-testid="workshop-member-avatar"]')

    expect({
      sabreTitles: sabreAvatars.map(avatar => avatar.attributes('title')),
      ilyraPortrait: sabreAvatars[0].get('img').attributes('src'),
      brannHasPortrait: sabreAvatars[1].find('img').exists(),
      brannInitial: sabreAvatars[1].text(),
      sabreOverflow: rows[SABRE_ROW].find('[data-testid="workshop-member-overflow"]').exists(),
      hecateAvatarCount: hecateAvatars.length,
      hecateLastTitle: hecateAvatars[MAXIMUM_AVATARS - 1].attributes('title'),
      hecateOverflow: rows[HECATE_ROW].get('[data-testid="workshop-member-overflow"]').text(),
    }).toEqual({
      sabreTitles: ['Ilyra Castell', 'Brann Holt'],
      ilyraPortrait: expect.stringContaining(`/characters/${ILYRA_CHARACTER_ID}/portrait`),
      brannHasPortrait: false,
      brannInitial: 'B',
      sabreOverflow: false,
      hecateAvatarCount: MAXIMUM_AVATARS,
      hecateLastTitle: 'Elia Varr',
      hecateOverflow: '+2',
    })
  })

  it('lists in the panel every member of the hovered project with the role, including those beyond the avatars', async () => {
    const wrapper = await mountWorkshop()

    await projectRows(wrapper)[HECATE_ROW].trigger('mouseenter')
    const panelText = wrapper.get('[data-testid="workshop-project-panel"]').text()

    for (const memberName of ['Ilyra Castell', 'Corin Vale', 'Brann Holt', 'Dorn Achar', 'Elia Varr', 'Fara Nyx', 'Gale Orm']) {
      expect(panelText).toContain(memberName)
    }
    expect(panelText).toContain(en.groupIndustry.members.roles.owner)
    expect(panelText).toContain(en.groupIndustry.members.roles.admin)
    expect(panelText).toContain(en.groupIndustry.members.roles.member)
  })
})

describe('IndustryWorkshop project lifecycle', () => {
  it('shows the current phase with its done over total counter, and the first phase of the project when it is blocked', async () => {
    const wrapper = await mountWorkshop()
    const rows = projectRows(wrapper)
    const phaseOf = (index: number) => [
      rows[index].get('[data-testid="workshop-phase-label"]').text(),
      rows[index].get('[data-testid="workshop-phase-counter"]').text(),
    ]

    expect({
      sabre: phaseOf(SABRE_ROW),
      widow: phaseOf(WIDOW_ROW),
      hecate: phaseOf(HECATE_ROW),
    }).toEqual({
      sabre: [en.groupIndustry.cockpit.phases.sell, '2 / 5'],
      widow: [en.groupIndustry.cockpit.phases.prepare, '0 / 1'],
      hecate: [en.groupIndustry.cockpit.phases.supply, '0 / 3'],
    })
  })

  it('colors in amber the segment of a blocked phase and gives its reason, even when no phase is current', async () => {
    const jobsBlockedWithoutCurrent = workshopProject({
      projectId: WIDOW_PROJECT_ID,
      members: WIDOW_WORKSHOP.members,
      phases: [
        phase('prepare', 'done', 1, 1),
        phase('supply', 'done', 4, 4),
        phase('produce', 'blocked', 0, 2, { code: 'jobs_unfinished', count: 2 }),
        phase('sell', 'upcoming', 0, 4),
        phase('close', 'upcoming', 0, 1),
      ],
      profit: WIDOW_WORKSHOP.profit,
    })
    const wrapper = await mountWorkshop()
    const hecateRow = projectRows(wrapper)[HECATE_ROW]
    const wrapperWithoutCurrentPhase = await mountWorkshop({ workshop: [SABRE_WORKSHOP, jobsBlockedWithoutCurrent, HECATE_WORKSHOP, ISHTAR_WORKSHOP] })
    const widowRow = projectRows(wrapperWithoutCurrentPhase)[WIDOW_ROW]

    expect({
      segmentsPerRow: projectRows(wrapper).map(row => row.findAll('[data-testid="workshop-phase-blocked-segment"]').length),
      segmentClass: hecateRow.get('[data-testid="workshop-phase-blocked-segment"]').classes().join(' '),
      hecateReason: hecateRow.get('[data-testid="workshop-phase-blocked-reason"]').text(),
      reasonOnOtherRows: [SABRE_ROW, WIDOW_ROW, ISHTAR_ROW].map(index => projectRows(wrapper)[index].find('[data-testid="workshop-phase-blocked-reason"]').exists()),
      widowPhase: widowRow.get('[data-testid="workshop-phase-label"]').text(),
      widowReason: widowRow.get('[data-testid="workshop-phase-blocked-reason"]').text(),
    }).toEqual({
      segmentsPerRow: [0, 0, 1, 0],
      segmentClass: expect.stringContaining('amber'),
      hecateReason: en.groupIndustry.cockpit.blockedReasons.materials_missing.replace('{count}', '3'),
      reasonOnOtherRows: [false, false, false],
      widowPhase: en.groupIndustry.cockpit.phases.produce,
      widowReason: en.groupIndustry.cockpit.blockedReasons.jobs_unfinished.replace('{count}', '2'),
    })
  })

  it('shows a completed project as completed, without any blocked reason', async () => {
    const wrapper = await mountWorkshop()
    const ishtarRow = projectRows(wrapper)[ISHTAR_ROW]

    expect(ishtarRow.get('[data-testid="workshop-phase-label"]').text()).toBe(en.groupIndustry.status.completed)
    expect(ishtarRow.find('[data-testid="workshop-phase-blocked-segment"]').exists()).toBe(false)
    expect(ishtarRow.find('[data-testid="workshop-phase-blocked-reason"]').exists()).toBe(false)
  })
})

describe('IndustryWorkshop project profit', () => {
  it('shows the signed amount in green or red with a caption per status, and never an amount for a project without sale', async () => {
    const wrapper = await mountWorkshop()
    const rows = projectRows(wrapper)
    const amountOf = (index: number) => rows[index].find('[data-testid="workshop-profit"]')
    const captionOf = (index: number) => rows[index].get('[data-testid="workshop-profit-caption"]').text()
    const captions = [SABRE_ROW, WIDOW_ROW, ISHTAR_ROW].map(captionOf)

    expect({
      sabre: amountOf(SABRE_ROW).text(),
      sabreIsGreen: amountOf(SABRE_ROW).classes().join(' ').includes('emerald'),
      hecate: amountOf(HECATE_ROW).text(),
      hecateIsRed: amountOf(HECATE_ROW).classes().join(' ').includes('red'),
      ishtar: amountOf(ISHTAR_ROW).text(),
      ishtarIsGreen: amountOf(ISHTAR_ROW).classes().join(' ').includes('emerald'),
      widowHasAmount: amountOf(WIDOW_ROW).exists(),
      widowIsUnknown: rows[WIDOW_ROW].find('[data-testid="workshop-profit-unknown"]').exists(),
      knownAmountsHideTheUnknownValue: [SABRE_ROW, HECATE_ROW, ISHTAR_ROW].map(index => rows[index].find('[data-testid="workshop-profit-unknown"]').exists()),
      captionsAreDistinct: new Set(captions).size === captions.length && captions.every(caption => caption !== ''),
    }).toEqual({
      sabre: '+1.20 M',
      sabreIsGreen: true,
      hecate: '-300.00 K',
      hecateIsRed: true,
      ishtar: '+24.46 M',
      ishtarIsGreen: true,
      widowHasAmount: false,
      widowIsUnknown: true,
      knownAmountsHideTheUnknownValue: [false, false, false],
      captionsAreDistinct: true,
    })
  })

  it('shows the unknown value, never zero, for an unknown profit and for a project missing from the answer', async () => {
    const unknownProfit = workshopProject({ projectId: SABRE_PROJECT_ID, members: SABRE_WORKSHOP.members, phases: SABRE_WORKSHOP.phases, profit: { amount: null, status: 'unknown' } })
    const wrapper = await mountWorkshop({ workshop: [unknownProfit] })

    expect({
      unknownValue: inEveryRow(wrapper, 'workshop-profit-unknown'),
      amount: inEveryRow(wrapper, 'workshop-profit'),
      avatars: testIds(wrapper, 'workshop-member-avatar').length,
      sabrePhase: projectRows(wrapper)[SABRE_ROW].get('[data-testid="workshop-phase-label"]').text(),
      widowPhase: projectRows(wrapper)[WIDOW_ROW].find('[data-testid="workshop-phase-label"]').exists(),
    }).toEqual({
      unknownValue: [true, true, true, true],
      amount: [false, false, false, false],
      avatars: 2,
      sabrePhase: en.groupIndustry.cockpit.phases.sell,
      widowPhase: false,
    })
  })

  it('shows the failure and the unknown value in every row when the workshop projects cannot be loaded', async () => {
    const wrapper = await mountWorkshop({ workshop: new Error('Workshop projects service unavailable') })

    expect({
      banner: wrapper.get('[data-testid="workshop-rows-error"]').text(),
      unknownValue: inEveryRow(wrapper, 'workshop-profit-unknown'),
      amount: inEveryRow(wrapper, 'workshop-profit'),
      avatars: testIds(wrapper, 'workshop-member-avatar').length,
    }).toEqual({
      banner: expect.stringContaining('Workshop projects service unavailable'),
      unknownValue: [true, true, true, true],
      amount: [false, false, false, false],
      avatars: 0,
    })
  })

  it('shows the unknown value without error while the workshop projects are loading', async () => {
    const wrapper = await mountWorkshop({ workshop: 'pending' })

    expect({
      unknownValue: inEveryRow(wrapper, 'workshop-profit-unknown'),
      amount: inEveryRow(wrapper, 'workshop-profit'),
      banner: wrapper.find('[data-testid="workshop-rows-error"]').exists(),
    }).toEqual({
      unknownValue: [true, true, true, true],
      amount: [false, false, false, false],
      banner: false,
    })
  })

  it('reloads the workshop projects when the industry project event is received', async () => {
    const wrapper = await mountWorkshop()
    const requestsAfterMount = workshopProjectsRequestCount()

    useSyncStore().syncStatus['industry-project'] = {
      syncType: 'industry-project',
      status: 'notification' as never,
      progress: null,
      message: 'Sabre job delivered',
      data: { projectId: SABRE_PROJECT_ID },
      timestamp: '2026-11-07T10:00:00+00:00',
    }
    await flushPromises()

    expect([requestsAfterMount, workshopProjectsRequestCount(), testIds(wrapper, 'workshop-profit').length]).toEqual([1, 2, 3])
  })
})

describe('IndustryWorkshop product sub-line', () => {
  it('shows ME, TE of the first product and the number of participants, or solo', async () => {
    const wrapper = await mountWorkshop()
    const rows = projectRows(wrapper)
    const sublineOf = (index: number) => rows[index].get('[data-testid="workshop-product-subline"]').text()

    expect(sublineOf(SABRE_ROW)).toMatch(/ME\s*10/)
    expect(sublineOf(SABRE_ROW)).toMatch(/TE\s*20/)
    expect(sublineOf(SABRE_ROW)).toMatch(/\b2\b/)
    expect(sublineOf(WIDOW_ROW)).toMatch(/ME\s*10/)
    expect(sublineOf(WIDOW_ROW)).toMatch(/TE\s*20/)
    expect(sublineOf(WIDOW_ROW).toLowerCase()).toContain(en.industry.workshop.team.solo.toLowerCase())
    expect(sublineOf(WIDOW_ROW)).not.toMatch(/\b1\b/)
    expect(sublineOf(ISHTAR_ROW)).toMatch(/ME\s*8/)
    expect(sublineOf(ISHTAR_ROW)).toMatch(/TE\s*16/)
  })

  it('ends with the corporation name of the project when the workshop row carries one', async () => {
    const wrapper = await mountWorkshop()
    const sublineOf = (index: number) => projectRows(wrapper)[index].get('[data-testid="workshop-product-subline"]').text()

    expect(sublineOf(SABRE_ROW)).toMatch(/ME\s*10\s*·\s*TE\s*20\s*·\s*\S.*·\s*Hedion University$/)
  })

  it('shows no corporation segment when the workshop row has no corporation name', async () => {
    const wrapper = await mountWorkshop()
    const sublineOf = (index: number) => projectRows(wrapper)[index].get('[data-testid="workshop-product-subline"]').text()

    expect(sublineOf(WIDOW_ROW)).toMatch(/TE\s*20\s*·\s*\S+$/)
    expect(sublineOf(WIDOW_ROW).split('·')).toHaveLength(3)
  })
})

describe('IndustryWorkshop header links', () => {
  it('opens the blueprints, the industry revenue and the project creation from the header, and no longer carries the skills link in the action queue', async () => {
    const wrapper = await mountWorkshop()

    await wrapper.get('[data-testid="workshop-link-blueprints"]').trigger('click')
    await wrapper.get('[data-testid="workshop-link-revenue"]').trigger('click')
    await wrapper.get('[data-testid="workshop-link-new-project"]').trigger('click')

    expect(routerPush.mock.calls).toEqual([
      [{ path: '/industry/production', query: { tab: 'blueprints' } }],
      [{ name: 'pve-revenue', query: { tab: 'industry' } }],
    ])
    expect(useIndustryStore().navigationIntent).toEqual({ target: 'projects', openCreateModal: true })
    expect(wrapper.find('[data-testid="workshop-link-skills"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="workshop-action-queue"]').find('[data-testid="workshop-link-skills"]').exists()).toBe(false)
  })
})

describe('IndustryWorkshop upcoming jobs end time', () => {
  it('shows the local end time of each upcoming job before the remaining duration', async () => {
    const moaEnd = '2026-11-08T07:45:00+00:00'
    const nyxEnd = '2026-11-08T13:20:00+00:00'
    const wrapper = await mountWorkshop({
      timeline: [timelineJob(612_335_001, 'Moa', moaEnd), timelineJob(612_335_002, 'Nyx', nyxEnd)],
    })
    const jobs = testIds(wrapper, 'workshop-upcoming-job')
    const endsBeforeDuration = (job: (typeof jobs)[number], endDate: string) => job.text().indexOf(localTimeOf(endDate)) < job.text().indexOf(REMAINING_DURATION)

    expect(jobs.map(job => job.get('[data-testid="workshop-job-end-time"]').text())).toEqual([localTimeOf(moaEnd), localTimeOf(nyxEnd)])
    expect([endsBeforeDuration(jobs[0], moaEnd), endsBeforeDuration(jobs[1], nyxEnd)]).toEqual([true, true])
  })
})
