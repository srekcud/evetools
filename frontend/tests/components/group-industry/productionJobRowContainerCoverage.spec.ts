import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import type { BomItem, ChainStatus, JobMatch } from '@/stores/group-industry/types'
import { mountProductionJobRows, rowOfLine, statusLabelOfRow } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1013'
const jobLabels = en.groupIndustry.jobs
const statusLabels = en.groupIndustry.chain.status
const FUEL_BLOCK_ID = 'job-hydrogen-fuel-block'

function componentJob(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'job-hydrogen-fuel-block',
    typeId: 4246,
    typeName: 'Hydrogen Fuel Block',
    requiredQuantity: 120,
    fulfilledQuantity: 0,
    remainingQuantity: 120,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'component',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 3,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 120,
    containerQuantity: null,
    containerStockState: 'known',
    ...overrides,
  }
}

const deliveredMatch: JobMatch = {
  id: 'match-delivered',
  bomLineId: 'job-hydrogen-fuel-block',
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
}

async function mountRows(jobs: BomItem[], statuses: Record<string, ChainStatus>, role: 'owner' | 'member' = 'member'): Promise<VueWrapper> {
  return mountProductionJobRows({ lines: jobs, projectId: PROJECT_ID, role, statuses })
}

function rowText(wrapper: VueWrapper, lineId: string = FUEL_BLOCK_ID): string {
  return rowOfLine(wrapper, lineId).text()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Production job row of a component line covered by the project container', () => {
  it('shows a line fully covered by the container with the status of its chain node, without the handle button or the quantity input', async () => {
    const wrapper = await mountRows([componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true, fulfillmentPercent: 100 })], { [FUEL_BLOCK_ID]: 'done' })

    expect(statusLabelOfRow(wrapper, FUEL_BLOCK_ID)).toBe(statusLabels.done)
    expect(rowText(wrapper)).not.toContain(statusLabels.todo)
    expect(wrapper.find('[data-testid="job-container-remaining"]').exists()).toBe(false)
    expect(rowText(wrapper)).not.toContain(en.groupIndustry.reservation.reserve)
    expect(rowOfLine(wrapper, FUEL_BLOCK_ID).find('input[type="number"]').exists()).toBe(false)
  })

  it('shows a partly covered line as to do with only the remaining quantity', async () => {
    const wrapper = await mountRows([componentJob({ containerQuantity: 50, fulfilledQuantity: 50, remainingQuantity: 70, fulfillmentPercent: 41.7 })], { [FUEL_BLOCK_ID]: 'todo' })

    expect(statusLabelOfRow(wrapper, FUEL_BLOCK_ID)).toBe(statusLabels.todo)
    expect(rowText(wrapper)).toContain('Container 50 / 120, 70 left to launch')
    expect(rowText(wrapper)).not.toContain(statusLabels.active)
  })

  it('keeps the current state of a line whose container stock is unknown and never treats it as covered', async () => {
    const wrapper = await mountRows([componentJob({ containerQuantity: null, containerStockState: 'unknown' })], { [FUEL_BLOCK_ID]: 'todo' })

    expect(statusLabelOfRow(wrapper, FUEL_BLOCK_ID)).toBe(statusLabels.todo)
    expect(rowText(wrapper)).not.toContain(jobLabels.coveredByContainer)
    expect(wrapper.find('[data-testid="job-container-remaining"]').exists()).toBe(false)
  })

  it('keeps a line delivered by a linked job as done and shows no remaining text even if the container holds its product', async () => {
    const wrapper = await mountRows([componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true, matchedRuns: 3, jobMatches: [deliveredMatch] })], { [FUEL_BLOCK_ID]: 'done' })

    expect(statusLabelOfRow(wrapper, FUEL_BLOCK_ID)).toBe(statusLabels.done)
    expect(wrapper.find('[data-testid="job-container-remaining"]').exists()).toBe(false)
  })

  it('leaves a final product line untouched by the container', async () => {
    const wrapper = await mountRows([componentJob({ id: 'job-ferox', jobGroup: 'final', containerStockState: 'none', containerQuantity: null })], { 'job-ferox': 'todo' })

    expect(statusLabelOfRow(wrapper, 'job-ferox')).toBe(statusLabels.todo)
    expect(wrapper.find('[data-testid="job-container-remaining"]').exists()).toBe(false)
  })

  it('counts the line covered by the container apart from the line to launch in the status filter', async () => {
    const wrapper = await mountRows([
      componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true }),
      componentJob({ id: 'job-nitrogen', typeId: 4051, typeName: 'Nitrogen Fuel Block', containerQuantity: 0 }),
    ], { [FUEL_BLOCK_ID]: 'done', 'job-nitrogen': 'todo' })

    expect(wrapper.get('[data-testid="chain-status-filter-done"]').text()).toBe(`${statusLabels.done} 1`)
    expect(wrapper.get('[data-testid="chain-status-filter-todo"]').text()).toBe(`${statusLabels.todo} 1`)
  })

  it('shows the unclaimed stock badge under a line covered by the container', async () => {
    const wrapper = await mountRows([componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true, unclaimedQuantity: 120, launchPrice: 2000, unclaimedValue: 240_000 })], { [FUEL_BLOCK_ID]: 'done' }, 'owner')

    const row = rowOfLine(wrapper, FUEL_BLOCK_ID)
    expect(row.find('[data-testid="unclaimed-stock-badge"]').exists()).toBe(true)
    expect(row.find('[data-testid="unclaimed-stock-assign"]').exists()).toBe(true)
  })
})
