import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import MiningMoons from '@/views/Mining/MiningMoons.vue'
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
  id: '00000000-0000-4000-8000-000000000531',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as User

async function mountMoons(): Promise<VueWrapper> {
  useAuthStore().user = ARIA_OF_C1
  const wrapper = mount(MiningMoons, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  await flushPromises()
  return wrapper
}

const ALL_CHUNK_IDS = [CHUNK_A.id, CHUNK_C_EXPLODED.id, CHUNK_B.id].sort()

function modalState(wrapper: VueWrapper): { system: string | null; label: string | null } {
  const system = wrapper.find('[data-chunk-system]')
  const label = wrapper.find('input[type="text"][maxlength="100"]')
  return { system: system.exists() ? system.text() : null, label: label.exists() ? (label.element as HTMLInputElement).value : null }
}

function listedChunkIds(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-extraction-id]').map(row => row.attributes('data-extraction-id') ?? '').sort()
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

describe('MiningMoons', () => {
  it('shows the moon chunks of the corporations by default', async () => {
    const wrapper = await mountMoons()

    expect(wrapper.findComponent(MoonChunksTab).exists()).toBe(true)
    expect(wrapper.find('[data-moon-tax-tab]').exists()).toBe(false)
  })

  it('shows the moon tax screens instead of the chunks on the tax tab', async () => {
    route.query = { tab: 'tax' }
    vi.mocked(apiRequest).mockImplementation(() => new Promise(() => {}))

    const wrapper = await mountMoons()

    expect(wrapper.find('[data-moon-tax-tab]').exists()).toBe(true)
    expect(wrapper.findComponent(MoonChunksTab).exists()).toBe(false)
  })

  it('opens the operation creation on the chunk of the url when it is known and not exploded, and keeps the page without modal when it is unknown or exploded', async () => {
    route.query = { chunk: CHUNK_B.id }
    const known = await mountMoons()
    const knownState = { modal: modalState(known), replacedQueries: router.replace.mock.calls.map(call => (call[0] as { query?: Record<string, string> }).query) }

    known.unmount()
    route.query = { chunk: 'unknown-chunk' }
    const unknown = await mountMoons()
    const unknownState = { modal: modalState(unknown), listedChunks: listedChunkIds(unknown) }

    unknown.unmount()
    route.query = { chunk: CHUNK_C_EXPLODED.id }
    const exploded = await mountMoons()

    expect({ knownState, unknownState, explodedModal: modalState(exploded), explodedListedChunks: listedChunkIds(exploded) }).toEqual({
      knownState: {
        modal: { system: expect.stringContaining('Jaspet'), label: 'Jaspet V - Moon 1' },
        replacedQueries: [{}],
      },
      unknownState: { modal: { system: null, label: null }, listedChunks: ALL_CHUNK_IDS },
      explodedModal: { system: null, label: null },
      explodedListedChunks: ALL_CHUNK_IDS,
    })
  })
})
