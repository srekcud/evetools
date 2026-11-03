import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { OPERATION_O } from '../views/MiningOperation/miningOperationLedgerFixtures'
import {
  ARCHIVE_PATH,
  PAID_AT,
  PAYMENTS_PATH,
  completedE1Operation,
  completedP2Operation,
  g1Payments,
  payment,
  settledG1Operation,
} from '../views/MiningOperation/miningOperationPaymentFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store, payments, settlement and archive', () => {
  it('fetches the payments of the operation and keeps them', async () => {
    vi.mocked(apiRequest).mockResolvedValue(g1Payments())
    const store = useMiningOperationStore()

    await store.fetchPayments(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, store.payments]).toEqual([[[PAYMENTS_PATH]], g1Payments()])
  })

  it('marks a payment paid with a merge patch of the paid flag only and replaces its line', async () => {
    const markedLine = payment(1, { payerMemberId: 'member-brann', payerName: 'Brann Holt', amount: 430_269, paid: true, paidAt: PAID_AT, paidByName: 'Aria Solberg' })
    vi.mocked(apiRequest).mockResolvedValueOnce(g1Payments()).mockResolvedValueOnce(markedLine)
    const store = useMiningOperationStore()
    await store.fetchPayments(OPERATION_O)

    const marked = await store.markPayment(OPERATION_O, 'payment-1', true)

    expect([vi.mocked(apiRequest).mock.calls[1], marked, store.payments.map(line => line.paid)]).toEqual([
      [`${PAYMENTS_PATH}/payment-1`, { method: 'PATCH', body: JSON.stringify({ paid: true }) }],
      markedLine,
      [false, true, false],
    ])
  })

  it('settles the operation and keeps it as the current operation', async () => {
    vi.mocked(apiRequest).mockResolvedValue(settledG1Operation())
    const store = useMiningOperationStore()

    const settled = await store.settle(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, settled, store.currentOperation]).toEqual([
      [[`/mining-operations/${OPERATION_O}/settle`, { method: 'POST', body: JSON.stringify({}) }]],
      settledG1Operation(),
      settledG1Operation(),
    ])
  })

  it('keeps the refusal of the settlement and rethrows it', async () => {
    const refusal = new ApiError('Every payment must be marked paid before settling: 1 left', 400)
    vi.mocked(apiRequest).mockRejectedValue(refusal)
    const store = useMiningOperationStore()

    await expect(store.settle(OPERATION_O)).rejects.toBe(refusal)
    expect(store.error).toBe('Every payment must be marked paid before settling: 1 left')
  })

  it('completes the each for themselves operation and keeps it as the current operation', async () => {
    vi.mocked(apiRequest).mockResolvedValue(completedE1Operation())
    const store = useMiningOperationStore()

    const completed = await store.complete(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, completed, store.currentOperation]).toEqual([
      [[`/mining-operations/${OPERATION_O}/complete`, { method: 'POST', body: JSON.stringify({}) }]],
      completedE1Operation(),
      completedE1Operation(),
    ])
  })

  it('keeps the refusal of the completion and rethrows it', async () => {
    const refusal = new ApiError('Every mined quantity must be known before completing the operation: Brann Holt', 400)
    vi.mocked(apiRequest).mockRejectedValue(refusal)
    const store = useMiningOperationStore()

    await expect(store.complete(OPERATION_O)).rejects.toBe(refusal)
    expect(store.error).toBe('Every mined quantity must be known before completing the operation: Brann Holt')
  })

  it('fetches the archive and keeps it apart from my operations', async () => {
    vi.mocked(apiRequest).mockResolvedValue([settledG1Operation(), completedP2Operation()])
    const store = useMiningOperationStore()

    await store.fetchArchive()

    expect([vi.mocked(apiRequest).mock.calls, store.archivedOperations.map(operation => operation.label), store.myOperations]).toEqual([
      [[ARCHIVE_PATH]],
      ['Anomalie Spodumain — 20:00', 'Ceinture Veldspar — mardi'],
      [],
    ])
  })
})
