import { enableAutoUnmount } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import type { BomItem, JobLocation, JobLocations, JobMatch } from '@/stores/group-industry/types'
import { mountProductionJobRows } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'

const LOCATIONS_LINE = '[data-testid="job-match-locations"]'
const LOCATIONS_CHECK = '[data-testid="job-match-check"]'

function side(overrides: Partial<JobLocation>): JobLocation {
  return {
    locationId: 1_049_000_000_001,
    kind: 'container',
    name: 'ISHTAR-319',
    divisionName: 'Corporation Hangar 2',
    status: 'inside',
    unknownReason: null,
    ...overrides,
  }
}

function jobLineWith(locations: JobLocations | null): BomItem {
  const match: JobMatch = {
    id: 'match-aria',
    bomLineId: 'job-ishtar',
    esiJobId: 480012,
    cost: 1_250_000,
    status: 'delivered',
    endDate: '2026-11-05T22:00:00+00:00',
    runs: 3,
    characterName: 'Aria Solberg',
    matchedAt: '2026-11-06T08:00:00+00:00',
    facilityId: null,
    facilityName: null,
    manual: false,
    locations,
  }
  return {
    id: 'job-ishtar',
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
    jobMatches: [match],
    matchedRuns: 3,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
  }
}

function mountWith(locations: JobLocations | null) {
  return mountProductionJobRows({ lines: [jobLineWith(locations)], projectId: PROJECT_ID, role: 'owner' })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Production job row job locations', () => {
  it('does not repeat the entry and the exit of a linked job under the job', async () => {
    const wrapper = await mountWith({
      blueprint: side({ name: 'Perso box', divisionName: null, status: 'outside' }),
      output: side({ kind: 'hangar', name: 'Jita IV - Moon 4', divisionName: null, status: 'outside' }),
    })

    expect(wrapper.find(LOCATIONS_LINE).exists()).toBe(false)
    expect(wrapper.find(LOCATIONS_CHECK).exists()).toBe(false)
  })

  it('keeps the pilot, the runs and the status of the linked job under the job', async () => {
    const wrapper = await mountWith({ blueprint: side({}), output: side({}) })

    const match = wrapper.get('[data-testid="job-match"]').text()
    expect(match).toContain('Aria Solberg')
    expect(match).toContain('3 Runs')
    expect(match).toContain('delivered')
  })

  it('shows no location line when the locations are not shared with the viewer', async () => {
    const wrapper = await mountWith(null)

    expect(wrapper.find(LOCATIONS_LINE).exists()).toBe(false)
    expect(wrapper.get('[data-testid="job-match"]').text()).toContain('Aria Solberg')
  })
})
