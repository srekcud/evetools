import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import type { MiningOperation } from '@/stores/miningOperation'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS, MEMBERS_PATH, OPERATION_O, ledger, spodumainOperation } from './miningOperationLedgerFixtures'
import { VALUATION_PATH, unmeasuredValuation } from './miningOperationValuationFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: OPERATION_O }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const UNNAMED_MOON_ID = 40_302_003
const CHUNK_REFINERY_ID = 1_035_000_000_102
const CHUNK_REFINERY_NAME = 'Ihakana - Deep Core Tatara'
const LINKED_CHUNK_BLOCK = '[data-testid="operation-linked-chunk"]'

function answer(operation: MiningOperation): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS as never
    if (endpoint === LEDGER_PATH) return ledger({ status: operation.status, endedAt: operation.endedAt }) as never
    if (endpoint === VALUATION_PATH) return unmeasuredValuation('planned') as never
    return [] as never
  })
}

async function mountDetail() {
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, linked chunk', () => {
  it('shows the linked chunk with the identifier of a moon without a name, its refinery, its arrival in EVE time and a link to the moons, and no block without chunk', async () => {
    answer(spodumainOperation({
      chunkId: '0195f3a0-2740-7000-8000-000000000103',
      chunk: {
        id: '0195f3a0-2740-7000-8000-000000000103',
        moonId: UNNAMED_MOON_ID,
        moonName: null,
        structureId: CHUNK_REFINERY_ID,
        structureName: CHUNK_REFINERY_NAME,
        chunkArrivalTime: '2026-11-05T17:00:00+00:00',
      },
    }))
    const withChunk = await mountDetail()
    const block = withChunk.find(LINKED_CHUNK_BLOCK)
    const state = {
      exists: block.exists(),
      text: block.exists() ? block.text().replace(/\s+/g, ' ') : null,
      link: block.exists() ? JSON.parse(block.get('a').attributes('data-to') ?? 'null').name : null,
    }
    withChunk.unmount()
    answer(spodumainOperation({ chunkId: null, chunk: null }))

    const withoutChunk = await mountDetail()

    expect({ state, blockWithoutChunk: withoutChunk.find(LINKED_CHUNK_BLOCK).exists() }).toEqual({
      state: {
        exists: true,
        text: expect.stringMatching(new RegExp(`Chunk lié.*${UNNAMED_MOON_ID}.*${CHUNK_REFINERY_NAME}.*2026-11-05 17:00`)),
        link: 'mining-moons',
      },
      blockWithoutChunk: false,
    })
  })
})
