import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationRecurrencesPanel from '@/components/mining-operation/MiningOperationRecurrencesPanel.vue'
import MiningOperationList from '@/views/MiningOperation/MiningOperationList.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { spodumainOperation } from './miningOperationLedgerFixtures'

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

const MINE_PATH = '/mining-operations'
const EXTRACTIONS_PATH = '/mining/corporation-extractions'
const RECURRENCES_PATH = '/mining-operations/recurrences'
const ATHANOR_STRUCTURE_ID = 1_001
const JASPET_RECURRENCE_ID = '8c3e1f5a-6b24-4d97-a1c0-2e9f7b4d6a38'
const SPODUMAIN_RECURRENCE_ID = '5a9d2c7e-3f18-4b60-8e45-1c7a9d3b5f02'
const THURSDAY_LABEL = 'Jaspet de jeudi'
const ONE_OFF_LABEL = 'Glace de nuit'

const ARIA_OF_C1 = {
  id: '00000000-0000-4000-8000-000000000310',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [{ id: '0199a1f0-0000-7000-8000-000000000310', name: 'Aria Solberg', isMain: true, hasValidToken: true }],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as unknown as User

function extractionsOf(structureIds: number[]) {
  return {
    corporations: [{
      corporationId: 98_262_101,
      access: 'ok',
      lastSuccessAt: '2026-11-03T09:00:00+00:00',
      extractions: structureIds.map((structureId, index) => ({
        id: `0199a1f0-0000-7000-8000-00000000031${index}`,
        structureId,
        structureName: 'Athanor Ilyra',
        solarSystemId: 30_003_504,
        solarSystemName: 'Ilyra',
        moonId: 40_000_000 + index,
        moonName: 'Ilyra IV - Moon 2',
        extractionStartTime: '2026-11-02T18:00:00+00:00',
        chunkArrivalTime: '2026-11-05T20:00:00+00:00',
        naturalDecayTime: '2026-11-08T20:00:00+00:00',
        status: 'extracting',
      })),
    }],
  }
}

function answer(extractions: ReturnType<typeof extractionsOf>, mine: unknown[] = []): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === EXTRACTIONS_PATH) return extractions as never
    if (endpoint === MINE_PATH && options?.method === 'POST') return spodumainOperation({ id: '0199a1f0-0000-7000-8000-0000000003a0', recurrenceId: JASPET_RECURRENCE_ID }) as never
    if (endpoint === MINE_PATH) return mine as never
    if (endpoint === RECURRENCES_PATH) return [] as never
    return [] as never
  })
}

async function mountList(): Promise<VueWrapper> {
  useAuthStore().user = ARIA_OF_C1
  const wrapper = mount(MiningOperationList, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

async function openCreation(wrapper: VueWrapper): Promise<void> {
  const newOperation = wrapper.findAll('button').find(button => button.text().includes('New operation'))
  await newOperation?.trigger('click')
  await flushPromises()
}

async function submitCreation(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  await wrapper.find('input[type="text"][maxlength="100"]').setValue(THURSDAY_LABEL)
  await wrapper.find('form').trigger('submit')
  await flushPromises()
  const post = vi.mocked(apiRequest).mock.calls.find(call => call[0] === MINE_PATH && call[1]?.method === 'POST')
  expect(post, 'creation request').toBeDefined()
  return JSON.parse(String(post?.[1]?.body)) as Record<string, unknown>
}

function radio(wrapper: VueWrapper, value: string) {
  return wrapper.find(`input[type="radio"][value="${value}"]`)
}

async function pickRecurrence(wrapper: VueWrapper, value: string): Promise<void> {
  expect(radio(wrapper, value).exists(), `recurrence choice ${value}`).toBe(true)
  await radio(wrapper, value).setValue(true)
}

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue([] as never)
})

enableAutoUnmount(afterEach)

