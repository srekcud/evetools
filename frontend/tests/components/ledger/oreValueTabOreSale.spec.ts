import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import OreSalePanel from '@/components/ledger/OreSalePanel.vue'
import OreValueTab from '@/components/ledger/OreValueTab.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { i18nPlugin } from './miningLedgerHarness'
import { PRICED_SALE } from './oreSaleHarness'
import { NPC_STATION_PROFILE, PILOT_ONE } from './reprocessingProfileHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function respond(endpoint: string): Promise<unknown> {
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
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = {
      id: 'user-a',
      authStatus: 'valid',
      mainCharacter: PILOT_ONE,
      characters: [PILOT_ONE],
      corporationId: 98000001,
      corporationName: 'Deep Core Mining Inc.',
    }
    const wrapper = mount(OreValueTab, {
      props: { structureId: null },
      global: { plugins: [i18nPlugin(), pinia] },
    })
    await flushPromises()

    expect(wrapper.findComponent(OreSalePanel).exists()).toBe(true)
    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toContain('/mining/ore-sale')
  })
})
