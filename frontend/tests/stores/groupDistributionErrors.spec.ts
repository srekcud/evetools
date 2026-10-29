import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, GroupMember, GroupSale } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type GroupDistributionStore = ReturnType<typeof useGroupDistributionStore>

type PendingResponse = {
  resolve: (value: unknown) => void
  reject: (reason: unknown) => void
}

const SABRE_BATCH_ID = 'project-sabre-batch'
const HECATE_BATCH_ID = 'project-hecate-batch'

const DISTRIBUTION_UNAVAILABLE = 'Distribution unavailable: ESI wallet journal timed out'
const SALES_UNAVAILABLE = 'Sales unavailable: database connection lost'
const MEMBERS_UNAVAILABLE = 'Members unavailable: character names could not be read'
const SALE_REJECTED = 'Sale rejected: the project is completed'

const SABRE_BATCH_SALES: GroupSale[] = [
  {
    id: 'sale-sabre',
    typeId: 22_456,
    typeName: 'Sabre',
    quantity: 2,
    unitPrice: 48_500_000,
    totalPrice: 97_000_000,
    venue: 'Amarr VIII (Oris)',
    soldAt: '2026-10-21T16:45:00+00:00',
    recordedByCharacterName: 'Brann Holt',
    sellerCharacterId: null,
    sellerCharacterName: null,
    createdAt: '2026-10-21T16:50:00+00:00',
  },
]

const SABRE_BATCH_DISTRIBUTION: Distribution = {
  id: SABRE_BATCH_ID,
  totalRevenue: 97_000_000,
  brokerFee: 2_910_000,
  salesTax: 3_492_000,
  netRevenue: 90_598_000,
  totalProjectCost: 70_000_000,
  marginPercent: 29.43,
  members: [],
}

const SABRE_BATCH_MEMBERS: GroupMember[] = [
  {
    id: 'member-brann',
    characterName: 'Brann Holt',
    characterId: 2_112_000_002,
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
    role: 'admin',
    status: 'accepted',
    totalContributionValue: 35_000_000,
    contributionCount: 3,
    joinedAt: '2026-10-19T12:00:00+00:00',
  },
]

let pendingResponses: Record<string, PendingResponse[]> = {}

function distributionPath(projectId: string): string {
  return `/group-industry/projects/${projectId}/distribution`
}

function salesPath(projectId: string): string {
  return `/group-industry/projects/${projectId}/sales`
}

function membersPath(projectId: string): string {
  return `/group-industry/projects/${projectId}/members`
}

function nextPendingResponse(endpoint: string): PendingResponse {
  const pending = pendingResponses[endpoint]?.shift()
  if (pending === undefined) {
    throw new Error(`No pending request on ${endpoint}`)
  }
  return pending
}

async function answer(endpoint: string, value: unknown): Promise<void> {
  nextPendingResponse(endpoint).resolve(value)
  await flushPromises()
}

async function fail(endpoint: string, message: string): Promise<void> {
  nextPendingResponse(endpoint).reject(new Error(message))
  await flushPromises()
}

function errors(store: GroupDistributionStore) {
  return {
    error: store.error,
    distributionError: store.distributionError,
    salesError: store.salesError,
    membersError: store.membersError,
  }
}

const NO_ERROR = { error: null, distributionError: null, salesError: null, membersError: null }

beforeEach(() => {
  setActivePinia(createPinia())
  pendingResponses = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => new Promise((resolve, reject) => {
    const pending = pendingResponses[endpoint] ?? []
    pending.push({ resolve, reject })
    pendingResponses[endpoint] = pending
  }))
})

