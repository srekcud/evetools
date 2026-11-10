import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import IndustryWorkshop from '@/components/industry/IndustryWorkshop.vue'
import { apiRequest } from '@/services/api'
import type { GroupProject, GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import type { IndustryAction } from '@/stores/industry/actions'
import { useSyncStore } from '@/stores/sync'
import { industryAction } from '../../industryActionFixtures'

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
const UNNAMED_PROJECT_ID = '9c1e4a72-5d08-4b36-a7f9-3e2c8b6d0a15'

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
  membersCount: 4,
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

const QUEUE: IndustryAction[] = [
  industryAction({ projectId: HECATE_PROJECT_ID, projectName: 'Hecate in progress', code: 'source_material', blocked: true, tab: 'materials', bomLineId: 'line-tritanium', typeId: 34, typeName: 'Tritanium', quantity: 147 }),
  industryAction({ projectId: SABRE_PROJECT_ID, projectName: 'Sabre selling', code: 'record_sale', tab: 'sales', quantity: 3 }),
  industryAction({ projectId: WIDOW_PROJECT_ID, projectName: 'Widow draft', code: 'start_project', targetStatus: 'in_progress' }),
  industryAction({ projectId: UNNAMED_PROJECT_ID, projectName: null, code: 'launch_job', tab: 'materials', bomLineId: 'line-raven', typeId: 638, typeName: 'Raven', quantity: null }),
]

const JOB_PRODUCTS_BY_END_HOUR = ['Moa', 'Nyx', 'Orca', 'Retriever', 'Cormorant', 'Gila', 'Hecate', 'Ishtar', 'Raven', 'Sabre']

function timelineJob(index: number, productTypeName: string) {
  return {
    jobId: 612_335_000 + index,
    characterName: `Pilot ${productTypeName}`,
    productTypeId: 1000 + index,
    productTypeName,
    activityType: 'manufacturing',
    runs: index + 1,
    timeLeftSeconds: (index + 1) * 3600,
    endDate: `2026-11-08T${String(index + 1).padStart(2, '0')}:00:00+00:00`,
  }
}

interface Answers {
  actions?: IndustryAction[] | Error
  timeline?: ReturnType<typeof timelineJob>[]
  projects?: GroupProject[]
  jobsSyncedAt?: string | null
}

function answerWith({ actions = QUEUE, timeline = [], projects = GROUP_PROJECTS, jobsSyncedAt = null }: Answers): void {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === ACTIONS_ENDPOINT) {
      if (actions instanceof Error) throw actions
      return actions
    }
    if (endpoint === SLOTS_ENDPOINT) return { characters: [], timeline, jobsSyncedAt }
    if (endpoint === PROJECTS_ENDPOINT) return projects
    if (endpoint === WORKSHOP_PROJECTS_ENDPOINT) return []
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

function queueRows(wrapper: VueWrapper) {
  return wrapper.get('[data-testid="workshop-action-queue"]').findAll('[data-testid="workshop-action-row"]')
}

function projectOfRow(row: { text: () => string }): string | undefined {
  return GROUP_PROJECTS.find(project => row.text().includes(project.items[0]?.typeName ?? project.name ?? ''))?.name ?? undefined
}

function projectRowNames(wrapper: VueWrapper): (string | undefined)[] {
  return testIds(wrapper, 'workshop-project-row').map(projectOfRow)
}

function selectedProjectName(wrapper: VueWrapper): string | undefined {
  const selected = testIds(wrapper, 'workshop-project-row').find(row => row.attributes('aria-selected') === 'true')
  return selected === undefined ? undefined : projectOfRow(selected)
}

function actionsRequestCount(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === ACTIONS_ENDPOINT).length
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  routerPush.mockClear()
  vi.mocked(apiRequest).mockReset()
})

