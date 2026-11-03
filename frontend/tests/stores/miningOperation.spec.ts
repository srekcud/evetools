import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningOperationStore, type MiningOperation, type MiningOperationInvitation, type MiningOperationMember } from '@/stores/miningOperation'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const OPERATION_ID = '4d2f8a61-3b7c-4e95-a0d8-6c1e9f2b7a53'
const CYRA_MEMBER_ID = '8b3e1f6a-9c24-4d70-b5e8-2a7f4c1d9e36'
const BRANN_HOLT_ID = '9c1b4e7a-2d58-4f36-b0a9-3e6d8f2c1a47'
const BRANN_II_ID = '2f7a9d3c-6e14-4b82-a5c0-8d1e4b7f9a36'
const JOIN_CODE = '7f3a9c2e1b'

const SPODUMAIN: MiningOperation = {
  id: OPERATION_ID,
  label: 'Anomalie Spodumain — 20:00',
  plannedStartAt: '2026-11-05T20:00:00+00:00',
  status: 'planned',
  visibility: 'corporation',
  splitMode: 'value_prorata',
  corporationId: 98_262_101,
  ownerCharacterName: 'Aria Solberg',
  participantsCount: 1,
  charactersCount: 2,
  joinCode: JOIN_CODE,
  myRole: 'owner',
  myStatus: 'accepted',
  createdAt: '2026-11-03T18:00:00+00:00',
  startedAt: null,
  endedAt: null,
  solarSystemId: null,
  solarSystemName: null,
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
  archivedAt: null,
  archivedByName: null,
  archiveSummary: null,
  fleetStorageRecipientName: null,
}

const ICE_BELT: MiningOperation = { ...SPODUMAIN, id: 'b7e4c2a9-1d63-4f58-8a0e-5c9d3f7b2e14', label: 'Ice belt — dimanche', participantsCount: 3, charactersCount: 5 }

const BRANN_INVITATION: MiningOperationInvitation = {
  code: JOIN_CODE,
  operationId: OPERATION_ID,
  label: 'Anomalie Spodumain — 20:00',
  ownerCharacterName: 'Aria Solberg',
  plannedStartAt: '2026-11-05T20:00:00+00:00',
  status: 'planned',
  visibility: 'corporation',
  splitMode: 'value_prorata',
  participantsCount: 2,
  autoAccepted: true,
  myRole: 'member',
  myStatus: 'accepted',
  characters: [],
  valuationSettings: {
    valuationForm: 'compressed',
    valuationMarket: 'jita',
    valuationSide: 'buy',
    structureId: null,
    structureName: null,
    reprocessYield: null,
    boosterCutPercent: 0,
    corporationCutPercent: 0,
    boosterCharacterName: null,
  },
  fleetStorageRecipientName: null,
}

