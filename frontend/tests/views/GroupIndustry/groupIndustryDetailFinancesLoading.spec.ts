import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import {
  callsTo,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NYX_DISTRIBUTION,
  nyxProject,
  PROJECT_PATH,
  route,
  sectionToggleSelector,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DISTRIBUTION_PATH = `${PROJECT_PATH}/distribution`
const SALES_PATH = `${PROJECT_PATH}/sales`
const SETTLEMENT_PATH = `${PROJECT_PATH}/settlement`
const LOADING = '[data-testid="distribution-loading"]'
const FORECAST_CARD = 'sell-forecast-card-stub'

const syncEvent = ref<GroupProjectEvent | null>(null)

function publish(action: string): void {
  syncEvent.value = { action, data: null, timestamp: '2026-11-21T09:00:00+00:00' }
}

function financesRequests(): Record<string, number> {
  return { distribution: callsTo(DISTRIBUTION_PATH), sales: callsTo(SALES_PATH), settlement: callsTo(SETTLEMENT_PATH) }
}

async function mountFinances(tab: string | undefined, distribution?: unknown) {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('selling', 'owner', { membersCount: 3 }),
    cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })),
    distribution,
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  syncEvent.value = null
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

describe('Finances section loaded on a reload or a direct link', () => {
  it('loads the distribution and the settlement once, and reads the sales, when the view opens on the distribution section', async () => {
    await mountFinances('distribution')

    const requests = financesRequests()
    expect({ distribution: requests.distribution, settlement: requests.settlement }).toEqual({ distribution: 1, settlement: 1 })
    expect(requests.sales).toBeGreaterThanOrEqual(1)
  })

  it('loads the settlement and reads the distribution again when the distribution section is opened, the distribution and the sales being already read for the ISK band and the flow grid', async () => {
    const wrapper = await mountFinances(undefined)
    expect(financesRequests()).toEqual({ distribution: 1, sales: 1, settlement: 0 })

    await wrapper.get(sectionToggleSelector('distribution')).trigger('click')
    await flushPromises()

    const requests = financesRequests()
    expect({ distribution: requests.distribution, settlement: requests.settlement }).toEqual({ distribution: 2, settlement: 1 })
    expect(requests.sales).toBeGreaterThanOrEqual(1)
  })

  it('reads the distribution for the ISK band but not the settlement when the view opens on the bom section', async () => {
    await mountFinances('bom')

    const requests = financesRequests()
    expect({ distribution: requests.distribution, settlement: requests.settlement }).toEqual({ distribution: 1, settlement: 0 })
  })

  it('shows only a loading state while the distribution is being read, then the Finances content', async () => {
    let answerDistribution: (distribution: unknown) => void = () => undefined
    const pending = new Promise(resolve => { answerDistribution = resolve })
    const wrapper = await mountFinances('distribution', pending)
    const whileReading = [wrapper.find(LOADING).exists(), wrapper.find(FORECAST_CARD).exists()]

    answerDistribution(NYX_DISTRIBUTION)
    await flushPromises()

    expect({ whileReading, once: [wrapper.find(LOADING).exists(), wrapper.find(FORECAST_CARD).exists()] }).toEqual({
      whileReading: [true, false],
      once: [false, true],
    })
  })

  it('shows the Finances content and no loading state when the distribution cannot be read', async () => {
    const wrapper = await mountFinances('distribution', new Error('Distribution unavailable'))

    expect([wrapper.find(LOADING).exists(), wrapper.find(FORECAST_CARD).exists()]).toEqual([false, true])
  })

  it('shows no loading state once the distribution is read', async () => {
    const wrapper = await mountFinances('distribution')

    expect(wrapper.find(LOADING).exists()).toBe(false)
  })
})

describe('Finances section refreshed by a sale recorded by another manager', () => {
  it('reads the sales again together with the distribution while the Finances section is open', async () => {
    await mountFinances('distribution')
    const before = financesRequests()

    publish('sale_recorded')
    await flushPromises()

    expect(callsTo(DISTRIBUTION_PATH) - before.distribution).toBe(1)
    expect(callsTo(SALES_PATH) - before.sales).toBeGreaterThanOrEqual(1)
  })
})
