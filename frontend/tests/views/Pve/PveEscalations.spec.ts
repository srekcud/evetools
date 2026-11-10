import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import EscalationsTab from '@/components/ledger/EscalationsTab.vue'
import { apiRequest } from '@/services/api'
import PveEscalations from '@/views/Pve/PveEscalations.vue'
import { currentTabTargets, mountPveModulePage, periodOfPveTab } from './pveModuleHarness'

const route = reactive<{ path: string; params: Record<string, string>; query: Record<string, string> }>({ path: '/pve/escalations', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockReturnValue(new Promise(() => {}) as never)
})

describe('PveEscalations', () => {
  it('opens the escalations tab on the escalations list, with no period selector and no ledger request', async () => {
    const wrapper = await mountPveModulePage(PveEscalations)

    expect({
      currentTab: currentTabTargets(wrapper),
      escalationsList: wrapper.findComponent(EscalationsTab).exists(),
      revenueTabPeriod: periodOfPveTab(wrapper),
      periodButtons: wrapper.findAll('button').map(button => button.text()).filter(text => text === 'YTD' || text === '30 days'),
      ledgerRequests: vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint.startsWith('/ledger')),
    }).toEqual({
      currentTab: [{ name: 'pve-escalations' }],
      escalationsList: true,
      revenueTabPeriod: null,
      periodButtons: [],
      ledgerRequests: [],
    })
  })
})
