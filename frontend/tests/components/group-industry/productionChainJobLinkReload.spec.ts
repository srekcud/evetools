import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import type { BomItem } from '@/stores/group-industry/types'
import { present } from '../../present'
import { callsTo, mountProductionJobRows } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const CHAIN_URL = `/industry/projects/${PROJECT_ID}/chain`
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const AVAILABLE_JOBS_URL = `/industry/projects/${PROJECT_ID}/available-jobs`
const ISHTAR_LINE_ID = 'job-ishtar'
const LINK_JOB_URL = `/industry/projects/${PROJECT_ID}/bom/${ISHTAR_LINE_ID}/link-job`
const UNLINK_ARIA_URL = `/industry/projects/${PROJECT_ID}/job-matches/match-aria`
const OPEN_LINK_SCREEN = '[data-testid="link-job-open"]'
const AVAILABLE_JOB = '[data-testid="available-job"]'
const AVAILABLE_JOB_LINK = '[data-testid="available-job-link"]'
const JOB_MATCH = '[data-testid="job-match"]'
const JOB_MATCH_UNLINK = '[data-testid="job-match-unlink"]'
const LINK_REFUSED = 'Job already linked to another line'
const UNLINK_REFUSED = 'Job match belongs to a delivered job'

const ISHTAR_JOB: BomItem = {
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
  matchedRuns: 3,
  purchases: [],
  purchasedQuantity: 0,
  reservations: [],
  pendingContributions: [],
  reservedQuantity: 0,
  freeQuantity: 0,
  jobMatches: [
    {
      id: 'match-aria',
      bomLineId: ISHTAR_LINE_ID,
      esiJobId: 480012,
      cost: 1_250_000,
      status: 'active',
      endDate: '2026-11-07T10:00:00+00:00',
      runs: 3,
      characterName: 'Aria Solberg',
      matchedAt: '2026-11-06T08:00:00+00:00',
      facilityId: 60003760,
      facilityName: 'Jita IV - Moon 4',
      manual: false,
    },
  ],
}

const AVAILABLE_JOBS = [
  {
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
  },
]

function mountRows(): Promise<VueWrapper> {
  return mountProductionJobRows({ lines: [ISHTAR_JOB], projectId: PROJECT_ID, role: 'owner', answers: { [AVAILABLE_JOBS_URL]: AVAILABLE_JOBS } })
}

async function linkCyraJob(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(OPEN_LINK_SCREEN).trigger('click')
  await flushPromises()
  const job = wrapper.findAll(AVAILABLE_JOB).find(candidate => candidate.text().includes('Cyra Lune'))
  await present(job).get(AVAILABLE_JOB_LINK).trigger('click')
  await flushPromises()
}

async function unlinkAria(wrapper: VueWrapper): Promise<void> {
  const match = wrapper.findAll(JOB_MATCH).find(candidate => candidate.text().includes('Aria Solberg'))
  await present(match).get(JOB_MATCH_UNLINK).trigger('click')
  await flushPromises()
}

function failOn(endpoint: string, message: string): void {
  const answer = vi.mocked(apiRequest).getMockImplementation()
  vi.mocked(apiRequest).mockImplementation((async (calledEndpoint: string, init?: RequestInit) => {
    if (calledEndpoint === endpoint) throw new Error(message)
    return answer?.(calledEndpoint, init)
  }) as never)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Production chain job link reload', () => {
  it('reloads the chain and tells the cockpit to reload after a job is linked to a line', async () => {
    const wrapper = await mountRows()
    expect(callsTo(CHAIN_URL)).toBe(1)

    await linkCyraJob(wrapper)

    expect(apiRequest).toHaveBeenCalledWith(LINK_JOB_URL, { method: 'POST', body: JSON.stringify({ esiJobId: 9001 }) })
    expect(callsTo(BOM_URL)).toBe(1)
    expect(callsTo(CHAIN_URL)).toBe(2)
    expect(wrapper.emitted('marked')).toHaveLength(1)
  })

  it('reloads the chain and tells the cockpit to reload after a job is unlinked from a line', async () => {
    const wrapper = await mountRows()

    await unlinkAria(wrapper)

    expect(apiRequest).toHaveBeenCalledWith(UNLINK_ARIA_URL, { method: 'DELETE' })
    expect(callsTo(BOM_URL)).toBe(1)
    expect(callsTo(CHAIN_URL)).toBe(2)
    expect(wrapper.emitted('marked')).toHaveLength(1)
  })

  it('leaves the chain and the cockpit alone when the link is refused', async () => {
    const wrapper = await mountRows()
    failOn(LINK_JOB_URL, LINK_REFUSED)

    await linkCyraJob(wrapper)

    expect(wrapper.get('[data-testid="job-link-error"]').text()).toBe(LINK_REFUSED)
    expect(callsTo(CHAIN_URL)).toBe(1)
    expect(wrapper.emitted('marked')).toBeUndefined()
  })

  it('leaves the chain and the cockpit alone when the unlink is refused', async () => {
    const wrapper = await mountRows()
    failOn(UNLINK_ARIA_URL, UNLINK_REFUSED)

    await unlinkAria(wrapper)

    expect(wrapper.get('[data-testid="job-link-error"]').text()).toBe(UNLINK_REFUSED)
    expect(callsTo(CHAIN_URL)).toBe(1)
    expect(wrapper.emitted('marked')).toBeUndefined()
  })
})
