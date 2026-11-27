import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { BROADCAST_NODE, PLANNER_ACCOUNT, PLANNER_CATALOG, PLANNER_MARKET } from '../../planetaryPlannerFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function mountTab() {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
    if (endpoint === '/planetary/templates') return []
    return { id: 'planner', ...PLANNER_CATALOG }
  }) as never)
  const wrapper = mount(PiPlannerTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

describe('PiPlannerPlanets planet mode', () => {
  it('shows one chain row of 5 planets in the auto mode, then the tier rows of 7 planets once the per tier mode is chosen', async () => {
    const wrapper = await mountTab()
    await wrapper.get('[data-testid="planner-add-select"]').setValue(String(BROADCAST_NODE))
    await flushPromises()
    const input = wrapper.get('[data-testid="planner-target-input"]')
    await input.setValue('144')
    await flushPromises()
    const keys = () => wrapper.findAll('[data-testid="planets-row"]').map(row => row.attributes('data-key'))
    const total = () => wrapper.get('[data-testid="planets-total"]').attributes('data-planets')
    const pressed = () => ['auto', 'perTier', 'wholeChain'].map(mode => wrapper.get(`[data-testid="planets-mode"] [data-mode="${mode}"]`).attributes('aria-pressed'))

    expect(pressed()).toEqual(['true', 'false', 'false'])
    expect(keys()).toEqual(['chain'])
    expect(wrapper.get('[data-testid="planets-row"]').attributes('data-role')).toBe('chain')
    expect(wrapper.get('[data-testid="planets-row"] [data-testid="planets-count"]').attributes('data-planets')).toBe('5')
    expect(total()).toBe('5')

    await wrapper.get('[data-testid="planets-mode"] [data-mode="perTier"]').trigger('click')
    await flushPromises()

    expect(pressed()).toEqual(['false', 'true', 'false'])
    expect(keys()).toEqual(['P4', 'P3', 'P2'])
    expect(total()).toBe('7')
  })
})
