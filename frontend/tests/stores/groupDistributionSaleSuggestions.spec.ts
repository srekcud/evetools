import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupSale, GroupSaleSuggestion } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE_PROJECT_ID = 'project-sabre'
const HECATE_PROJECT_ID = 'project-hecate'
const T1_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e01'
const T7_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e07'
const ALREADY_LINKED = 'This transaction is already linked to a sale.'

const T7_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T7_ID,
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 12,
  unitPrice: 60_000_000,
  totalPrice: 720_000_000,
  date: '2026-10-26T11:45:00+00:00',
  sellerCharacterId: 2_120_000_002,
  sellerCharacterName: 'O-Alt',
  locationId: 60_004_588,
  possibleDuplicateSaleIds: [],
}

const T1_SUGGESTION: GroupSaleSuggestion = {
  transactionId: T1_ID,
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  date: '2026-10-24T21:05:00+00:00',
  sellerCharacterId: 2_120_000_001,
  sellerCharacterName: 'O-Main',
  locationId: 60_003_760,
  possibleDuplicateSaleIds: ['sale-v'],
}

const MANUAL_SALE_V: GroupSale = {
  id: 'sale-v',
  typeId: 0,
  typeName: 'Sabre',
  quantity: 3,
  unitPrice: 61_250_000,
  totalPrice: 183_750_000,
  venue: null,
  soldAt: '2026-10-24T22:00:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: null,
  sellerCharacterName: null,
  linkedToWalletTransaction: false,
  createdAt: '2026-10-24T22:01:00+00:00',
}

const SALE_OF_T7: GroupSale = {
  id: 'sale-t7',
  typeId: 22_456,
  typeName: 'Sabre',
  quantity: 10,
  unitPrice: 60_000_000,
  totalPrice: 600_000_000,
  venue: null,
  soldAt: '2026-10-26T11:45:00+00:00',
  recordedByCharacterName: 'O-Main',
  sellerCharacterId: 2_120_000_002,
  sellerCharacterName: 'O-Alt',
  linkedToWalletTransaction: true,
  createdAt: '2026-10-29T08:00:00+00:00',
}

function suggestionsPath(projectId: string): string {
  return `/group-industry/projects/${projectId}/sale-suggestions`
}

function saleFromWalletTransactionPath(projectId: string): string {
  return `/group-industry/projects/${projectId}/sales/from-wallet-transaction`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group distribution store sale suggestions', () => {
  it('reads the sale suggestions of the project newest first', async () => {
    const store = useGroupDistributionStore()
    vi.mocked(apiRequest).mockResolvedValueOnce([T7_SUGGESTION, T1_SUGGESTION])

    await expect(store.fetchSaleSuggestions(SABRE_PROJECT_ID)).resolves.toBeUndefined()

    expect(apiRequest).toHaveBeenCalledWith(suggestionsPath(SABRE_PROJECT_ID))
    expect({ suggestions: store.saleSuggestions, error: store.saleSuggestionsError }).toEqual({
      suggestions: [T7_SUGGESTION, T1_SUGGESTION],
      error: null,
    })
  })

  it('holds no suggestion but the refusal when the suggestions cannot be read', async () => {
    const store = useGroupDistributionStore()
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('Only project owner or admin can perform this action', 403))

    await expect(store.fetchSaleSuggestions(SABRE_PROJECT_ID)).resolves.toBeUndefined()

    expect({ suggestions: store.saleSuggestions, error: store.saleSuggestionsError, loading: store.loadingSaleSuggestions }).toEqual({
      suggestions: [],
      error: 'Only project owner or admin can perform this action',
      loading: false,
    })
  })

  it('forgets the suggestions of the previous project while those of another project load', async () => {
    const store = useGroupDistributionStore()
    vi.mocked(apiRequest).mockResolvedValueOnce([T7_SUGGESTION, T1_SUGGESTION])
    await store.fetchSaleSuggestions(SABRE_PROJECT_ID)
    vi.mocked(apiRequest).mockReturnValueOnce(new Promise(() => undefined))

    void store.fetchSaleSuggestions(HECATE_PROJECT_ID)

    expect({ suggestions: store.saleSuggestions, loading: store.loadingSaleSuggestions }).toEqual({ suggestions: [], loading: true })
  })
})

describe('group distribution store sale recorded from a wallet transaction', () => {
  it('posts the transaction and the entered quantity and puts the recorded sale first', async () => {
    const store = useGroupDistributionStore()
    store.sales = [MANUAL_SALE_V]
    vi.mocked(apiRequest).mockResolvedValueOnce(SALE_OF_T7)

    const recorded = await store.recordSaleFromWalletTransaction(SABRE_PROJECT_ID, { transactionId: T7_ID, quantity: 10 })

    expect(apiRequest).toHaveBeenCalledWith(saleFromWalletTransactionPath(SABRE_PROJECT_ID), {
      method: 'POST',
      body: JSON.stringify({ transactionId: T7_ID, quantity: 10 }),
    })
    expect({ recorded, sales: store.sales }).toEqual({ recorded: SALE_OF_T7, sales: [SALE_OF_T7, MANUAL_SALE_V] })
  })

  it('posts the transaction alone when no quantity is entered', async () => {
    const store = useGroupDistributionStore()
    vi.mocked(apiRequest).mockResolvedValueOnce({ ...SALE_OF_T7, quantity: 12, totalPrice: 720_000_000 })

    await store.recordSaleFromWalletTransaction(SABRE_PROJECT_ID, { transactionId: T7_ID })

    expect(apiRequest).toHaveBeenCalledWith(saleFromWalletTransactionPath(SABRE_PROJECT_ID), {
      method: 'POST',
      body: JSON.stringify({ transactionId: T7_ID }),
    })
  })

  it('keeps the sales and hands the conflict to the caller without the shared error of the sales table', async () => {
    const store = useGroupDistributionStore()
    store.sales = [MANUAL_SALE_V]
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError(ALREADY_LINKED, 409))

    await expect(store.recordSaleFromWalletTransaction(SABRE_PROJECT_ID, { transactionId: T1_ID })).rejects.toThrow(ALREADY_LINKED)

    expect({ sales: store.sales, error: store.error }).toEqual({ sales: [MANUAL_SALE_V], error: null })
  })
})
