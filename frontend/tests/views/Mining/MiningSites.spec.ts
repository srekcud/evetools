import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import MiningSites from '@/views/Mining/MiningSites.vue'
import { SITES_PATH, SITE_ID, siteAfterThreeScans } from '../MiningSite/miningSiteFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ARIA_OF_C1 = {
  id: '00000000-0000-4000-8000-000000000531',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as User

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningSites', () => {
  it('loads my mining sites and shows their history', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === SITES_PATH) return [siteAfterThreeScans()] as never
      return [] as never
    })
    useAuthStore().user = ARIA_OF_C1

    const wrapper = mount(MiningSites, {
      global: {
        plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
        stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, RouterLink: RouterLinkStub },
      },
    })
    await flushPromises()

    const siteRow = wrapper.find(`[data-testid="site-row-${SITE_ID}"]`)
    expect({
      siteRequests: vi.mocked(apiRequest).mock.calls.filter(call => call[0] === SITES_PATH).length,
      siteRow: siteRow.exists() ? siteRow.text() : null,
    }).toEqual({ siteRequests: 1, siteRow: expect.stringContaining('Belt Aldranette') })
  })
})
