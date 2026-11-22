import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupSurplusStore } from '@/stores/group-industry/surplus'
import type { ProjectSurplus, ProjectSurplusLine } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const SURPLUS_PATH = `/industry/projects/${PROJECT_ID}/surplus`
const STORAGE_PATH = `/industry/projects/${PROJECT_ID}/surplus-storage`
const STORED_AT = '2026-11-22T09:30:00+00:00'
const API_FAILURE = 'Only project owner or admin can perform this action (fictif)'

const PYERITE: ProjectSurplusLine = {
  lineId: 'line-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  requiredQuantity: 800,
  contributedQuantity: 950,
  surplusQuantity: 150,
  unitPrice: 8,
  surplusValue: 1_200,
  source: 'contribution',
}

const NOT_STORED: ProjectSurplus = { id: PROJECT_ID, totalValue: 1_200, stored: false, storedAt: null, storedBy: null, lines: [PYERITE] }
const STORED: ProjectSurplus = { ...NOT_STORED, stored: true, storedAt: STORED_AT, storedBy: 'Aria Solberg' }

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group surplus store, storing the surplus', () => {
  it('reads whether the surplus is stored then posts the storage and replaces its state with the answer', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(NOT_STORED)
    const store = useGroupSurplusStore()
    await store.fetchSurplus(PROJECT_ID)
    expect([store.stored, store.storedAt, store.storedBy]).toEqual([false, null, null])
    vi.mocked(apiRequest).mockResolvedValueOnce(STORED)

    await store.store(PROJECT_ID)

    expect(apiRequest).toHaveBeenLastCalledWith(STORAGE_PATH, expect.objectContaining({ method: 'POST' }))
    expect([store.stored, store.storedAt, store.storedBy]).toEqual([true, STORED_AT, 'Aria Solberg'])
    expect([store.lines, store.totalValue, store.error]).toEqual([[PYERITE], 1_200, null])
    expect(apiRequest).toHaveBeenNthCalledWith(1, SURPLUS_PATH)
  })

  it('shows the error and keeps the surplus not stored when the storage is refused or the answer carries no boolean stored', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(NOT_STORED)
    const store = useGroupSurplusStore()
    await store.fetchSurplus(PROJECT_ID)

    vi.mocked(apiRequest).mockRejectedValueOnce(new Error(API_FAILURE))
    await store.store(PROJECT_ID)
    expect([store.error, store.stored]).toEqual([API_FAILURE, false])

    const answersWithoutBooleanStored: unknown[] = [
      { id: PROJECT_ID, totalValue: 1_200, storedAt: STORED_AT, storedBy: 'Aria Solberg', lines: [PYERITE] },
      { ...STORED, stored: 'true' },
      { ...STORED, stored: null },
    ]
    const outcomes = []
    for (const answer of answersWithoutBooleanStored) {
      vi.mocked(apiRequest).mockResolvedValueOnce(answer)
      await store.store(PROJECT_ID)
      outcomes.push({ stored: store.stored, storedAt: store.storedAt, explicitError: typeof store.error === 'string' && store.error !== API_FAILURE })
    }

    expect(outcomes).toEqual(Array.from({ length: answersWithoutBooleanStored.length }, () => ({ stored: false, storedAt: null, explicitError: true })))
  })
})
