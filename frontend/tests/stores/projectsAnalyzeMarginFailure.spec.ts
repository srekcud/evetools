import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { ProfitMarginResult } from '@/stores/industry/types'
import { apiOutcomesByPath, awaiting, deferred, rejectWith, respondWith, type ApiOutcome } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_TYPE_ID = 12005
const VEXOR_TYPE_ID = 626
const PRICE_DATA_UNAVAILABLE = 'Price data unavailable (fictif)'

const ISHTAR_MARGIN: ProfitMarginResult = {
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  isT2: true,
  runs: 1,
  outputQuantity: 1,
  outputPerRun: 1,
  materialCost: 196_400_000,
  materials: [
    { typeId: 11_399, typeName: 'Morphite', quantity: 1_240, unitPrice: 9_350, totalPrice: 11_594_000 },
  ],
  jobInstallCost: 2_870_000,
  jobInstallSteps: [],
  inventionCost: 4_120_000,
  totalCost: 203_390_000,
  costPerUnit: 203_390_000,
  invention: null,
  sellPrices: {
    jitaSell: 268_000_000,
    structureSell: 262_500_000,
    structureBuy: 241_000_000,
    contractSell: null,
    contractCount: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.03,
  salesTaxRate: 0.036,
  margins: {
    jitaSell: { revenue: 268_000_000, fees: 17_688_000, profit: 46_922_000, margin: 17.51 },
  },
  dailyVolume: 37,
  missingData: [],
  warnings: [],
}

const VEXOR_MARGIN: ProfitMarginResult = {
  ...ISHTAR_MARGIN,
  typeId: VEXOR_TYPE_ID,
  typeName: 'Vexor',
  isT2: false,
  materialCost: 9_870_000,
  materials: [
    { typeId: 34, typeName: 'Tritanium', quantity: 1_620_000, unitPrice: 4.3, totalPrice: 6_966_000 },
  ],
  jobInstallCost: 215_000,
  inventionCost: 0,
  totalCost: 10_085_000,
  costPerUnit: 10_085_000,
  sellPrices: { ...ISHTAR_MARGIN.sellPrices, jitaSell: 14_600_000, structureSell: 14_250_000, structureBuy: 12_900_000 },
  margins: {
    jitaSell: { revenue: 14_600_000, fees: 963_600, profit: 3_551_400, margin: 24.32 },
  },
  dailyVolume: 212,
}

const outcomes = apiOutcomesByPath()

function respondToMarginOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`/industry/profit-margin/${typeId}`, outcome)
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

