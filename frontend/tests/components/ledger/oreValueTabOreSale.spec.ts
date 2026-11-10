import { enableAutoUnmount } from '@vue/test-utils'
import OreSalePanel from '@/components/ledger/OreSalePanel.vue'
import { apiRequest } from '@/services/api'
import { valuationProfileServer } from './miningValuationProfileHarness'
import { mountOreValueTab } from './oreValueTabHarness'
import { PRICED_SALE } from './oreSaleHarness'
import { NPC_STATION_PROFILE } from './reprocessingProfileHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const valuationProfile = valuationProfileServer()

function respond(endpoint: string, options?: RequestInit): Promise<unknown> {
  return valuationProfile.respondingWith(respondToOreValueTab)(endpoint, options)
}

function respondToOreValueTab(endpoint: string): Promise<unknown> {
  if (endpoint.startsWith('/mining/ore-sale')) return Promise.resolve(PRICED_SALE)
  if (endpoint === '/mining/reprocessing-profile') return Promise.resolve(NPC_STATION_PROFILE)
  return new Promise(() => {})
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(respond)
})

describe('OreValueTab ore sale helper', () => {
  it('shows the panel to sell the held compressed ore', async () => {
    const wrapper = await mountOreValueTab()

    expect(wrapper.findComponent(OreSalePanel).exists()).toBe(true)
    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toContain('/mining/ore-sale')
  })
})
