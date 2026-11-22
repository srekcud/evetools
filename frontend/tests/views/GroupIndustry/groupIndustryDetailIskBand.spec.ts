import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { GROUP_PROJECT_EVENT_COALESCE_MS } from '@/composables/useCoalescedGroupProjectEvents'
import type { Distribution, SellForecast } from '@/stores/group-industry/types'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { stationQuantity, typeQuantities, TRITANIUM } from '../../components/group-industry/projectFlowFixtures'
import {
  bomLine,
  callsTo,
  cockpitOf,
  DISTRIBUTION_PATH,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_ID,
  route,
  SELL_FORECAST_PATH,
} from './projectCockpitFixtures'

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }))
const syncEvent = ref<GroupProjectEvent | null>(null)

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BAND = '[data-testid="isk-band"]'

const DISTRIBUTION: Distribution = {
  id: 'distribution',
  totalRevenue: 0,
  brokerFee: null,
  salesTax: null,
  netRevenue: null,
  totalProjectCost: 2_000_000,
  marginPercent: null,
  awaitingSales: true,
  unsoldProducts: [],
  projectedMarginPercent: null,
  members: [],
}

const FORECAST: SellForecast = {
  projectId: PROJECT_ID,
  sellStructure: null,
  products: [],
  totals: {
    structureNetRevenue: null,
    jitaNetRevenue: 9_000_000,
    structureUnknownProductCount: 0,
    jitaUnknownProductCount: 0,
    remainingCost: 1_000_000,
    remainingCostUnknownLineCount: 0,
    structureProfit: null,
    jitaProfit: 8_000_000,
  },
}

const COCKPIT = cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 }))
const TRITANIUM_ON_SITE = [typeQuantities(TRITANIUM, [stationQuantity({ locationId: 1, stationName: 'Azbel', onSite: 100 })])]
const TRITANIUM_LINE = [bomLine({ id: 'line-34', ...TRITANIUM, estimatedPrice: 5 })]

async function mountBand(overrides: { personalUse?: boolean; sellForecast?: SellForecast | Error; distribution?: unknown } = {}): Promise<VueWrapper> {
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { membersCount: 3, personalUse: overrides.personalUse === true }),
    cockpit: COCKPIT,
    bom: TRITANIUM_LINE,
    stationQuantities: TRITANIUM_ON_SITE,
    distribution: overrides.distribution ?? DISTRIBUTION,
    sellForecast: overrides.sellForecast ?? FORECAST,
  })
}

function figure(wrapper: VueWrapper, key: string): string {
  return wrapper.get(`[data-testid="isk-band-${key}"] [data-testid="isk-band-value"]`).text().replace(/\s/g, ' ')
}

async function publish(action: string): Promise<void> {
  syncEvent.value = { action, data: null, timestamp: '2026-11-22T09:00:00+00:00' }
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  route.query = {}
  syncEvent.value = null
  replace.mockReset()
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('group project detail ISK band', () => {
  it('shows the committed, on site, expected and profit figures above the flow whatever section is open', async () => {
    const wrapper = await mountBand()

    expect(wrapper.find(BAND).exists()).toBe(true)
    expect(figure(wrapper, 'committed')).toContain('2.00 M')
    expect(figure(wrapper, 'onSite')).toBe('500')
    expect(figure(wrapper, 'expected')).toContain('9.00 M')
    expect(figure(wrapper, 'profit')).toContain('6.00 M')
    const band = wrapper.get(BAND).element
    const flow = wrapper.get('[data-testid="project-flow-grid"]').element
    expect(band.compareDocumentPosition(flow) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('reads the distribution and the sell forecast at mount, with the finances section closed', async () => {
    await mountBand()

    expect(callsTo(DISTRIBUTION_PATH)).toBeGreaterThanOrEqual(1)
    expect(callsTo(SELL_FORECAST_PATH)).toBeGreaterThanOrEqual(1)
  })

  it('shows an unknown expected figure and an unknown profit when the forecast cannot be read, never zero', async () => {
    const wrapper = await mountBand({ sellForecast: new Error('forecast down') })

    expect(wrapper.get('[data-testid="isk-band-expected"]').attributes('data-unknown')).toBe('true')
    expect(wrapper.get('[data-testid="isk-band-profit"]').attributes('data-unknown')).toBe('true')
    expect(wrapper.get('[data-testid="isk-band-committed"]').attributes('data-unknown')).toBeUndefined()
  })

  it('shows an unknown committed figure when the distribution cannot be read', async () => {
    const wrapper = await mountBand({ distribution: new Error('distribution down') })

    expect(wrapper.get('[data-testid="isk-band-committed"]').attributes('data-unknown')).toBe('true')
  })

  it('has no sale on a personal use project: no expected figure, no profit, no forecast read', async () => {
    const wrapper = await mountBand({ personalUse: true })

    expect(wrapper.find('[data-testid="isk-band-expected"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="isk-band-profit"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="isk-band-costPerUnit"]').exists()).toBe(true)
    expect(callsTo(SELL_FORECAST_PATH)).toBe(0)
  })

  it('reads the distribution and the forecast again on a sale event with the finances section closed', async () => {
    await mountBand()
    const atMount = { distribution: callsTo(DISTRIBUTION_PATH), forecast: callsTo(SELL_FORECAST_PATH) }

    await publish('sale_recorded')
    await vi.advanceTimersByTimeAsync(GROUP_PROJECT_EVENT_COALESCE_MS)
    await flushPromises()

    expect(callsTo(DISTRIBUTION_PATH)).toBeGreaterThan(atMount.distribution)
    expect(callsTo(SELL_FORECAST_PATH)).toBeGreaterThan(atMount.forecast)
  })
})
