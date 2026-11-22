import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FavoriteSystemsConfig from '@/components/industry/FavoriteSystemsConfig.vue'
import { apiRequest } from '@/services/api'
import type { UserSettings } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SETTINGS_URL = '/industry/settings'
const SEARCH_DEBOUNCE_MS = 300
const AMARR_SOLAR_SYSTEM_ID = 30_002_187

const STORED_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  exportCostPerM3: 1200,
  courierHubSolarSystemId: 30_000_772,
  courierHubSolarSystemName: 'C-J6MT',
  jumpBridgeCostPerM3: 50,
}

const AMARR_RESULT = { solarSystemId: AMARR_SOLAR_SYSTEM_ID, solarSystemName: 'Amarr', security: 1, regionName: 'Domain' }

let savedBodies: unknown[] = []

async function mountFavoriteSystems(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (url: string, options?: RequestInit) => {
    if (url === SETTINGS_URL && options?.method === 'PATCH') {
      savedBodies.push(JSON.parse(String(options.body)))
      return { ...STORED_SETTINGS, courierHubSolarSystemId: AMARR_SOLAR_SYSTEM_ID, courierHubSolarSystemName: 'Amarr' }
    }
    if (url === SETTINGS_URL) return { ...STORED_SETTINGS }
    if (url === '/sde/solar-systems?q=amar') return [AMARR_RESULT]
    throw new Error(`Unexpected request ${url}`)
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(FavoriteSystemsConfig, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  savedBodies = []
})

afterEach(() => {
  vi.useRealTimers()
})

function currentHubText(wrapper: VueWrapper): string | null {
  const current = wrapper.find('[data-testid="courier-hub-current"]')
  return current.exists() ? current.text() : null
}

describe('FavoriteSystemsConfig courier hub', () => {
  it('shows the stored courier hub by name next to the favorite systems', async () => {
    const wrapper = await mountFavoriteSystems()

    expect(String(currentHubText(wrapper))).toContain('C-J6MT')
  })

  it('searches the solar systems from the hub selector and sends only the chosen hub in the PATCH body', async () => {
    const wrapper = await mountFavoriteSystems()
    const search = wrapper.find('[data-testid="courier-hub-search"]')
    expect(search.exists()).toBe(true)

    if (search.exists()) await search.setValue('amar')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    await flushPromises()
    const result = wrapper.find(`[data-testid="courier-hub-result-${AMARR_SOLAR_SYSTEM_ID}"]`)
    expect(result.exists()).toBe(true)
    if (result.exists()) await result.trigger('mousedown')
    await flushPromises()

    expect(savedBodies).toEqual([{ courierHubSolarSystemId: AMARR_SOLAR_SYSTEM_ID }])
    expect(String(currentHubText(wrapper))).toContain('Amarr')
  })
})
