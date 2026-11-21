import { enableAutoUnmount } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import type { BomItem } from '@/stores/group-industry/types'
import { chainNodeOfLine, mountProductionJobRows, rowOfLine } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const REAL_COST_COLUMN_INDEX = 4

function jobLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'job',
    typeId: 0,
    typeName: '',
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    remainingQuantity: 0,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'final',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 0,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

const SABRE_JOB = jobLine({ id: 'job-sabre', typeId: 22456, typeName: 'Sabre', requiredQuantity: 10, remainingQuantity: 10, runs: 10 })
const PURIFIER_JOB = jobLine({ id: 'job-purifier', typeId: 12038, typeName: 'Purifier', requiredQuantity: 5, remainingQuantity: 5, runs: 5 })
const SABRE_BLUEPRINT_COPY = jobLine({
  id: 'job-sabre-copy',
  typeId: 22457,
  typeName: 'Sabre Blueprint',
  requiredQuantity: 10,
  remainingQuantity: 10,
  runs: 10,
  jobGroup: 'blueprint',
  activityType: 'copy',
  meLevel: null,
  teLevel: null,
})

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Production job rows and the estimated cost of the jobs', () => {
  it('shows neither the estimated total of the jobs nor a jobs counter under the job tree', async () => {
    const wrapper = await mountProductionJobRows({ lines: [
      { ...SABRE_JOB, estimatedTotal: 100 },
      { ...PURIFIER_JOB, estimatedTotal: 200 },
    ] })

    expect(wrapper.text()).not.toContain(en.groupIndustry.bom.totalEstCost)
    expect(wrapper.text()).not.toContain('Total Jobs')
    expect(wrapper.text()).not.toContain(en.groupIndustry.bom.estCost)
  })

  it('keeps every job cost cell on the real cost of the node, never on the estimate or on 0', async () => {
    const lines = [{ ...SABRE_JOB, estimatedTotal: 0 }, { ...PURIFIER_JOB, estimatedTotal: 240 }, SABRE_BLUEPRINT_COPY]
    const wrapper = await mountProductionJobRows({
      lines,
      nodes: [
        chainNodeOfLine(lines[0], { costState: 'none' }),
        chainNodeOfLine(lines[1], { costState: 'known', realCost: 1_500_000 }),
        chainNodeOfLine(lines[2], { costState: 'unknown' }),
      ],
    })

    const costCells = lines.map(line => rowOfLine(wrapper, line.id).findAll('td')[REAL_COST_COLUMN_INDEX]?.text())
    expect(costCells[0]).toBe('—')
    expect(costCells[1]).toContain('1.50 M')
    expect(costCells[2]).toContain('—')
    expect(rowOfLine(wrapper, SABRE_BLUEPRINT_COPY.id).find('[data-testid="chain-cost-unknown"]').exists()).toBe(true)
  })
})