const CYRA_MEMBER: MiningOperationMember = {
  id: CYRA_MEMBER_ID,
  characterName: 'Cyra Lune',
  corporationId: 98_262_102,
  role: 'member',
  status: 'accepted',
  joinedAt: '2026-11-04T09:30:00+00:00',
  departedAt: null,
  characters: [{ id: 'c4a1e8d2-7b36-4f9c-a2e5-1d8b6f3c9a70', eveCharacterId: 2_112_262_105, name: 'Cyra Lune' }],
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining operation store', () => {
  it('loads my operations', async () => {
    vi.mocked(apiRequest).mockResolvedValue([ICE_BELT, SPODUMAIN])
    const store = useMiningOperationStore()

    await store.fetchMine()

    expect(apiRequest).toHaveBeenCalledWith('/mining-operations')
    expect(store.myOperations).toEqual([ICE_BELT, SPODUMAIN])
  })

  it('loads the available operations of my corporation', async () => {
    vi.mocked(apiRequest).mockResolvedValue([SPODUMAIN])
    const store = useMiningOperationStore()

    await store.fetchAvailable()

    expect(apiRequest).toHaveBeenCalledWith('/mining-operations/available')
    expect(store.availableOperations).toEqual([SPODUMAIN])
  })

  it('loads the detail and the members of an operation', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint.endsWith('/members') ? [CYRA_MEMBER] : SPODUMAIN) as never)
    const store = useMiningOperationStore()

    await store.fetchDetail(OPERATION_ID)
    await store.fetchMembers(OPERATION_ID)

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual([`/mining-operations/${OPERATION_ID}`, `/mining-operations/${OPERATION_ID}/members`])
    expect(store.currentOperation).toEqual(SPODUMAIN)
    expect(store.members).toEqual([CYRA_MEMBER])
  })

  it('creates an operation with the ticked characters and puts it first in my operations', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([ICE_BELT]).mockResolvedValueOnce(SPODUMAIN)
    const store = useMiningOperationStore()
    await store.fetchMine()
    const input = {
      label: 'Anomalie Spodumain — 20:00',
      plannedStartAt: '2026-11-05T20:00:00+00:00',
      visibility: 'corporation' as const,
      splitMode: 'value_prorata' as const,
      characterIds: [BRANN_HOLT_ID, BRANN_II_ID],
    }

    const created = await store.create(input)

    expect(apiRequest).toHaveBeenLastCalledWith('/mining-operations', { method: 'POST', body: JSON.stringify(input) })
    expect(created).toEqual(SPODUMAIN)
    expect(store.myOperations).toEqual([SPODUMAIN, ICE_BELT])
  })

  it('loads the invitation of a join code', async () => {
    vi.mocked(apiRequest).mockResolvedValue(BRANN_INVITATION)
    const store = useMiningOperationStore()

    await store.fetchInvitation(JOIN_CODE)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/join/${JOIN_CODE}`)
    expect(store.invitation).toEqual(BRANN_INVITATION)
  })

  it('joins with the ticked characters and keeps the updated invitation', async () => {
    vi.mocked(apiRequest).mockResolvedValue(BRANN_INVITATION)
    const store = useMiningOperationStore()

    const joined = await store.join(JOIN_CODE, [BRANN_HOLT_ID, BRANN_II_ID])

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/join/${JOIN_CODE}`, {
      method: 'POST',
      body: JSON.stringify({ characterIds: [BRANN_HOLT_ID, BRANN_II_ID] }),
    })
    expect(joined).toEqual(BRANN_INVITATION)
    expect(store.invitation).toEqual(BRANN_INVITATION)
  })

  it('keeps the server message when a ticked character is already in another open operation', async () => {
    const refusal = new ApiError('Brann IV is already in the open operation "Ice belt — dimanche"', 409)
    vi.mocked(apiRequest).mockRejectedValue(refusal)
    const store = useMiningOperationStore()

    await expect(store.join(JOIN_CODE, [BRANN_HOLT_ID])).rejects.toBe(refusal)
    expect(store.error).toBe('Brann IV is already in the open operation "Ice belt — dimanche"')
  })

  it('renews the join code and keeps the renewed operation as current', async () => {
    const renewed = { ...SPODUMAIN, joinCode: '0e9d4b7c21' }
    vi.mocked(apiRequest).mockResolvedValue(renewed)
    const store = useMiningOperationStore()

    await store.renewJoinCode(OPERATION_ID)

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/join-code`, expect.objectContaining({ method: 'POST' }))
    expect(store.currentOperation?.joinCode).toBe('0e9d4b7c21')
  })

  it('keeps the server message when the join code of a completed operation cannot be renewed', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new ApiError('This operation is no longer accepting participants', 400))
    const store = useMiningOperationStore()

    await expect(store.renewJoinCode(OPERATION_ID)).rejects.toBeInstanceOf(ApiError)
    expect(store.error).toBe('This operation is no longer accepting participants')
  })

  it('leaves an operation and drops it from my operations', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([ICE_BELT, SPODUMAIN]).mockResolvedValueOnce(undefined)
    const store = useMiningOperationStore()
    await store.fetchMine()

    await store.leave(OPERATION_ID)

    expect(apiRequest).toHaveBeenLastCalledWith(`/mining-operations/${OPERATION_ID}/leave`, expect.objectContaining({ method: 'POST' }))
    expect(store.myOperations).toEqual([ICE_BELT])
  })

  it('deletes an operation and drops it from my operations', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([ICE_BELT, SPODUMAIN]).mockResolvedValueOnce(undefined)
    const store = useMiningOperationStore()
    await store.fetchMine()

    await store.deleteOperation(OPERATION_ID)

    expect(apiRequest).toHaveBeenLastCalledWith(`/mining-operations/${OPERATION_ID}`, { method: 'DELETE' })
    expect(store.myOperations).toEqual([ICE_BELT])
  })

  it('accepts a pending member through a merge patch', async () => {
    vi.mocked(apiRequest).mockResolvedValue(CYRA_MEMBER)
    const store = useMiningOperationStore()

    const updated = await store.updateMember(OPERATION_ID, CYRA_MEMBER_ID, { status: 'accepted' })

    expect(apiRequest).toHaveBeenCalledWith(`/mining-operations/${OPERATION_ID}/members/${CYRA_MEMBER_ID}`, {
      method: 'PATCH',
      body: JSON.stringify({ status: 'accepted' }),
    })
    expect(updated).toEqual(CYRA_MEMBER)
  })

  it('keeps the server message when an admin tries to promote a member', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new ApiError('Only the operation owner can promote members to admin', 403))
    const store = useMiningOperationStore()

    await expect(store.updateMember(OPERATION_ID, CYRA_MEMBER_ID, { role: 'admin' })).rejects.toBeInstanceOf(ApiError)
    expect(store.error).toBe('Only the operation owner can promote members to admin')
  })

  it('removes a member and drops it from the members', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([CYRA_MEMBER]).mockResolvedValueOnce(undefined)
    const store = useMiningOperationStore()
    await store.fetchMembers(OPERATION_ID)

    await store.removeMember(OPERATION_ID, CYRA_MEMBER_ID)

    expect(apiRequest).toHaveBeenLastCalledWith(`/mining-operations/${OPERATION_ID}/members/${CYRA_MEMBER_ID}`, { method: 'DELETE' })
    expect(store.members).toEqual([])
  })
})
