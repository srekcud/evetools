import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningOperationStore, type MiningOperation, type MiningOperationLedger } from '@/stores/miningOperation'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPERATION_ID = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
const BRANN_II_ENROLMENT_ID = '6a2d9f4e-1c83-4b57-9e20-7f3c5a1d8b64'
const S1 = 30_003_504
const SPODUMAIN_TYPE_ID = 19

const STARTED_SPODUMAIN: MiningOperation = {
  id: OPERATION_ID,
  label: 'Anomalie Spodumain — 20:00',
  plannedStartAt: '2026-11-05T20:00:00+00:00',
  status: 'in_progress',
  visibility: 'corporation',
  splitMode: 'value_prorata',
  corporationId: 98_262_101,
  ownerCharacterName: 'Aria Solberg',
  participantsCount: 2,
  charactersCount: 4,
  joinCode: '7f3a9c2e1b',
  myRole: 'owner',
  myStatus: 'accepted',
  createdAt: '2026-11-03T18:00:00+00:00',
  startedAt: '2026-11-05T20:00:00+00:00',
  endedAt: null,
  solarSystemId: S1,
  solarSystemName: 'Aldranette',
  myDepartedAt: null,
  valuationSettings: {
    valuationForm: 'compressed',
    valuationMarket: 'jita',
    valuationSide: 'buy',
    structureId: null,
    structureName: null,
    reprocessYield: null,
    boosterCutPercent: 0,
    corporationCutPercent: 0,
    boosterMemberId: null,
    boosterCharacterName: null,
  },
  sharesFrozenAt: null,
}

const CLOSING_LEDGER: MiningOperationLedger = {
  operationId: OPERATION_ID,
  status: 'closing',
  startedAt: '2026-11-05T20:00:00+00:00',
  endedAt: '2026-11-05T21:30:00+00:00',
  solarSystemId: S1,
  solarSystemName: 'Aldranette',
  endSnapshotsReadyAt: '2026-11-05T21:39:50+00:00',
  endSnapshotsPending: false,
  endSnapshotsSettled: 0,
  endSnapshotsTotal: 4,
  participants: [],
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store, lifecycle and ledger', () => {
  it('starts the operation in S1 and keeps the started operation', async () => {
    vi.mocked(apiRequest).mockResolvedValue(STARTED_SPODUMAIN)
    const store = useMiningOperationStore()

    const started = await store.start(OPERATION_ID, S1)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/start`, { method: 'POST', body: JSON.stringify({ solarSystemId: S1 }) })
    expect([started, store.currentOperation]).toEqual([STARTED_SPODUMAIN, STARTED_SPODUMAIN])
  })

  it('starts the operation without solar system', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ ...STARTED_SPODUMAIN, solarSystemId: null, solarSystemName: null })
    const store = useMiningOperationStore()

    await store.start(OPERATION_ID, null)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/start`, { method: 'POST', body: JSON.stringify({ solarSystemId: null }) })
  })

  it('ends the operation and keeps the closing operation', async () => {
    const closing: MiningOperation = { ...STARTED_SPODUMAIN, status: 'closing', endedAt: '2026-11-05T21:30:00+00:00' }
    vi.mocked(apiRequest).mockResolvedValue(closing)
    const store = useMiningOperationStore()

    await store.end(OPERATION_ID)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/end`, { method: 'POST', body: JSON.stringify({}) })
    expect(store.currentOperation).toEqual(closing)
  })

  it('leaves the operation early and keeps the departure time', async () => {
    const departed: MiningOperation = { ...STARTED_SPODUMAIN, myRole: 'member', myDepartedAt: '2026-11-05T21:05:00+00:00' }
    vi.mocked(apiRequest).mockResolvedValue(departed)
    const store = useMiningOperationStore()

    await store.depart(OPERATION_ID)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/depart`, { method: 'POST', body: JSON.stringify({}) })
    expect(store.currentOperation?.myDepartedAt).toBe('2026-11-05T21:05:00+00:00')
  })

  it('loads the ledger of the operation', async () => {
    vi.mocked(apiRequest).mockResolvedValue(CLOSING_LEDGER)
    const store = useMiningOperationStore()

    await store.fetchLedger(OPERATION_ID)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/ledger`)
    expect(store.ledger).toEqual(CLOSING_LEDGER)
  })

  it('retries the snapshot of an enrolled character', async () => {
    vi.mocked(apiRequest).mockResolvedValue(null)
    const store = useMiningOperationStore()

    await store.retrySnapshot(OPERATION_ID, BRANN_II_ENROLMENT_ID)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/characters/${BRANN_II_ENROLMENT_ID}/snapshot-retry`, { method: 'POST', body: JSON.stringify({}) })
  })

  it('corrects the Spodumain quantity of an enrolled character', async () => {
    vi.mocked(apiRequest).mockResolvedValue(null)
    const store = useMiningOperationStore()

    await store.correctQuantity(OPERATION_ID, BRANN_II_ENROLMENT_ID, SPODUMAIN_TYPE_ID, 20_000)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/characters/${BRANN_II_ENROLMENT_ID}/quantity-corrections`, {
      method: 'POST',
      body: JSON.stringify({ typeId: SPODUMAIN_TYPE_ID, quantity: 20_000 }),
    })
  })

  it('keeps and rethrows the refusal of a correction', async () => {
    const refusal = new ApiError('This quantity is still being measured', 400)
    vi.mocked(apiRequest).mockRejectedValue(refusal)
    const store = useMiningOperationStore()

    await expect(store.correctQuantity(OPERATION_ID, BRANN_II_ENROLMENT_ID, SPODUMAIN_TYPE_ID, 9_000)).rejects.toBe(refusal)
    expect(store.error).toBe('This quantity is still being measured')
  })
})