describe('MiningOperationList, recurrence of a new operation', () => {
  it('offers a one-off, a weekly and a chunk recurrence when the corporation has a refinery with an extraction', async () => {
    answer(extractionsOf([ATHANOR_STRUCTURE_ID]))
    const wrapper = await mountList()

    await openCreation(wrapper)

    expect(['once', 'weekly', 'chunk'].map(value => radio(wrapper, value).exists())).toEqual([true, true, true])
  })

  it('hides the chunk recurrence when the corporation has no refinery with an extraction', async () => {
    answer(extractionsOf([]))
    const wrapper = await mountList()

    await openCreation(wrapper)

    expect(['once', 'weekly', 'chunk'].map(value => radio(wrapper, value).exists())).toEqual([true, true, false])
  })

  it('sends no recurrence for a one-off operation', async () => {
    answer(extractionsOf([ATHANOR_STRUCTURE_ID]))
    const wrapper = await mountList()
    await openCreation(wrapper)

    const body = await submitCreation(wrapper)

    expect(Object.keys(body)).not.toContain('recurrence')
  })

  it('sends a weekly recurrence when the pilot picks every week', async () => {
    answer(extractionsOf([ATHANOR_STRUCTURE_ID]))
    const wrapper = await mountList()
    await openCreation(wrapper)
    await pickRecurrence(wrapper, 'weekly')

    const body = await submitCreation(wrapper)

    expect(body.recurrence).toEqual({ kind: 'weekly' })
  })

  it('sends the chosen refinery with a chunk recurrence', async () => {
    answer(extractionsOf([ATHANOR_STRUCTURE_ID]))
    const wrapper = await mountList()
    await openCreation(wrapper)
    await pickRecurrence(wrapper, 'chunk')
    const refinery = wrapper.find('[data-testid="recurrence-structure"]')
    expect(refinery.exists(), 'refinery choice').toBe(true)
    await refinery.setValue(String(ATHANOR_STRUCTURE_ID))

    const body = await submitCreation(wrapper)

    expect(body.recurrence).toEqual({ kind: 'chunk', structureId: ATHANOR_STRUCTURE_ID })
  })
})

describe('MiningOperationList, occurrences', () => {
  it('marks only the operations issued from a recurrence with a recurrence icon in front of their label', async () => {
    answer(extractionsOf([]), [
      spodumainOperation({ id: '0199a1f0-0000-7000-8000-0000000003b0', label: THURSDAY_LABEL, recurrenceId: JASPET_RECURRENCE_ID }),
      spodumainOperation({ id: '0199a1f0-0000-7000-8000-0000000003b1', label: ONE_OFF_LABEL, recurrenceId: null }),
    ])

    const wrapper = await mountList()

    const rowOf = (label: string) => wrapper.findAll('li, tr').find(row => row.text().includes(label))?.text() ?? ''
    expect([rowOf(THURSDAY_LABEL).includes('↻'), rowOf(ONE_OFF_LABEL).includes('↻')]).toEqual([true, false])
  })
})

describe('MiningOperationRecurrencesPanel', () => {
  const RECURRENCES = [
    { id: JASPET_RECURRENCE_ID, kind: 'weekly', label: THURSDAY_LABEL, structureId: null, structureName: null, nextOccurrenceAt: '2026-11-12T20:00:00+00:00', createdAt: '2026-11-05T18:00:00+00:00' },
    { id: SPODUMAIN_RECURRENCE_ID, kind: 'chunk', label: ONE_OFF_LABEL, structureId: ATHANOR_STRUCTURE_ID, structureName: 'Athanor Ilyra', nextOccurrenceAt: null, createdAt: '2026-11-02T12:00:00+00:00' },
  ]

  async function mountPanel(): Promise<VueWrapper> {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint === RECURRENCES_PATH ? RECURRENCES : []) as never)
    const wrapper = mount(MiningOperationRecurrencesPanel, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
    })
    await flushPromises()
    return wrapper
  }

  it('lists each active recurrence with its label and, for a chunk recurrence, its refinery', async () => {
    const wrapper = await mountPanel()

    expect([THURSDAY_LABEL, ONE_OFF_LABEL, 'Athanor Ilyra'].map(text => wrapper.text().includes(text))).toEqual([true, true, true])
  })

  it('stops a recurrence only after the owner confirmed it', async () => {
    const wrapper = await mountPanel()

    const stopButtons = wrapper.findAll('[data-testid="stop-recurrence"]')
    expect(stopButtons).toHaveLength(2)
    await stopButtons[0]?.trigger('click')
    const deletesBeforeConfirmation = vi.mocked(apiRequest).mock.calls.filter(call => call[1]?.method === 'DELETE').length
    const confirmation = wrapper.find('[data-testid="confirm-stop-recurrence"]')
    expect(confirmation.exists(), 'confirmation button').toBe(true)
    await confirmation.trigger('click')
    await flushPromises()

    const deleteCalls = vi.mocked(apiRequest).mock.calls.filter(call => call[1]?.method === 'DELETE').map(call => call[0])
    expect([deletesBeforeConfirmation, deleteCalls]).toEqual([0, [`${RECURRENCES_PATH}/${JASPET_RECURRENCE_ID}`]])
  })
})
