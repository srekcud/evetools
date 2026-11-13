import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BatchScanItem } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROFIT_SCAN_PATH = '/industry/profit-scan'
const C_J6MT_STRUCTURE_ID = 1_049_588_174_021
const RIFTER_TYPE_ID = 587

const RIFTER_WITHOUT_DAILY_VOLUME: BatchScanItem = {
  typeId: RIFTER_TYPE_ID,
  typeName: 'Rifter',
  groupName: 'Frigate',
  categoryLabel: 'T1 Ships',
  marginPercent: 21.5,
  profitPerUnit: 96_000,
  dailyVolume: null,
  iskPerDay: null,
  materialCost: 380_000,
  importCost: 2_450,
  exportCost: 0,
  sellPrice: 520_000,
  meUsed: 10,
  activityType: 'manufacturing',
  isFactionBlueprint: false,
  bpcCostPerRun: null,
  hasAllSkills: true,
  missingSkillCount: 0,
  unknownReason: 'missing_daily_volume',
  missingPriceTypeIds: [],
}

const STRUCTURE_FILTERS = { category: 'all', minMargin: 5, minDailyVolume: 50, sellVenue: 'structure', structureId: C_J6MT_STRUCTURE_ID }

const outcomes = apiOutcomesByPath()

function requestedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)
}

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => outcomes.answer(endpoint))
})

describe('scanner store scan diagnosis (issue 884)', () => {
  it('reads the same scan without the minimum margin and the minimum daily volume when the filtered scan is empty', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([]))
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([RIFTER_WITHOUT_DAILY_VOLUME]))

    await store.fetchBatchScan(STRUCTURE_FILTERS)

    expect({ requested: requestedEndpoints(), diagnosisRows: store.scanDiagnosisRows, failed: store.scanDiagnosisFailed }).toEqual({
      requested: [
        `${PROFIT_SCAN_PATH}?minMargin=5&minDailyVolume=50&sellVenue=structure&structureId=${C_J6MT_STRUCTURE_ID}`,
        `${PROFIT_SCAN_PATH}?sellVenue=structure&structureId=${C_J6MT_STRUCTURE_ID}`,
      ],
      diagnosisRows: [RIFTER_WITHOUT_DAILY_VOLUME],
      failed: false,
    })
  })

  it('keeps the category in the diagnosis scan', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([]))
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([]))

    await store.fetchBatchScan({ ...STRUCTURE_FILTERS, category: 't1_ships' })

    expect(requestedEndpoints()[1]).toBe(`${PROFIT_SCAN_PATH}?category=t1_ships&sellVenue=structure&structureId=${C_J6MT_STRUCTURE_ID}`)
  })

  it('does not read a second scan when the scan returns products, and drops the diagnosis of the previous scan', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([]))
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([RIFTER_WITHOUT_DAILY_VOLUME]))
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([RIFTER_WITHOUT_DAILY_VOLUME]))
    await store.fetchBatchScan(STRUCTURE_FILTERS)
    vi.mocked(apiRequest).mockClear()

    await store.fetchBatchScan({ minMargin: null, minDailyVolume: null })

    expect({ requested: requestedEndpoints(), diagnosisRows: store.scanDiagnosisRows }).toEqual({
      requested: [`${PROFIT_SCAN_PATH}?sellVenue=structure&structureId=${C_J6MT_STRUCTURE_ID}`],
      diagnosisRows: null,
    })
  })

  it('does not read a second scan when an empty scan had no minimum to relax', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([]))

    await store.fetchBatchScan({ ...STRUCTURE_FILTERS, minMargin: null, minDailyVolume: null })

    expect({ requested: requestedEndpoints().length, diagnosisRows: store.scanDiagnosisRows }).toEqual({ requested: 1, diagnosisRows: [] })
  })

  it('flags the diagnosis as failed when the second scan fails, and keeps the empty result without an error', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([]))
    outcomes.respondTo(PROFIT_SCAN_PATH, rejectWith(new Error('Gateway timeout (fictif)')))

    await store.fetchBatchScan(STRUCTURE_FILTERS)

    expect({ diagnosisRows: store.scanDiagnosisRows, failed: store.scanDiagnosisFailed, scanError: store.scanError, scanLoading: store.scanLoading })
      .toEqual({ diagnosisRows: null, failed: true, scanError: null, scanLoading: false })
  })

  it('does not diagnose a scan that failed', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, rejectWith(new Error('Scan timeout (fictif)')))

    await store.fetchBatchScan(STRUCTURE_FILTERS)

    expect({ requested: requestedEndpoints().length, diagnosisRows: store.scanDiagnosisRows, failed: store.scanDiagnosisFailed })
      .toEqual({ requested: 1, diagnosisRows: null, failed: false })
  })
})
