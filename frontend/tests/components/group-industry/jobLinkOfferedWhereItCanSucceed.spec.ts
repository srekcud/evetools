import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { ApiError, apiRequest } from '@/services/api'
import type { AvailableJob, BomItem } from '@/stores/group-industry/types'
import { present } from '../../present'
import { mountProductionJobRows, rowOfLine } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const AVAILABLE_JOBS_URL = `/industry/projects/${PROJECT_ID}/available-jobs`
const OPEN_LINK_SCREEN = '[data-testid="link-job-open"]'
const AVAILABLE_JOB = '[data-testid="available-job"]'
const AVAILABLE_JOB_LINK = '[data-testid="available-job-link"]'
const AVAILABLE_JOB_ELSEWHERE = '[data-testid="available-job-linked-elsewhere"]'
const JOB_LINK_ERROR = '[data-testid="job-link-error"]'
const ISHTAR_LINE_ID = 'job-ishtar'
const VEXOR_LINE_ID = 'job-vexor'

function jobLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: ISHTAR_LINE_ID,
    typeId: 12005,
    typeName: 'Ishtar',
    requiredQuantity: 10,
    fulfilledQuantity: 0,
    remainingQuantity: 10,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'final',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 10,
    isFulfilled: false,
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    jobMatches: [],
    ...overrides,
  }
}

function availableJob(overrides: Partial<AvailableJob>): AvailableJob {
  return {
    esiJobId: 9001,
    blueprintTypeId: 12006,
    productTypeId: 12005,
    productTypeName: 'Ishtar',
    runs: 4,
    cost: 910_000,
    status: 'active',
    startDate: '2026-11-06T06:00:00+00:00',
    endDate: '2026-11-07T06:00:00+00:00',
    characterName: 'Cyra Lune',
    linkedToBomLineId: null,
    linkedToBomLineName: null,
    matchId: null,
    linkedRuns: 0,
    remainingRuns: 4,
    linkableBomLineIds: [ISHTAR_LINE_ID],
    linkedToAnotherProject: false,
    ...overrides,
  }
}

const MANUFACTURING_LINE = jobLine({ id: ISHTAR_LINE_ID })
const REACTION_LINE = jobLine({ id: 'job-fullerene', typeId: 30375, typeName: 'Fullerene Fibers', jobGroup: 'component', activityType: 'reaction' })
const COPY_LINE = jobLine({ id: 'job-ishtar-copy', typeId: 12006, typeName: 'Ishtar Blueprint', jobGroup: 'blueprint', activityType: 'copy', meLevel: null, teLevel: null })
const INVENTION_LINE = jobLine({ id: 'job-ishtar-invention', typeId: 12006, typeName: 'Ishtar Blueprint', jobGroup: 'blueprint', activityType: 'invention', meLevel: null, teLevel: null })

const JOB_OF_THE_LINE = availableJob({ esiJobId: 9001, characterName: 'Cyra Lune' })
const JOB_OF_ANOTHER_LINE = availableJob({ esiJobId: 9002, characterName: 'Dax Orlan', linkableBomLineIds: [VEXOR_LINE_ID] })
const JOB_TAKEN_BY_ANOTHER_PROJECT = availableJob({
  esiJobId: 9003,
  characterName: 'Eve Marek',
  runs: 6,
  linkedRuns: 6,
  remainingRuns: 0,
  linkedToAnotherProject: true,
})

function rowOf(wrapper: VueWrapper, characterName: string) {
  const row = wrapper.findAll(AVAILABLE_JOB).find(candidate => candidate.text().includes(characterName))
  expect(row, `available job of ${characterName}`).toBeDefined()
  return present(row)
}