describe('projects store margin analysis failure (issue 189)', () => {
  it('clears the margin of the previous product and keeps the failure in the margin error, not in the page error', async () => {
    const store = useProjectsStore()
    respondToMarginOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_MARGIN))
    await store.analyzeMargin(ISHTAR_TYPE_ID)
    respondToMarginOf(VEXOR_TYPE_ID, rejectWith(new Error(PRICE_DATA_UNAVAILABLE)))

    const analysis = await store.analyzeMargin(VEXOR_TYPE_ID)

    expect({
      analysis,
      marginResult: store.marginResult,
      marginError: store.marginError,
      pageError: store.error,
      marginLoading: store.marginLoading,
    }).toEqual({
      analysis: null,
      marginResult: null,
      marginError: PRICE_DATA_UNAVAILABLE,
      pageError: null,
      marginLoading: false,
    })
  })

  it('keeps the generic margin failure when the failure carries no message', async () => {
    const store = useProjectsStore()
    respondToMarginOf(VEXOR_TYPE_ID, rejectWith('Gateway timeout (fictif)'))

    await store.analyzeMargin(VEXOR_TYPE_ID)

    expect({ marginError: store.marginError, pageError: store.error })
      .toEqual({ marginError: 'Failed to analyze profit margin', pageError: null })
  })

  it('replaces the margin and clears the margin error when the next analysis succeeds', async () => {
    const store = useProjectsStore()
    respondToMarginOf(VEXOR_TYPE_ID, rejectWith(new Error(PRICE_DATA_UNAVAILABLE)))
    await store.analyzeMargin(VEXOR_TYPE_ID)
    respondToMarginOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_MARGIN))

    const analysis = await store.analyzeMargin(ISHTAR_TYPE_ID)

    expect({ analysis, marginResult: store.marginResult, marginError: store.marginError })
      .toEqual({ analysis: ISHTAR_MARGIN, marginResult: ISHTAR_MARGIN, marginError: null })
  })

  it('clears the margin error as soon as the next analysis starts', async () => {
    const store = useProjectsStore()
    respondToMarginOf(VEXOR_TYPE_ID, rejectWith(new Error(PRICE_DATA_UNAVAILABLE)))
    await store.analyzeMargin(VEXOR_TYPE_ID)
    respondToMarginOf(ISHTAR_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))

    void store.analyzeMargin(ISHTAR_TYPE_ID)

    expect({ marginError: store.marginError, marginLoading: store.marginLoading })
      .toEqual({ marginError: null, marginLoading: true })
  })

  it('keeps the margin of the previous product while the next analysis is loading', async () => {
    const store = useProjectsStore()
    respondToMarginOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_MARGIN))
    await store.analyzeMargin(ISHTAR_TYPE_ID)
    respondToMarginOf(VEXOR_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))

    void store.analyzeMargin(VEXOR_TYPE_ID)

    expect({ marginResult: store.marginResult, marginLoading: store.marginLoading })
      .toEqual({ marginResult: ISHTAR_MARGIN, marginLoading: true })
  })

  it('ignores the margin of an outdated analysis that succeeds after the latest one', async () => {
    const store = useProjectsStore()
    const ishtarResponse = deferred<ProfitMarginResult>()
    respondToMarginOf(ISHTAR_TYPE_ID, awaiting(ishtarResponse))
    respondToMarginOf(VEXOR_TYPE_ID, respondWith(VEXOR_MARGIN))
    const outdatedAnalysis = store.analyzeMargin(ISHTAR_TYPE_ID)
    await store.analyzeMargin(VEXOR_TYPE_ID)

    ishtarResponse.resolve(ISHTAR_MARGIN)

    expect({ outdated: await outdatedAnalysis, marginResult: store.marginResult, marginLoading: store.marginLoading })
      .toEqual({ outdated: null, marginResult: VEXOR_MARGIN, marginLoading: false })
  })

  it('ignores the failure of an outdated analysis that fails after the latest one', async () => {
    const store = useProjectsStore()
    const ishtarResponse = deferred<ProfitMarginResult>()
    respondToMarginOf(ISHTAR_TYPE_ID, awaiting(ishtarResponse))
    respondToMarginOf(VEXOR_TYPE_ID, respondWith(VEXOR_MARGIN))
    const outdatedAnalysis = store.analyzeMargin(ISHTAR_TYPE_ID)
    await store.analyzeMargin(VEXOR_TYPE_ID)

    ishtarResponse.reject(new Error(PRICE_DATA_UNAVAILABLE))

    expect({
      outdated: await outdatedAnalysis,
      marginResult: store.marginResult,
      marginError: store.marginError,
      pageError: store.error,
      marginLoading: store.marginLoading,
    }).toEqual({ outdated: null, marginResult: VEXOR_MARGIN, marginError: null, pageError: null, marginLoading: false })
  })

  it('keeps loading until the latest analysis answers, whatever an outdated analysis answers before it', async () => {
    const store = useProjectsStore()
    const ishtarResponse = deferred<ProfitMarginResult>()
    const vexorResponse = deferred<ProfitMarginResult>()
    respondToMarginOf(ISHTAR_TYPE_ID, awaiting(ishtarResponse))
    respondToMarginOf(VEXOR_TYPE_ID, awaiting(vexorResponse))
    const outdatedAnalysis = store.analyzeMargin(ISHTAR_TYPE_ID)
    const latestAnalysis = store.analyzeMargin(VEXOR_TYPE_ID)

    ishtarResponse.resolve(ISHTAR_MARGIN)
    await outdatedAnalysis
    const whileLatestLoads = { marginResult: store.marginResult, marginLoading: store.marginLoading }
    vexorResponse.resolve(VEXOR_MARGIN)
    await latestAnalysis

    expect({ whileLatestLoads, marginResult: store.marginResult, marginLoading: store.marginLoading }).toEqual({
      whileLatestLoads: { marginResult: null, marginLoading: true },
      marginResult: VEXOR_MARGIN,
      marginLoading: false,
    })
  })
})
