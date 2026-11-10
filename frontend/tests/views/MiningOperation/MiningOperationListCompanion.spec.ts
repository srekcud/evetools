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

function modalState(wrapper: VueWrapper): { system: string | null; label: string | null } {
  const system = wrapper.find('[data-chunk-system]')
  const label = wrapper.find('input[type="text"][maxlength="100"]')
  return { system: system.exists() ? system.text() : null, label: label.exists() ? (label.element as HTMLInputElement).value : null }
}

function roleOfUnavailableMessage(wrapper: VueWrapper): string | null {
  const message = wrapper.find('[data-testid="operation-list-chunk-unavailable"]')
  return message.exists() ? (message.attributes('role') ?? null) : null
}

function replacedQueries(): unknown[] {
  return router.replace.mock.calls.map(call => (call[0] as { query?: Record<string, string> }).query)
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

  it('opens the operation creation on the chunk of the url once found and not exploded, then removes the chunk from the url', async () => {
    route.query = { chunk: CHUNK_B.id, period: '30d' }
    const wrapper = await mountList()

    expect({
      modal: modalState(wrapper),
      unavailable: wrapper.find('[data-testid="operation-list-chunk-unavailable"]').exists(),
      extractionRequests: extractionRequests(),
      replacedQueries: replacedQueries(),
    }).toEqual({
      modal: { system: expect.stringContaining('Jaspet'), label: 'Jaspet V - Moon 1' },
      unavailable: false,
      extractionRequests: 2,
      replacedQueries: [{ period: '30d' }],
    })
  })

  it('opens no modal and says the chunk is unavailable when the chunk of the url is unknown or exploded, and removes it from the url', async () => {
    route.query = { chunk: 'unknown-chunk' }
    const unknown = await mountList()
    const unknownState = {
      modal: modalState(unknown),
      message: roleOfUnavailableMessage(unknown),
      replacedQueries: replacedQueries(),
    }

    unknown.unmount()
    router.replace.mockReset()
    route.query = { chunk: CHUNK_C_EXPLODED.id }
    const exploded = await mountList()

    expect({
      unknownState,
      explodedModal: modalState(exploded),
      explodedMessage: exploded.find('[data-testid="operation-list-chunk-unavailable"]').exists(),
      explodedReplacedQueries: replacedQueries(),
    }).toEqual({
      unknownState: { modal: { system: null, label: null }, message: 'status', replacedQueries: [{}] },
      explodedModal: { system: null, label: null },
      explodedMessage: true,
      explodedReplacedQueries: [{}],
    })
  })
})
