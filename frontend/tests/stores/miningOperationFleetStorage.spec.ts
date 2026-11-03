import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { OPERATION_O } from '../views/MiningOperation/miningOperationLedgerFixtures'
import { VELDSPAR_TYPE_ID } from '../views/MiningOperation/miningOperationValuationFixtures'
import {
  CONTAINER_NAME,
  CORPORATION_ASSETS_REFRESH_PATH,
  FLEET_STORAGE_PATH,
  VERIFICATION_PATH,
  fleetStorage,
  fs2FleetStorage,
  shortVerification,
} from '../views/MiningOperation/miningOperationFleetStorageFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store, fleet storage', () => {
  it('fetches the fleet storage of the operation and keeps it', async () => {
    vi.mocked(apiRequest).mockResolvedValue(fs2FleetStorage())
    const store = useMiningOperationStore()

    await store.fetchFleetStorage(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, store.fleetStorage]).toEqual([[[FLEET_STORAGE_PATH]], fs2FleetStorage()])
  })

  it('updates the recipient and the container with a merge patch of the given fields only', async () => {
    vi.mocked(apiRequest).mockResolvedValue(fs2FleetStorage())
    const store = useMiningOperationStore()

    const updated = await store.updateFleetStorage(OPERATION_O, { recipientMemberId: 'member-brann', containerName: CONTAINER_NAME })

    expect([vi.mocked(apiRequest).mock.calls, updated, store.fleetStorage]).toEqual([
      [[FLEET_STORAGE_PATH, { method: 'PATCH', body: JSON.stringify({ recipientMemberId: 'member-brann', containerName: CONTAINER_NAME }) }]],
      fs2FleetStorage(),
      fs2FleetStorage(),
    ])
  })

  it('declares the fleet storage of a participant and keeps the returned fleet storage', async () => {
    vi.mocked(apiRequest).mockResolvedValue(fs2FleetStorage())
    const store = useMiningOperationStore()

    const declared = await store.declareFleetStorage(OPERATION_O, 'member-aria', { delivery: 'partial', quantities: [{ typeId: VELDSPAR_TYPE_ID, quantity: 100_000 }] })

    expect([vi.mocked(apiRequest).mock.calls, declared, store.fleetStorage]).toEqual([
      [[`${FLEET_STORAGE_PATH}/declarations/member-aria`, { method: 'PATCH', body: JSON.stringify({ delivery: 'partial', quantities: [{ typeId: VELDSPAR_TYPE_ID, quantity: 100_000 }] }) }]],
      fs2FleetStorage(),
      fs2FleetStorage(),
    ])
  })

  it('keeps the refusal of a declaration and rethrows it without changing the fleet storage', async () => {
    const refusal = new ApiError('The fleet storage recipient keeps the ore delivered to them', 400)
    vi.mocked(apiRequest).mockResolvedValueOnce(fleetStorage()).mockRejectedValueOnce(refusal)
    const store = useMiningOperationStore()
    await store.fetchFleetStorage(OPERATION_O)

    await expect(store.declareFleetStorage(OPERATION_O, 'member-brann', { delivery: 'all' })).rejects.toBe(refusal)
    expect([store.error, store.fleetStorage]).toEqual(['The fleet storage recipient keeps the ore delivered to them', fleetStorage()])
  })

  it('fetches the verification of the container and keeps it', async () => {
    vi.mocked(apiRequest).mockResolvedValue(shortVerification())
    const store = useMiningOperationStore()

    await store.fetchFleetStorageVerification(OPERATION_O)

    expect([vi.mocked(apiRequest).mock.calls, store.fleetStorageVerification]).toEqual([[[VERIFICATION_PATH]], shortVerification()])
  })

  it('asks for a refresh of the corporation assets with an empty body', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ status: 'queued' })
    const store = useMiningOperationStore()

    await store.refreshCorporationAssets()

    expect(vi.mocked(apiRequest).mock.calls).toEqual([[CORPORATION_ASSETS_REFRESH_PATH, { method: 'POST', body: JSON.stringify({}) }]])
  })
})
