import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BatchScanItem, BuyVsBuildResult, PivotAnalysisResult } from '@/stores/industry/types'
import { apiOutcomesByPath, awaiting, deferred, rejectWith, respondWith, type ApiOutcome } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_TYPE_ID = 12005
const VEXOR_TYPE_ID = 626
const RIFTER_TYPE_ID = 587
const MERLIN_TYPE_ID = 603
const TRISTAN_TYPE_ID = 593
const BLUEPRINT_NOT_FOUND = 'Blueprint not found (fictif)'
const SCAN_TIMEOUT = 'Scan timeout (fictif)'
const PROFIT_SCAN_PATH = '/industry/profit-scan'
const FIRST_SCAN_AT = new Date('2026-10-29T08:00:00Z')
const SECOND_SCAN_AT = new Date('2026-10-29T08:25:00Z')

const ISHTAR_BUY_VS_BUILD: BuyVsBuildResult = {
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  isT2: true,
  runs: 1,
  totalProductionCost: 212_400_000,
  sellPrice: 268_000_000,
  marginPercent: 20.75,
  components: [],
  buildAllCost: 9_120_000,
  buyAllCost: 10_560_000,
  optimalMixCost: 9_120_000,
  buildTypeIds: [],
  buyTypeIds: [],
  missingData: [],
  warnings: [],
  sellStructureId: null,
}

const VEXOR_BUY_VS_BUILD: BuyVsBuildResult = {
  ...ISHTAR_BUY_VS_BUILD,
  typeId: VEXOR_TYPE_ID,
  typeName: 'Vexor',
  isT2: false,
  totalProductionCost: 10_085_000,
  sellPrice: 14_600_000,
  marginPercent: 30.92,
  buildAllCost: 640_000,
  buyAllCost: 712_000,
  optimalMixCost: 640_000,
}

const ISHTAR_PIVOT: PivotAnalysisResult = {
  typeId: ISHTAR_TYPE_ID,
  sourceProduct: {
    typeId: ISHTAR_TYPE_ID,
    typeName: 'Ishtar',
    groupName: 'Heavy Assault Cruiser',
    marginPercent: 14.2,
    sellPrice: 268_000_000,
    dailyVolume: 37,
    keyComponents: [],
  },
  matrix: [],
  candidates: [],
  matrixProductIds: [ISHTAR_TYPE_ID],
}

function scanItem(typeId: number, typeName: string, marginPercent: number, dailyVolume: number, sellPrice: number): BatchScanItem {
  return {
    typeId,
    typeName,
    groupName: 'Frigate',
    categoryLabel: 'T1 Ships',
    marginPercent,
    profitPerUnit: Math.round(sellPrice * marginPercent / 100),
    dailyVolume,
    iskPerDay: Math.round(sellPrice * marginPercent / 100) * dailyVolume,
    materialCost: Math.round(sellPrice * (1 - marginPercent / 100)),
    importCost: 2_450,
    exportCost: 25_000,
    sellPrice,
    meUsed: 10,
    activityType: 'manufacturing',
    isFactionBlueprint: false,
    bpcCostPerRun: null,
    hasAllSkills: true,
    missingSkillCount: 0,
    unknownReason: null,
    missingPriceTypeIds: [],
  }
}

const RIFTER = scanItem(RIFTER_TYPE_ID, 'Rifter', 21.5, 75, 520_000)
const MERLIN = scanItem(MERLIN_TYPE_ID, 'Merlin', 18.4, 64, 610_000)
const TRISTAN = scanItem(TRISTAN_TYPE_ID, 'Tristan', 16.2, 91, 705_000)

const outcomes = apiOutcomesByPath()

function respondToBuyVsBuildOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`/industry/buy-vs-build/${typeId}`, outcome)
}

function respondToPivotOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`/industry/pivot-advisor/${typeId}`, outcome)
}

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('scanner store buy vs build failure (issue 189)', () => {
  it('clears the buy vs build of the previous product when the next analysis fails', async () => {
    const store = useScannerStore()
    respondToBuyVsBuildOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_BUY_VS_BUILD))
    await store.fetchBuyVsBuild(ISHTAR_TYPE_ID, 1, 2)
    respondToBuyVsBuildOf(VEXOR_TYPE_ID, rejectWith(new Error(BLUEPRINT_NOT_FOUND)))

    await store.fetchBuyVsBuild(VEXOR_TYPE_ID, 3, 10)

    expect({ result: store.buyVsBuildResult, error: store.buyVsBuildError, loading: store.buyVsBuildLoading })
      .toEqual({ result: null, error: BLUEPRINT_NOT_FOUND, loading: false })
  })

  it('ignores the buy vs build of an outdated analysis that succeeds after the latest one', async () => {
    const store = useScannerStore()
    const ishtarResponse = deferred<BuyVsBuildResult>()
    respondToBuyVsBuildOf(ISHTAR_TYPE_ID, awaiting(ishtarResponse))
    respondToBuyVsBuildOf(VEXOR_TYPE_ID, respondWith(VEXOR_BUY_VS_BUILD))
    const outdatedAnalysis = store.fetchBuyVsBuild(ISHTAR_TYPE_ID, 1, 2)
    await store.fetchBuyVsBuild(VEXOR_TYPE_ID, 3, 10)

    ishtarResponse.resolve(ISHTAR_BUY_VS_BUILD)
    await outdatedAnalysis

    expect({ result: store.buyVsBuildResult, error: store.buyVsBuildError, loading: store.buyVsBuildLoading })
      .toEqual({ result: VEXOR_BUY_VS_BUILD, error: null, loading: false })
  })

  it('keeps loading until the latest analysis answers, whatever an outdated analysis answers before it', async () => {
    const store = useScannerStore()
    const ishtarResponse = deferred<BuyVsBuildResult>()
    respondToBuyVsBuildOf(ISHTAR_TYPE_ID, awaiting(ishtarResponse))
    respondToBuyVsBuildOf(VEXOR_TYPE_ID, awaiting(deferred<BuyVsBuildResult>()))
    const outdatedAnalysis = store.fetchBuyVsBuild(ISHTAR_TYPE_ID, 1, 2)
    void store.fetchBuyVsBuild(VEXOR_TYPE_ID, 3, 10)

    ishtarResponse.reject(new Error(BLUEPRINT_NOT_FOUND))
    await outdatedAnalysis

    expect({ result: store.buyVsBuildResult, error: store.buyVsBuildError, loading: store.buyVsBuildLoading })
      .toEqual({ result: null, error: null, loading: true })
  })
})

describe('scanner store pivot analysis failure (issue 189)', () => {
  it('clears the pivots of the previous product when the next analysis fails', async () => {
    const store = useScannerStore()
    respondToPivotOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_PIVOT))
    await store.fetchPivotAnalysis(ISHTAR_TYPE_ID, 1)
    respondToPivotOf(VEXOR_TYPE_ID, rejectWith(new ApiError('API error: 503', 503)))

    await store.fetchPivotAnalysis(VEXOR_TYPE_ID, 5)

    expect({ result: store.pivotResult, error: store.pivotError, loading: store.pivotLoading })
      .toEqual({ result: null, error: 'API error: 503', loading: false })
  })

  it('keeps the failure of the latest analysis when an outdated analysis succeeds after it', async () => {
    const store = useScannerStore()
    const ishtarResponse = deferred<PivotAnalysisResult>()
    respondToPivotOf(ISHTAR_TYPE_ID, awaiting(ishtarResponse))
    respondToPivotOf(VEXOR_TYPE_ID, rejectWith(new ApiError('API error: 503', 503)))
    const outdatedAnalysis = store.fetchPivotAnalysis(ISHTAR_TYPE_ID, 1)
    await store.fetchPivotAnalysis(VEXOR_TYPE_ID, 5)

    ishtarResponse.resolve(ISHTAR_PIVOT)
    await outdatedAnalysis

    expect({ result: store.pivotResult, error: store.pivotError, loading: store.pivotLoading })
      .toEqual({ result: null, error: 'API error: 503', loading: false })
  })

  it('keeps loading until the latest analysis answers, whatever an outdated analysis answers before it', async () => {
    const store = useScannerStore()
    respondToPivotOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_PIVOT))
    await store.fetchPivotAnalysis(ISHTAR_TYPE_ID, 1)
    const vexorResponse = deferred<PivotAnalysisResult>()
    respondToPivotOf(VEXOR_TYPE_ID, awaiting(vexorResponse))
    respondToPivotOf(ISHTAR_TYPE_ID, awaiting(deferred<PivotAnalysisResult>()))
    const outdatedAnalysis = store.fetchPivotAnalysis(VEXOR_TYPE_ID, 5)
    void store.fetchPivotAnalysis(ISHTAR_TYPE_ID, 2)

    vexorResponse.reject(new ApiError('API error: 503', 503))
    await outdatedAnalysis

    expect({ result: store.pivotResult, error: store.pivotError, loading: store.pivotLoading })
      .toEqual({ result: ISHTAR_PIVOT, error: null, loading: true })
  })
})

