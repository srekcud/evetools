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
  tabSelector,
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

describe('Finances tab loaded on a reload or a direct link', () => {
  it('loads the distribution, the sales and the settlement once when the view opens on the distribution tab', async () => {
    await mountFinances('distribution')

    expect(financesRequests()).toEqual({ distribution: 1, sales: 1, settlement: 1 })
  })

  it('loads the sales together with the distribution when the distribution tab is opened', async () => {
    const wrapper = await mountFinances(undefined)
    expect(financesRequests()).toEqual({ distribution: 0, sales: 0, settlement: 0 })

    await wrapper.get(tabSelector('distribution')).trigger('click')
    await flushPromises()

    expect(financesRequests()).toEqual({ distribution: 1, sales: 1, settlement: 1 })
  })

  it('does not load the Finances data when the view opens on the bom tab', async () => {
    await mountFinances('bom')

    expect(financesRequests()).toEqual({ distribution: 0, sales: 0, settlement: 0 })
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

describe('Finances tab refreshed by a sale recorded by another manager', () => {
  it('reads the sales again together with the distribution while the Finances tab is open', async () => {
    await mountFinances('distribution')
    const before = financesRequests()

    publish('sale_recorded')
    await flushPromises()

    expect({
      distribution: callsTo(DISTRIBUTION_PATH) - before.distribution,
      sales: callsTo(SALES_PATH) - before.sales,
    }).toEqual({ distribution: 1, sales: 1 })
  })
})
