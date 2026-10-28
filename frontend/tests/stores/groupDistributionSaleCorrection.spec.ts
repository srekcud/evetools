import { createPinia, setActivePinia } from 'pinia'
import { apiRequest, ValidationError } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupSale, RecordSaleInput } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SABRE_BATCH_ID = 'project-sabre-batch'

const ISHTAR_SALE: GroupSale = {
  id: 'sale-ishtar',
  typeId: 0,
  typeName: 'Ishtar',
  quantity: 5,
  unitPrice: 1_200_000,
  totalPrice: 6_000_000,
  venue: 'Jita IV - Moon 4',
  soldAt: '2026-10-20T18:30:00+00:00',
  recordedByCharacterName: 'Brann Holt',
  createdAt: '2026-10-20T18:31:00+00:00',
}

const SABRE_SALE: GroupSale = {
  id: 'sale-sabre',
  typeId: 0,
  typeName: 'Sabre',
  quantity: 2,
  unitPrice: 900_000,
  totalPrice: 1_800_000,
  venue: 'Amarr VIII (Oris)',
  soldAt: '2026-10-21T16:45:00+00:00',
  recordedByCharacterName: 'Brann Holt',
  createdAt: '2026-10-21T16:50:00+00:00',
}

const HOUND_SALE: GroupSale = {
  id: 'sale-hound',
  typeId: 0,
  typeName: 'Hound',
  quantity: 1,
  unitPrice: 38_000_000,
  totalPrice: 38_000_000,
  venue: null,
  soldAt: '2026-10-22T09:30:00+00:00',
  recordedByCharacterName: null,
  createdAt: '2026-10-22T09:31:00+00:00',
}

const CORRECTED_SABRE_SALE: GroupSale = { ...SABRE_SALE, unitPrice: 950_000, totalPrice: 1_900_000 }

const SALE_DATE_VIOLATION = 'soldAt: This value is not a valid datetime.'

function sabreSalePath(): string {
  return `/group-industry/projects/${SABRE_BATCH_ID}/sales/${SABRE_SALE.id}`
}

function storeHolding(sales: GroupSale[]): ReturnType<typeof useGroupDistributionStore> {
  const store = useGroupDistributionStore()
  store.sales = [...sales]
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group distribution store sales changes', () => {
  it('puts a recorded sale first in the sales list', async () => {
    const store = storeHolding([SABRE_SALE])
    const input: RecordSaleInput = { typeId: 0, typeName: 'Ishtar', quantity: 5, unitPrice: 1_200_000, venue: 'Jita IV - Moon 4' }
    vi.mocked(apiRequest).mockResolvedValueOnce(ISHTAR_SALE)

    await store.recordSale(SABRE_BATCH_ID, input)

    expect(apiRequest).toHaveBeenCalledWith(`/group-industry/projects/${SABRE_BATCH_ID}/sales`, {
      method: 'POST',
      body: JSON.stringify(input),
    })
    expect(store.sales).toEqual([ISHTAR_SALE, SABRE_SALE])
  })

  it('sends the correction of a sale as a PATCH of the changed fields', async () => {
    const store = storeHolding([ISHTAR_SALE, SABRE_SALE])
    vi.mocked(apiRequest).mockResolvedValueOnce(CORRECTED_SABRE_SALE)

    await store.updateSale(SABRE_BATCH_ID, SABRE_SALE.id, { unitPrice: 950_000 })

    expect(apiRequest).toHaveBeenCalledWith(sabreSalePath(), {
      method: 'PATCH',
      body: JSON.stringify({ unitPrice: 950_000 }),
    })
  })

  it('replaces the corrected sale at its place in the sales list', async () => {
    const store = storeHolding([ISHTAR_SALE, SABRE_SALE, HOUND_SALE])
    vi.mocked(apiRequest).mockResolvedValueOnce(CORRECTED_SABRE_SALE)

    const correctedSale = await store.updateSale(SABRE_BATCH_ID, SABRE_SALE.id, { unitPrice: 950_000 })

    expect(correctedSale).toEqual(CORRECTED_SABRE_SALE)
    expect(store.sales).toEqual([ISHTAR_SALE, CORRECTED_SABRE_SALE, HOUND_SALE])
  })

  it('keeps the sales and shows the validation message when a correction is rejected', async () => {
    const store = storeHolding([ISHTAR_SALE, SABRE_SALE])
    vi.mocked(apiRequest).mockRejectedValueOnce(new ValidationError({
      '@type': 'ConstraintViolationList',
      status: 422,
      violations: [{ propertyPath: 'soldAt', message: 'This value is not a valid datetime.' }],
      description: SALE_DATE_VIOLATION,
      detail: SALE_DATE_VIOLATION,
    }))

    await store.updateSale(SABRE_BATCH_ID, SABRE_SALE.id, { soldAt: 'not-a-date' }).catch(() => undefined)

    expect({ sales: store.sales, error: store.error }).toEqual({
      sales: [ISHTAR_SALE, SABRE_SALE],
      error: SALE_DATE_VIOLATION,
    })
  })

  it('sends the deletion of a sale as a DELETE', async () => {
    const store = storeHolding([ISHTAR_SALE, SABRE_SALE])
    vi.mocked(apiRequest).mockResolvedValueOnce(null)

    await store.deleteSale(SABRE_BATCH_ID, SABRE_SALE.id)

    expect(apiRequest).toHaveBeenCalledWith(sabreSalePath(), { method: 'DELETE' })
  })

  it('removes the deleted sale and keeps the other sales in order', async () => {
    const store = storeHolding([ISHTAR_SALE, SABRE_SALE, HOUND_SALE])
    vi.mocked(apiRequest).mockResolvedValueOnce(null)

    await store.deleteSale(SABRE_BATCH_ID, SABRE_SALE.id)

    expect(store.sales).toEqual([ISHTAR_SALE, HOUND_SALE])
  })

  it('keeps the sales and shows the refusal when a deletion fails', async () => {
    const store = storeHolding([ISHTAR_SALE, SABRE_SALE])
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('Cannot delete a sale on a completed project'))

    await store.deleteSale(SABRE_BATCH_ID, SABRE_SALE.id).catch(() => undefined)

    expect({ sales: store.sales, error: store.error }).toEqual({
      sales: [ISHTAR_SALE, SABRE_SALE],
      error: 'Cannot delete a sale on a completed project',
    })
  })
})
