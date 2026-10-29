import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, GroupMember, GroupSale, LineRentalRates } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type GroupDistributionStore = ReturnType<typeof useGroupDistributionStore>
type ProjectFetch = 'fetchDistribution' | 'fetchSales' | 'fetchMembers'

type PendingResponse = {
  resolve: (value: unknown) => void
  reject: (reason: unknown) => void
}

const SABRE_BATCH_ID = 'project-sabre-batch'
const HECATE_BATCH_ID = 'project-hecate-batch'
const LINE_RENTAL_RATES_PATH = '/group-industry/line-rental-rates'

const SABRE_BATCH_SALES: GroupSale[] = [
  {
    id: 'sale-ishtar',
    typeId: 0,
    typeName: 'Ishtar',
    quantity: 5,
    unitPrice: 1_200_000,
    totalPrice: 6_000_000,
    venue: 'Jita IV - Moon 4',
    soldAt: '2026-10-20T18:30:00+00:00',
    recordedByCharacterName: 'Brann Holt',
    sellerCharacterId: null,
    sellerCharacterName: null,
    createdAt: '2026-10-20T18:31:00+00:00',
  },
  {
    id: 'sale-sabre',
    typeId: 0,
    typeName: 'Sabre',
    quantity: 2,
    unitPrice: 900_000,
    totalPrice: 1_800_000,
    venue: 'Amarr VIII (Oris)',
    soldAt: '2026-10-21T16:45:00+00:00',
    recordedByCharacterName: 'Brann Holt',
    sellerCharacterId: null,
    sellerCharacterName: null,
    createdAt: '2026-10-21T16:50:00+00:00',
  },
]

const HECATE_BATCH_SALES: GroupSale[] = [
  {
    id: 'sale-hecate',
    typeId: 0,
    typeName: 'Hecate',
    quantity: 3,
    unitPrice: 41_500_000,
    totalPrice: 124_500_000,
    venue: null,
    soldAt: '2026-10-22T09:30:00+00:00',
    recordedByCharacterName: 'Cyra Lune',
    sellerCharacterId: null,
    sellerCharacterName: null,
    createdAt: '2026-10-22T09:31:00+00:00',
  },
]

const SABRE_BATCH_DISTRIBUTION: Distribution = {
  id: SABRE_BATCH_ID,
  totalRevenue: 7_800_000,
  brokerFee: 234_000,
  salesTax: 312_000,
  netRevenue: 7_254_000,
  totalProjectCost: 5_000_000,
  marginPercent: 45.08,
  members: [],
}

const HECATE_BATCH_DISTRIBUTION: Distribution = {
  id: HECATE_BATCH_ID,
  totalRevenue: 124_500_000,
  brokerFee: 3_735_000,
  salesTax: 4_980_000,
  netRevenue: 115_785_000,
  totalProjectCost: 90_000_000,
  marginPercent: 28.65,
  members: [],
}

function acceptedMember(id: string, characterName: string, totalContributionValue: number): GroupMember {
  return {
    id,
    characterName,
    characterId: null,
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
    role: 'member',
    status: 'accepted',
    totalContributionValue,
    contributionCount: 2,
    joinedAt: '2026-10-19T12:00:00+00:00',
  }
}

const SABRE_BATCH_MEMBERS: GroupMember[] = [acceptedMember('member-brann', 'Brann Holt', 3_000_000)]
const HECATE_BATCH_MEMBERS: GroupMember[] = [acceptedMember('member-cyra', 'Cyra Lune', 90_000_000)]

const LINE_RENTAL_RATES: LineRentalRates = {
  id: 'line-rental-rates',
  rates: { manufacturing: 1_250_000, reaction: 2_400_000 },
}

const NOTHING_HELD = { distribution: null, sales: [], members: [], lineRentalRates: null }

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

async function fail(endpoint: string, reason: Error): Promise<void> {
  nextPendingResponse(endpoint).reject(reason)
  await flushPromises()
}

function heldData(store: GroupDistributionStore) {
  return {
    distribution: store.distribution,
    sales: store.sales,
    members: store.members,
    lineRentalRates: store.lineRentalRates,
  }
}

function loadingIndicators(store: GroupDistributionStore) {
  return {
    loading: store.loading,
    loadingDistribution: store.loadingDistribution,
    loadingSales: store.loadingSales,
    loadingMembers: store.loadingMembers,
  }
}

async function storeHoldingTheSabreBatch(): Promise<GroupDistributionStore> {
  const store = useGroupDistributionStore()
  void store.fetchDistribution(SABRE_BATCH_ID)
  void store.fetchSales(SABRE_BATCH_ID)
  void store.fetchMembers(SABRE_BATCH_ID)
  void store.fetchLineRentalRates()
  await answer(distributionPath(SABRE_BATCH_ID), SABRE_BATCH_DISTRIBUTION)
  await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)
  await answer(membersPath(SABRE_BATCH_ID), SABRE_BATCH_MEMBERS)
  await answer(LINE_RENTAL_RATES_PATH, LINE_RENTAL_RATES)
  expect(heldData(store)).toEqual({
    distribution: SABRE_BATCH_DISTRIBUTION,
    sales: SABRE_BATCH_SALES,
    members: SABRE_BATCH_MEMBERS,
    lineRentalRates: LINE_RENTAL_RATES,
  })
  return store
}

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

