import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import type { Colony } from '@/stores/planetary'
import { apiRequest } from '@/services/api'

const route = reactive<{ path: string; params: Record<string, string>; query: LocationQuery }>({ path: '/planetary', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SETTINGS_LINK_SELECTOR = '[data-testid="pi-alert-settings-link"]'

const GAS_COLONY: Colony = {
  id: 'gas',
  characterId: 2112000002,
  characterName: 'Doran Vex',
  planetId: 40009081,
  planetName: 'Perimeter II',
  planetType: 'gas',
  solarSystemId: 30000144,
  solarSystemName: 'Perimeter',
  solarSystemSecurity: 0.9,
  upgradeLevel: 3,
  numPins: 9,
  lastUpdate: '2026-11-25T09:00:00+00:00',
  cachedAt: '2026-11-25T09:05:00+00:00',
  extractorCount: 3,
  factoryCount: 4,
  activeExtractors: 1,
  nearestExpiry: '2026-11-27T09:00:00+00:00',
  factoryProgress: null,
  status: 'expiring',
  pins: [],
  routes: [],
}

async function mountView(locale: 'en' | 'fr') {
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return Promise.resolve([GAS_COLONY])
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const wrapper = mount(PlanetaryInteraction, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        ErrorBanner: true,
        PiAlertsBanner: true,
        PiProductionTable: true,
        PiColonyCard: true,
        PiTierLegend: true,
        PiKpiCards: true,
        PiPlannerTab: true,
        RouterLink: RouterLinkStub,
      },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

describe('PlanetaryInteraction alert settings link on the Colonies tab', () => {
  it('links to the notifications section of the settings', async () => {
    const wrapper = await mountView('en')

    const link = wrapper.findAllComponents(RouterLinkStub).find(candidate => candidate.attributes('data-testid') === 'pi-alert-settings-link')
    expect(link?.props('to')).toEqual({ name: 'settings', params: { section: 'notifications' } })
  })

  it('labels the link in English', async () => {
    const wrapper = await mountView('en')

    expect(wrapper.get(SETTINGS_LINK_SELECTOR).text()).toBe('Alert settings')
  })

  it('labels the link in French', async () => {
    const wrapper = await mountView('fr')

    expect(wrapper.get(SETTINGS_LINK_SELECTOR).text()).toBe('Réglages des alertes')
  })

  it('declares the label in both locales under pi.alerts.settingsLink', () => {
    expect((en.pi as unknown as { alerts: Record<string, string> }).alerts.settingsLink).toBe('Alert settings')
    expect((fr.pi as unknown as { alerts: Record<string, string> }).alerts.settingsLink).toBe('Réglages des alertes')
  })
})