describe('group distribution store errors of the distribution, sales and members fetches', () => {
  it('reports each failed fetch in its own error and none as the error of an action', async () => {
    const store = useGroupDistributionStore()
    void store.fetchDistribution(SABRE_BATCH_ID)
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchMembers(SABRE_BATCH_ID)

    await fail(distributionPath(SABRE_BATCH_ID), DISTRIBUTION_UNAVAILABLE)
    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)
    await fail(membersPath(SABRE_BATCH_ID), MEMBERS_UNAVAILABLE)

    expect(errors(store)).toEqual({
      error: null,
      distributionError: DISTRIBUTION_UNAVAILABLE,
      salesError: SALES_UNAVAILABLE,
      membersError: MEMBERS_UNAVAILABLE,
    })
  })

  it('keeps the sales error when the distribution of the project starts loading', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    void store.fetchDistribution(SABRE_BATCH_ID)

    expect(errors(store)).toEqual({ ...NO_ERROR, salesError: SALES_UNAVAILABLE })
  })

  it('keeps the members error when the sales of the project start loading', async () => {
    const store = useGroupDistributionStore()
    void store.fetchMembers(SABRE_BATCH_ID)
    await fail(membersPath(SABRE_BATCH_ID), MEMBERS_UNAVAILABLE)

    void store.fetchSales(SABRE_BATCH_ID)

    expect(errors(store)).toEqual({ ...NO_ERROR, membersError: MEMBERS_UNAVAILABLE })
  })

  it('keeps the distribution error when the members of the project load successfully', async () => {
    const store = useGroupDistributionStore()
    void store.fetchDistribution(SABRE_BATCH_ID)
    await fail(distributionPath(SABRE_BATCH_ID), DISTRIBUTION_UNAVAILABLE)

    void store.fetchMembers(SABRE_BATCH_ID)
    await answer(membersPath(SABRE_BATCH_ID), SABRE_BATCH_MEMBERS)

    expect(errors(store)).toEqual({ ...NO_ERROR, distributionError: DISTRIBUTION_UNAVAILABLE })
  })

  it('clears the sales error once the sales of the project load successfully', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    void store.fetchSales(SABRE_BATCH_ID)
    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)

    expect({ ...errors(store), sales: store.sales }).toEqual({ ...NO_ERROR, sales: SABRE_BATCH_SALES })
  })

  it('clears the errors of the previous project when another project is opened', async () => {
    const store = useGroupDistributionStore()
    void store.fetchDistribution(SABRE_BATCH_ID)
    void store.fetchMembers(SABRE_BATCH_ID)
    await fail(distributionPath(SABRE_BATCH_ID), DISTRIBUTION_UNAVAILABLE)
    await fail(membersPath(SABRE_BATCH_ID), MEMBERS_UNAVAILABLE)

    void store.fetchSales(HECATE_BATCH_ID)

    expect(errors(store)).toEqual(NO_ERROR)
  })

  it('ignores the failure of a sales fetch for the previous project', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchSales(HECATE_BATCH_ID)

    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    expect({ ...errors(store), loadingSales: store.loadingSales }).toEqual({ ...NO_ERROR, loadingSales: true })
  })
})

describe('group distribution store sales after a failed sales fetch', () => {
  it('holds no sales when the sales of the project fail to load again', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)

    void store.fetchSales(SABRE_BATCH_ID)
    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    expect({ sales: store.sales, salesError: store.salesError }).toEqual({ sales: [], salesError: SALES_UNAVAILABLE })
  })

  it('holds no sales when the first sales fetch of the project fails', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)

    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    expect(store.sales).toEqual([])
  })

  it('keeps the distribution and the members of the project when only the sales fail to load again', async () => {
    const store = useGroupDistributionStore()
    void store.fetchDistribution(SABRE_BATCH_ID)
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchMembers(SABRE_BATCH_ID)
    await answer(distributionPath(SABRE_BATCH_ID), SABRE_BATCH_DISTRIBUTION)
    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)
    await answer(membersPath(SABRE_BATCH_ID), SABRE_BATCH_MEMBERS)

    void store.fetchSales(SABRE_BATCH_ID)
    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    expect({ distribution: store.distribution, sales: store.sales, members: store.members }).toEqual({
      distribution: SABRE_BATCH_DISTRIBUTION,
      sales: [],
      members: SABRE_BATCH_MEMBERS,
    })
  })
})

describe('group distribution store error of an action', () => {
  it('keeps the error of a failed sale while the sales of the project are reloaded', async () => {
    const store = useGroupDistributionStore()
    const recording = store.recordSale(SABRE_BATCH_ID, { typeId: 22_456, typeName: 'Sabre', quantity: 1, unitPrice: 48_000_000, venue: 'Jita IV - Moon 4' })
    const recorded = expect(recording).rejects.toThrow(SALE_REJECTED)
    await fail(salesPath(SABRE_BATCH_ID), SALE_REJECTED)
    await recorded

    void store.fetchSales(SABRE_BATCH_ID)

    expect(errors(store)).toEqual({ ...NO_ERROR, error: SALE_REJECTED })
  })

  it('keeps the sales loading error when a sale fails to be recorded', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    await fail(salesPath(SABRE_BATCH_ID), SALES_UNAVAILABLE)

    const recording = store.recordSale(SABRE_BATCH_ID, { typeId: 22_456, typeName: 'Sabre', quantity: 1, unitPrice: 48_000_000, venue: 'Jita IV - Moon 4' })
    const recorded = expect(recording).rejects.toThrow(SALE_REJECTED)
    await fail(salesPath(SABRE_BATCH_ID), SALE_REJECTED)
    await recorded

    expect(errors(store)).toEqual({ ...NO_ERROR, error: SALE_REJECTED, salesError: SALES_UNAVAILABLE })
  })
})