describe('IndustryWorkshop action queue', () => {
  it('lists one row per entry in the order received, flags the blocked project, counts the entries and never shows an unknown quantity as zero', async () => {
    const wrapper = await mountWorkshop()
    const rows = queueRows(wrapper)

    expect(rows).toHaveLength(4)
    expect(rows.map(row => row.find('[data-testid="workshop-action-blocked"]').exists())).toEqual([true, false, false, false])
    expect(wrapper.get('[data-testid="workshop-action-count"]').text()).toMatch(/\b4\b/)
    expect(rows[0].text()).toContain('Hecate in progress')
    expect(rows[0].text()).toContain('Tritanium')
    expect(rows[0].text()).toContain('147')
    expect(rows[1].text()).toContain('Sabre selling')
    expect(rows[1].text()).toMatch(/\b3\b/)
    expect(rows[3].text()).toContain(en.industry.workshop.unnamedProject)
    expect(rows[3].text()).toContain('Raven')
    expect(rows[3].text()).not.toMatch(/(^|\D)0(\D|$)/)
  })

  it('opens the cockpit of the project on the detail tab of the next action, with no tab when the action has none', async () => {
    const wrapper = await mountWorkshop()
    const rows = queueRows(wrapper)

    await rows[0].get('[data-testid="workshop-action-button"]').trigger('click')
    await rows[2].get('[data-testid="workshop-action-button"]').trigger('click')

    expect(routerPush.mock.calls.map(([location]) => ({ name: location.name, id: location.params.id, tab: location.query?.tab }))).toEqual([
      { name: 'industry-project', id: HECATE_PROJECT_ID, tab: 'bom' },
      { name: 'industry-project', id: WIDOW_PROJECT_ID, tab: undefined },
    ])
  })

  it('shows the empty states of the queue and of the upcoming jobs when there is nothing to do and no job running', async () => {
    const wrapper = await mountWorkshop({ actions: [], timeline: [] })

    expect({
      emptyQueue: wrapper.find('[data-testid="workshop-empty-queue"]').exists(),
      rows: queueRows(wrapper).length,
      emptyJobs: wrapper.find('[data-testid="workshop-empty-jobs"]').exists(),
      jobs: testIds(wrapper, 'workshop-upcoming-job').length,
      error: wrapper.find('[data-testid="workshop-error"]').exists(),
    }).toEqual({ emptyQueue: true, rows: 0, emptyJobs: true, jobs: 0, error: false })
  })

  it('shows how long ago the ESI jobs were synced in the header of the upcoming jobs', async () => {
    const fourMinutesAgo = new Date(Date.now() - 4 * 60_000).toISOString()
    const wrapper = await mountWorkshop({ timeline: [timelineJob(0, 'Moa')], jobsSyncedAt: fourMinutesAgo })

    expect(wrapper.get('[data-testid="workshop-jobs-freshness"]').text()).toContain('4min ago')
  })

  it('says the sync date is unknown instead of a fake age when no job sync date is known', async () => {
    const wrapper = await mountWorkshop({ timeline: [timelineJob(0, 'Moa')], jobsSyncedAt: null })

    const chip = wrapper.get('[data-testid="workshop-jobs-freshness"]').text()
    expect(chip).toContain('unknown')
    expect(chip).not.toContain('just now')
    expect(chip).not.toContain('ago')
  })

  it('shows the failure instead of an empty queue when the actions cannot be loaded', async () => {
    const wrapper = await mountWorkshop({ actions: new Error('Actions service unavailable') })

    expect(wrapper.get('[data-testid="workshop-error"]').text()).toContain('Actions service unavailable')
    expect(wrapper.find('[data-testid="workshop-empty-queue"]').exists()).toBe(false)
    expect(queueRows(wrapper)).toHaveLength(0)
  })

  it('reloads the queue when the industry project event is received', async () => {
    const wrapper = await mountWorkshop()
    const requestsAfterMount = actionsRequestCount()

    useSyncStore().syncStatus['industry-project'] = {
      syncType: 'industry-project',
      status: 'notification' as never,
      progress: null,
      message: 'Sabre job delivered',
      data: { projectId: SABRE_PROJECT_ID },
      timestamp: '2026-11-07T10:00:00+00:00',
    }
    await flushPromises()

    expect([requestsAfterMount, actionsRequestCount(), queueRows(wrapper).length]).toEqual([1, 2, 4])
  })
})