describe('scanner store failure without message (issue 189)', () => {
  it('keeps the generic buy vs build failure when the failure carries no message', async () => {
    const store = useScannerStore()
    respondToBuyVsBuildOf(VEXOR_TYPE_ID, rejectWith('Gateway timeout (fictif)'))

    await store.fetchBuyVsBuild(VEXOR_TYPE_ID, 3, 10)

    expect({ result: store.buyVsBuildResult, error: store.buyVsBuildError })
      .toEqual({ result: null, error: 'Analysis failed' })
  })

  it('keeps the generic pivot failure when the failure carries no message', async () => {
    const store = useScannerStore()
    respondToPivotOf(VEXOR_TYPE_ID, rejectWith('Gateway timeout (fictif)'))

    await store.fetchPivotAnalysis(VEXOR_TYPE_ID, 5)

    expect({ result: store.pivotResult, error: store.pivotError })
      .toEqual({ result: null, error: 'Analysis failed' })
  })

  it('keeps the generic scan failure when the failure carries no message', async () => {
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, rejectWith('Gateway timeout (fictif)'))

    await store.fetchBatchScan()

    expect({ scanResults: store.scanResults, scanError: store.scanError })
      .toEqual({ scanResults: [], scanError: 'Scan failed' })
  })
})

describe('scanner store batch scan failure (issue 189)', () => {
  it('clears the products of the previous scan when the scan with new filters fails, and keeps the date of the last successful scan', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    const store = useScannerStore()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([RIFTER, MERLIN, TRISTAN]))
    outcomes.respondTo(PROFIT_SCAN_PATH, rejectWith(new Error(SCAN_TIMEOUT)))
    vi.setSystemTime(FIRST_SCAN_AT)
    await store.fetchBatchScan()
    vi.setSystemTime(SECOND_SCAN_AT)

    await store.fetchBatchScan({ category: 'ships', minMargin: 15 })

    expect({
      scanResults: store.scanResults,
      scanError: store.scanError,
      scanLoading: store.scanLoading,
      lastScanAt: store.lastScanAt,
      requested: vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint),
    }).toEqual({
      scanResults: [],
      scanError: SCAN_TIMEOUT,
      scanLoading: false,
      lastScanAt: FIRST_SCAN_AT,
      requested: [
        `${PROFIT_SCAN_PATH}?sellVenue=jita`,
        `${PROFIT_SCAN_PATH}?category=ships&minMargin=15&sellVenue=jita`,
      ],
    })
  })

  it('ignores the products of an outdated scan that answers after the latest one', async () => {
    const store = useScannerStore()
    const firstScanResponse = deferred<BatchScanItem[]>()
    outcomes.respondTo(PROFIT_SCAN_PATH, awaiting(firstScanResponse))
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([MERLIN]))
    const outdatedScan = store.fetchBatchScan()
    await store.fetchBatchScan({ category: 'ships', minMargin: 15 })

    firstScanResponse.resolve([RIFTER, MERLIN, TRISTAN])
    await outdatedScan

    expect({ scanResults: store.scanResults, scanError: store.scanError, scanLoading: store.scanLoading })
      .toEqual({ scanResults: [MERLIN], scanError: null, scanLoading: false })
  })

  it('keeps loading until the latest scan answers, whatever an outdated scan answers before it', async () => {
    const store = useScannerStore()
    const outdatedScanResponse = deferred<BatchScanItem[]>()
    outcomes.respondTo(PROFIT_SCAN_PATH, respondWith([RIFTER, TRISTAN]))
    outcomes.respondTo(PROFIT_SCAN_PATH, awaiting(outdatedScanResponse))
    outcomes.respondTo(PROFIT_SCAN_PATH, awaiting(deferred<BatchScanItem[]>()))
    await store.fetchBatchScan()
    const outdatedScan = store.fetchBatchScan({ category: 'ships' })
    void store.fetchBatchScan({ minMargin: 15 })

    outdatedScanResponse.reject(new Error(SCAN_TIMEOUT))
    await outdatedScan

    expect({ scanResults: store.scanResults, scanError: store.scanError, scanLoading: store.scanLoading })
      .toEqual({ scanResults: [RIFTER, TRISTAN], scanError: null, scanLoading: true })
  })
})