const PROJECT_FETCHES: ProjectFetch[] = ['fetchDistribution', 'fetchSales', 'fetchMembers']

describe('group distribution store when another project is opened', () => {
  it.each(PROJECT_FETCHES)('holds nothing of the previous project while %s loads another project', async (projectFetch) => {
    const store = await storeHoldingTheSabreBatch()

    void store[projectFetch](HECATE_BATCH_ID)

    expect(heldData(store)).toEqual(NOTHING_HELD)
  })

  it.each(PROJECT_FETCHES)('keeps the data of the project while %s reloads the same project', async (projectFetch) => {
    const store = await storeHoldingTheSabreBatch()

    void store[projectFetch](SABRE_BATCH_ID)

    expect(heldData(store)).toEqual({
      distribution: SABRE_BATCH_DISTRIBUTION,
      sales: SABRE_BATCH_SALES,
      members: SABRE_BATCH_MEMBERS,
      lineRentalRates: LINE_RENTAL_RATES,
    })
  })

  it('holds the sales of the opened project once they are loaded', async () => {
    const store = await storeHoldingTheSabreBatch()

    void store.fetchSales(HECATE_BATCH_ID)
    await answer(salesPath(HECATE_BATCH_ID), HECATE_BATCH_SALES)

    expect(store.sales).toEqual(HECATE_BATCH_SALES)
  })
})

describe('group distribution store when the response for the previous project arrives late', () => {
  it('keeps the distribution of the opened project', async () => {
    const store = useGroupDistributionStore()
    void store.fetchDistribution(SABRE_BATCH_ID)
    void store.fetchDistribution(HECATE_BATCH_ID)
    await answer(distributionPath(HECATE_BATCH_ID), HECATE_BATCH_DISTRIBUTION)

    await answer(distributionPath(SABRE_BATCH_ID), SABRE_BATCH_DISTRIBUTION)

    expect(store.distribution).toEqual(HECATE_BATCH_DISTRIBUTION)
  })

  it('keeps the sales of the opened project', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchSales(HECATE_BATCH_ID)
    await answer(salesPath(HECATE_BATCH_ID), HECATE_BATCH_SALES)

    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)

    expect(store.sales).toEqual(HECATE_BATCH_SALES)
  })

  it('keeps the members of the opened project', async () => {
    const store = useGroupDistributionStore()
    void store.fetchMembers(SABRE_BATCH_ID)
    void store.fetchMembers(HECATE_BATCH_ID)
    await answer(membersPath(HECATE_BATCH_ID), HECATE_BATCH_MEMBERS)

    await answer(membersPath(SABRE_BATCH_ID), SABRE_BATCH_MEMBERS)

    expect(store.members).toEqual(HECATE_BATCH_MEMBERS)
  })

  it('shows no sales of the previous project once only the distribution of another project was requested', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchDistribution(HECATE_BATCH_ID)

    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)

    expect(store.sales).toEqual([])
  })
})

describe('group distribution store loading indicators', () => {
  it('reports loading until the last of the distribution, sales and members fetches finishes', async () => {
    const store = useGroupDistributionStore()
    void store.fetchDistribution(SABRE_BATCH_ID)
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchMembers(SABRE_BATCH_ID)
    const indicators = [loadingIndicators(store)]

    await answer(distributionPath(SABRE_BATCH_ID), SABRE_BATCH_DISTRIBUTION)
    indicators.push(loadingIndicators(store))
    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)
    indicators.push(loadingIndicators(store))
    await answer(membersPath(SABRE_BATCH_ID), SABRE_BATCH_MEMBERS)
    indicators.push(loadingIndicators(store))

    expect(indicators).toEqual([
      { loading: true, loadingDistribution: true, loadingSales: true, loadingMembers: true },
      { loading: true, loadingDistribution: false, loadingSales: true, loadingMembers: true },
      { loading: true, loadingDistribution: false, loadingSales: false, loadingMembers: true },
      { loading: false, loadingDistribution: false, loadingSales: false, loadingMembers: false },
    ])
  })

  it('stops reporting the loading of a failed fetch while the other fetches go on', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchMembers(SABRE_BATCH_ID)

    await fail(salesPath(SABRE_BATCH_ID), new Error('Failed to fetch sales'))

    expect(loadingIndicators(store)).toEqual({
      loading: true,
      loadingDistribution: false,
      loadingSales: false,
      loadingMembers: true,
    })
  })

  it('keeps reporting the sales loading of the opened project when the response for the previous project arrives', async () => {
    const store = useGroupDistributionStore()
    void store.fetchSales(SABRE_BATCH_ID)
    void store.fetchSales(HECATE_BATCH_ID)

    await answer(salesPath(SABRE_BATCH_ID), SABRE_BATCH_SALES)

    expect({ loading: store.loading, loadingSales: store.loadingSales, sales: store.sales }).toEqual({
      loading: true,
      loadingSales: true,
      sales: [],
    })
  })
})
