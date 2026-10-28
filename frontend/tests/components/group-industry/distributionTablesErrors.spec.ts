import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Character, User } from '@/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type PendingResponse = {
  resolve: (value: unknown) => void
  reject: (reason: unknown) => void
}

const PROJECT_ID = 'project-sabre-batch'
const DISTRIBUTION_PATH = `/group-industry/projects/${PROJECT_ID}/distribution`
const SALES_PATH = `/group-industry/projects/${PROJECT_ID}/sales`
const MEMBERS_PATH = `/group-industry/projects/${PROJECT_ID}/members`

const DISTRIBUTION_UNAVAILABLE = 'Distribution unavailable: ESI wallet journal timed out'
const SALES_UNAVAILABLE = 'Sales unavailable: database connection lost'
const MEMBERS_UNAVAILABLE = 'Members unavailable: character names could not be read'
const SALE_REJECTED = 'Sale rejected: the project is completed'

let pendingResponses: Record<string, PendingResponse[]> = {}

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

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function ownerOfTheProject(): User {
  const mainCharacter: Character = {
    id: 'character-aria',
    eveCharacterId: 2_112_000_001,
    name: 'Aria Solberg',
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
    allianceId: null,
    allianceName: null,
    isMain: true,
    hasValidToken: true,
    lastSyncAt: null,
  }

  return {
    id: 'user-aria',
    authStatus: 'full',
    mainCharacter,
    characters: [mainCharacter],
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
  }
}

function mountSalesTable(): VueWrapper {
  return mount(SalesTable, {
    props: { projectId: PROJECT_ID, isAdmin: true },
    global: { plugins: [i18n()], stubs: { LoadingSpinner: true, ConfirmModal: true } },
  })
}

function mountMemberTable(): VueWrapper {
  useAuthStore().user = ownerOfTheProject()
  const memberTableInAPage = defineComponent({
    render: () => h('section', [h(MemberTable, { projectId: PROJECT_ID, isAdmin: true, isOwner: true })]),
  })
  return mount(memberTableInAPage, { global: { plugins: [i18n()], stubs: { ConfirmModal: true } } })
}

function mountDistributionTable(): VueWrapper {
  return mount(DistributionTable, { global: { plugins: [i18n()] } })
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

describe('SalesTable errors', () => {
  it('shows both the sales loading error and the error of a sale that failed to be recorded', async () => {
    const wrapper = mountSalesTable()
    await fail(SALES_PATH, SALES_UNAVAILABLE)

    const recording = useGroupDistributionStore().recordSale(PROJECT_ID, { typeId: 22_456, typeName: 'Sabre', quantity: 1, unitPrice: 48_000_000, venue: 'Jita IV - Moon 4' })
    const recorded = expect(recording).rejects.toThrow(SALE_REJECTED)
    await fail(SALES_PATH, SALE_REJECTED)
    await recorded

    expect([wrapper.text().includes(SALES_UNAVAILABLE), wrapper.text().includes(SALE_REJECTED)]).toEqual([true, true])
  })

  it('keeps showing the sales loading error while the members start loading', async () => {
    const wrapper = mountSalesTable()
    await fail(SALES_PATH, SALES_UNAVAILABLE)

    void useGroupDistributionStore().fetchMembers(PROJECT_ID)
    await flushPromises()

    expect(wrapper.text()).toContain(SALES_UNAVAILABLE)
  })

  it('does not show the members loading error', async () => {
    const wrapper = mountSalesTable()
    await answer(SALES_PATH, [])

    void useGroupDistributionStore().fetchMembers(PROJECT_ID)
    await fail(MEMBERS_PATH, MEMBERS_UNAVAILABLE)

    expect(wrapper.text()).not.toContain(MEMBERS_UNAVAILABLE)
  })
})

describe('MemberTable errors', () => {
  it('keeps showing the members loading error while the distribution starts loading', async () => {
    const wrapper = mountMemberTable()
    await fail(MEMBERS_PATH, MEMBERS_UNAVAILABLE)

    void useGroupDistributionStore().fetchDistribution(PROJECT_ID)
    await flushPromises()

    expect(wrapper.text()).toContain(MEMBERS_UNAVAILABLE)
  })

  it('does not show the sales loading error', async () => {
    const wrapper = mountMemberTable()
    await answer(MEMBERS_PATH, [])

    void useGroupDistributionStore().fetchSales(PROJECT_ID)
    await fail(SALES_PATH, SALES_UNAVAILABLE)

    expect(wrapper.text()).not.toContain(SALES_UNAVAILABLE)
  })
})

describe('DistributionTable errors', () => {
  it('shows the distribution loading error', async () => {
    const wrapper = mountDistributionTable()

    void useGroupDistributionStore().fetchDistribution(PROJECT_ID)
    await fail(DISTRIBUTION_PATH, DISTRIBUTION_UNAVAILABLE)

    expect(wrapper.text()).toContain(DISTRIBUTION_UNAVAILABLE)
  })

  it('does not show the sales loading error', async () => {
    const wrapper = mountDistributionTable()
    const store = useGroupDistributionStore()
    void store.fetchDistribution(PROJECT_ID)
    void store.fetchSales(PROJECT_ID)

    await fail(SALES_PATH, SALES_UNAVAILABLE)
    await fail(DISTRIBUTION_PATH, DISTRIBUTION_UNAVAILABLE)

    expect([wrapper.text().includes(DISTRIBUTION_UNAVAILABLE), wrapper.text().includes(SALES_UNAVAILABLE)]).toEqual([true, false])
  })
})