describe('IndustryWorkshop skills link', () => {
  it('opens the skills page from the header of the workshop', async () => {
    const wrapper = await mountWorkshop()

    await wrapper.get('[data-testid="workshop-link-skills"]').trigger('click')

    expect(routerPush).toHaveBeenCalledExactlyOnceWith({ name: 'industry-skills' })
  })
})

describe('IndustryWorkshop upcoming jobs', () => {
  it('lists the eight jobs ending first, soonest first', async () => {
    const wrapper = await mountWorkshop({
      timeline: [...JOB_PRODUCTS_BY_END_HOUR].reverse().map((product, index) => timelineJob(JOB_PRODUCTS_BY_END_HOUR.length - 1 - index, product)),
    })

    const shownProducts = testIds(wrapper, 'workshop-upcoming-job').map(row => JOB_PRODUCTS_BY_END_HOUR.find(product => row.text().includes(product)))

    expect(shownProducts).toEqual(['Moa', 'Nyx', 'Orca', 'Retriever', 'Cormorant', 'Gila', 'Hecate', 'Ishtar'])
  })
})

describe('IndustryWorkshop projects', () => {
  it('lists the unfinished projects first, newest first, with the product, and tells solo from group with the role of the member', async () => {
    const wrapper = await mountWorkshop()
    const rows = testIds(wrapper, 'workshop-project-row')

    expect(projectRowNames(wrapper)).toEqual(['Sabre selling', 'Widow draft', 'Hecate in progress', 'Ishtar completed'])
    expect(rows[0].text()).toContain('Sabre')
    expect(rows[0].text()).toMatch(/\+\s?1/)
    expect(rows[1].text()).toContain('Widow')
    expect(rows.map(row => [row.find('[data-testid="workshop-team-solo"]').exists(), row.find('[data-testid="workshop-team-group"]').exists()])).toEqual([
      [false, true],
      [true, false],
      [false, true],
      [true, false],
    ])
    expect(rows[0].get('[data-testid="workshop-team-group"]').text()).toMatch(/\b2\b/)
    expect(rows[0].text()).toContain(en.groupIndustry.members.roles.member)
    expect(rows[2].get('[data-testid="workshop-team-group"]').text()).toMatch(/\b4\b/)
    expect(rows[2].text()).toContain(en.groupIndustry.members.roles.admin)
  })

  it('filters the projects with the solo and group counters, keeps the whole queue and moves the selection to the first visible project', async () => {
    const wrapper = await mountWorkshop()
    const counters = ['all', 'solo', 'group'].map(filter => wrapper.get(`[data-testid="workshop-filter-${filter}"]`).text())

    await wrapper.get('[data-testid="workshop-filter-group"]').trigger('click')
    const groupView = [projectRowNames(wrapper), selectedProjectName(wrapper)]
    await wrapper.get('[data-testid="workshop-filter-solo"]').trigger('click')
    const soloView = [projectRowNames(wrapper), selectedProjectName(wrapper)]

    expect(counters[0]).toMatch(/\b4\b/)
    expect(counters[1]).toMatch(/\b2\b/)
    expect(counters[2]).toMatch(/\b2\b/)
    expect(groupView).toEqual([['Sabre selling', 'Hecate in progress'], 'Sabre selling'])
    expect(soloView).toEqual([['Widow draft', 'Ishtar completed'], 'Widow draft'])
    expect(queueRows(wrapper)).toHaveLength(4)
  })

  it('opens the project when its row is clicked or entered with the keyboard', async () => {
    const wrapper = await mountWorkshop()
    const rows = testIds(wrapper, 'workshop-project-row')

    await rows[2].trigger('click')
    await rows[0].trigger('keydown', { key: 'Enter' })

    expect(routerPush.mock.calls).toEqual([
      [{ name: 'industry-project', params: { id: HECATE_PROJECT_ID } }],
      [{ name: 'industry-project', params: { id: SABRE_PROJECT_ID } }],
    ])
  })

  it('opens the project in a new tab on ctrl click, cmd click and middle click, without leaving the workshop', async () => {
    const openWindow = vi.spyOn(window, 'open').mockReturnValue(null)
    const wrapper = await mountWorkshop()
    const hecateRow = testIds(wrapper, 'workshop-project-row')[2]

    await hecateRow.trigger('click', { ctrlKey: true })
    await hecateRow.trigger('click', { metaKey: true })
    await hecateRow.trigger('auxclick', { button: 1 })

    expect(openWindow.mock.calls).toEqual([
      [`/industry/projects/${HECATE_PROJECT_ID}`, '_blank'],
      [`/industry/projects/${HECATE_PROJECT_ID}`, '_blank'],
      [`/industry/projects/${HECATE_PROJECT_ID}`, '_blank'],
    ])
    expect(routerPush).not.toHaveBeenCalled()
    openWindow.mockRestore()
  })

  it('shows in the panel the project hovered or focused', async () => {
    const wrapper = await mountWorkshop()
    const rows = testIds(wrapper, 'workshop-project-row')

    await rows[2].trigger('focus')
    const focused = selectedProjectName(wrapper)
    await rows[1].trigger('mouseenter')

    expect(focused).toBe('Hecate in progress')
    expect(selectedProjectName(wrapper)).toBe(projectOfRow(rows[1]))
    expect(routerPush).not.toHaveBeenCalled()
  })

  it('selects the first project by default and shows in the panel its status, its action or the absence of action, and a button to open its cockpit', async () => {
    const wrapper = await mountWorkshop({ actions: QUEUE.filter(entry => entry.projectId !== HECATE_PROJECT_ID) })
    const panelOfFirstProject = wrapper.get('[data-testid="workshop-project-panel"]')
    const firstProjectPanel = {
      selected: selectedProjectName(wrapper),
      text: panelOfFirstProject.text(),
      actionButton: panelOfFirstProject.find('[data-testid="workshop-action-button"]').exists(),
    }

    await testIds(wrapper, 'workshop-project-row')[2].trigger('mouseenter')
    const panelOfHecate = wrapper.get('[data-testid="workshop-project-panel"]')
    await panelOfHecate.get('[data-testid="workshop-open-cockpit"]').trigger('click')

    expect(firstProjectPanel.selected).toBe('Sabre selling')
    expect(firstProjectPanel.text).toContain('Sabre selling')
    expect(firstProjectPanel.text).toContain(en.groupIndustry.status.selling)
    expect(firstProjectPanel.actionButton).toBe(true)
    expect(selectedProjectName(wrapper)).toBe('Hecate in progress')
    expect(panelOfHecate.text()).toContain(en.groupIndustry.status.in_progress)
    expect(panelOfHecate.text()).toContain(en.industry.workshop.noPendingAction)
    expect(panelOfHecate.find('[data-testid="workshop-action-button"]').exists()).toBe(false)
    expect(routerPush).toHaveBeenCalledWith(expect.objectContaining({ name: 'industry-project', params: { id: HECATE_PROJECT_ID } }))
  })
})

describe('IndustryWorkshop production opportunities', () => {
  it('shows the what-to-produce card next to the action queue', async () => {
    const wrapper = await mountWorkshop()

    expect(wrapper.find('[data-testid="production-opportunities-card"]').exists()).toBe(true)
  })
})
