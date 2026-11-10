import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationList from '@/views/MiningOperation/MiningOperationList.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useMiningCompanionStore } from '@/stores/miningCompanion'
import type { User } from '@/types'
import { CHUNK_A, CHUNK_B, CHUNK_C_EXPLODED, EXTRACTIONS_PATH, FREE_NOW, corporationsOf } from '../../components/mining-site/companionFreeFixtures'
import { response } from '../../components/ledger/moonChunksHarness'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: {}, query: {} })
const { router } = vi.hoisted(() => ({ router: { push: vi.fn(), replace: vi.fn() } }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => router) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ARIA_OF_C1 = {
  id: '00000000-0000-4000-8000-000000000304',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as User

async function mountList(): Promise<VueWrapper> {
  useAuthStore().user = ARIA_OF_C1
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(MiningOperationList, {
    global: {
      plugins: [i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  await flushPromises()
  return wrapper
}

function extractionRequests(): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === EXTRACTIONS_PATH).length
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(FREE_NOW)
  setActivePinia(createPinia())
  router.push.mockReset()
  router.replace.mockReset()
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === EXTRACTIONS_PATH) return response(...corporationsOf([CHUNK_A, CHUNK_C_EXPLODED], [CHUNK_B])) as never
    return [] as never
  })
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningOperationList companion', () => {
  it('detaches the companion on the free target from the header button and loads no extraction without chunk in the url', async () => {
    const companion = useMiningCompanionStore()
    const detach = vi.spyOn(companion, 'detach').mockResolvedValue(undefined)
    const wrapper = await mountList()

    const button = wrapper.find('[data-testid="operation-list-companion"]')
    expect(button.exists(), 'companion button').toBe(true)
    await button.trigger('click')

    expect({ detached: detach.mock.calls, extractionRequests: extractionRequests(), replaced: router.replace.mock.calls }).toEqual({
      detached: [[{ siteId: null, operationId: null }]],
      extractionRequests: 0,
      replaced: [],
    })
  })
})
