import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import type { BomItem } from '@/stores/group-industry/types'
import { mountProductionJobRows, rowOfLine } from '../../productionJobRows'
import { bomLine, jobMatch } from './companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const INVENTION_LINE_ID = 'job-hammerhead-ii-invention'
const MANUFACTURING_LINE_ID = 'job-hammerhead-ii'
const EXPECTED_COPIES = '[data-testid="invention-expected-copies"]'
const OBTAINED_COPIES = '[data-testid="job-match-obtained-copies"]'

function inventionLine(overrides: Partial<BomItem> = {}): BomItem {
  return bomLine({
    id: INVENTION_LINE_ID,
    typeId: 2_205,
    typeName: 'Hammerhead II',
    isJob: true,
    jobGroup: 'blueprint',
    activityType: 'invention',
    runs: 59,
    expectedBlueprintCopies: 20.06,
    ...overrides,
  })
}

function deliveredInventionJob(id: string, esiJobId: number, successfulRuns: number | null) {
  return jobMatch({ id, bomLineId: INVENTION_LINE_ID, status: 'delivered', esiJobId, runs: 20, successfulRuns })
}

async function mountLines(lines: BomItem[], locale: 'en' | 'fr' = 'fr'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockReset()
  return mountProductionJobRows({ lines, locale })
}

function expectedCopiesTextOf(wrapper: VueWrapper, lineId: string): string {
  const expected = rowOfLine(wrapper, lineId).find(EXPECTED_COPIES)
  return expected.exists() ? expected.text() : 'absent'
}

describe('Production tree, result of an invention step', () => {
  it('shows the expected blueprint copies of an invention step with one decimal in French', async () => {
    const wrapper = await mountLines([inventionLine()])

    expect(expectedCopiesTextOf(wrapper, INVENTION_LINE_ID)).toBe('≈ 20,1 BPC attendus')
  })

  it('writes the expected blueprint copies with the decimal separator of the English interface', async () => {
    const wrapper = await mountLines([inventionLine()], 'en')

    expect(expectedCopiesTextOf(wrapper, INVENTION_LINE_ID)).toContain('20.1')
  })

  it('shows the obtained blueprint copies of each linked job, zero included, and nothing for a job without result', async () => {
    const wrapper = await mountLines([inventionLine({
      jobMatches: [
        deliveredInventionJob('match-first', 7_316_001, 21),
        deliveredInventionJob('match-second', 7_316_002, 0),
        deliveredInventionJob('match-third', 7_316_003, null),
      ],
    })])

    expect(wrapper.findAll(OBTAINED_COPIES).map(obtained => obtained.text())).toEqual(['21 BPC obtenus', '0 BPC obtenus'])
  })

  it('shows no expected blueprint copies when the invention step has none', async () => {
    const wrapper = await mountLines([inventionLine({ expectedBlueprintCopies: null })])

    expect(expectedCopiesTextOf(wrapper, INVENTION_LINE_ID)).toBe('absent')
  })

  it('shows no blueprint copies text on a manufacturing step', async () => {
    const manufacturingLine = bomLine({
      id: MANUFACTURING_LINE_ID,
      typeId: 2_205,
      typeName: 'Hammerhead II',
      isJob: true,
      jobGroup: 'final',
      activityType: 'manufacturing',
      runs: 5,
      expectedBlueprintCopies: null,
    })
    const wrapper = await mountLines([manufacturingLine])

    expect(rowOfLine(wrapper, MANUFACTURING_LINE_ID).text()).not.toContain('BPC')
  })
})