async function openLinkScreen(jobs: AvailableJob[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const wrapper = await mountProductionJobRows({
    lines: [MANUFACTURING_LINE],
    projectId: PROJECT_ID,
    locale,
    answers: { [AVAILABLE_JOBS_URL]: jobs },
  })
  await wrapper.get(OPEN_LINK_SCREEN).trigger('click')
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Link job offered only where it can succeed', () => {
  it('offers the link action on manufacturing and reaction lines and on no copy or invention line', async () => {
    const lines = [MANUFACTURING_LINE, REACTION_LINE, COPY_LINE, INVENTION_LINE]
    const wrapper = await mountProductionJobRows({ lines, projectId: PROJECT_ID })

    expect(lines.map(line => rowOfLine(wrapper, line.id).find(OPEN_LINK_SCREEN).exists())).toEqual([true, true, false, false])
  })

  it('lists only the jobs whose blueprint produces the line, and marks a job taken by another project without a link action', async () => {
    const wrapper = await openLinkScreen([JOB_OF_THE_LINE, JOB_OF_ANOTHER_LINE, JOB_TAKEN_BY_ANOTHER_PROJECT])

    expect(wrapper.findAll(AVAILABLE_JOB).map(row => row.text().includes('Dax Orlan'))).toEqual([false, false])
    expect(rowOf(wrapper, 'Cyra Lune').find(AVAILABLE_JOB_ELSEWHERE).exists()).toBe(false)
    expect(rowOf(wrapper, 'Cyra Lune').find(AVAILABLE_JOB_LINK).exists()).toBe(true)
    expect(rowOf(wrapper, 'Eve Marek').get(AVAILABLE_JOB_ELSEWHERE).text()).toBe('Linked to another project')
    expect(rowOf(wrapper, 'Eve Marek').find(AVAILABLE_JOB_LINK).exists()).toBe(false)
  })

  it('says "Lié à un autre projet" in French', async () => {
    const wrapper = await openLinkScreen([JOB_TAKEN_BY_ANOTHER_PROJECT], 'fr')

    expect(rowOf(wrapper, 'Eve Marek').get(AVAILABLE_JOB_ELSEWHERE).text()).toBe('Lié à un autre projet')
  })

  it.each([
    ['Job activity does not match BOM line activity', 'This job does not run the activity of this line.'],
    ['Job blueprint does not match BOM line blueprint', 'This job does not use the blueprint that produces this line.'],
    ['ESI job 9001 has no output', 'This job produced nothing, it cannot be linked.'],
    ['ESI job 9001 is already linked to a BOM line', 'All the runs of this job are already linked to other lines.'],
    ['ESI job 9001 already has a share on this BOM line', 'This job already carries a share on this line.'],
    ['ESI job 9001 has only 3 runs left to share', 'Only 3 runs of this job are still free.'],
    ['ESI job 9001 not found', 'This job is not among the jobs you can link.'],
  ])('translates the refusal "%s" instead of showing the server message', async (serverMessage, translated) => {
    const wrapper = await openLinkScreen([JOB_OF_THE_LINE])

    vi.mocked(apiRequest).mockRejectedValue(new ApiError(serverMessage, 400))
    await rowOf(wrapper, 'Cyra Lune').get(AVAILABLE_JOB_LINK).trigger('click')
    await flushPromises()

    expect(wrapper.get(JOB_LINK_ERROR).text()).toBe(translated)
  })

  it('translates a refusal in French', async () => {
    const wrapper = await openLinkScreen([JOB_OF_THE_LINE], 'fr')

    vi.mocked(apiRequest).mockRejectedValue(new ApiError('ESI job 9001 is already linked to a BOM line', 400))
    await rowOf(wrapper, 'Cyra Lune').get(AVAILABLE_JOB_LINK).trigger('click')
    await flushPromises()

    expect(wrapper.get(JOB_LINK_ERROR).text()).toBe("Toutes les runs de ce job sont déjà liées à d'autres lignes.")
  })

  it('shows the generic message and never the server message for a refusal it does not know', async () => {
    const wrapper = await openLinkScreen([JOB_OF_THE_LINE])

    vi.mocked(apiRequest).mockRejectedValue(new ApiError('You do not have access to this project', 403))
    await rowOf(wrapper, 'Cyra Lune').get(AVAILABLE_JOB_LINK).trigger('click')
    await flushPromises()

    expect(wrapper.get(JOB_LINK_ERROR).text()).toBe('Failed to link the job')
  })
})
