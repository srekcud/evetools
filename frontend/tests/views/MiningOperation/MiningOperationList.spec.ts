import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationList from '@/views/MiningOperation/MiningOperationList.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { MiningOperation } from '@/stores/miningOperation'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { User } from '@/types'
import { expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { OPERATION_O } from './miningOperationLedgerFixtures'
import { ARCHIVE_PATH, P2_LABEL, completedP2Operation, g1Operation, settledArchiveSummary, settledG1Operation } from './miningOperationPaymentFixtures'

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

const DORN_WITHOUT_CORPORATION = {
  id: '00000000-0000-4000-8000-000000000263',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationName: '',
} as unknown as User

const ARIA_OF_C1 = {
  id: '00000000-0000-4000-8000-000000000264',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [],
  corporationId: 98_262_101,
  corporationName: 'Corporation 98262101',
} as User

async function mountList(user: User, locale: TestLocale = 'en'): Promise<VueWrapper> {
  useAuthStore().user = user
  const wrapper = mount(MiningOperationList, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return wrapper
}

const NEW_OPERATION: Record<TestLocale, string> = { en: 'New operation', fr: 'Nouvelle opération' }

async function openCreation(wrapper: VueWrapper, locale: TestLocale = 'en'): Promise<void> {
  const newOperation = wrapper.findAll('button').find(button => button.text().includes(NEW_OPERATION[locale]))
  expect(newOperation, 'new operation button').toBeDefined()
  await newOperation?.trigger('click')
}

function radio(wrapper: VueWrapper, value: string) {
  const input = wrapper.find(`input[type="radio"][value="${value}"]`)
  expect(input.exists(), `radio ${value}`).toBe(true)
  return input
}

const MINE_PATH = '/mining-operations'
const LONG_OPERATION_LABEL = 'Anomalie Spodumain du dimanche soir dans la ceinture la plus lointaine'
const events = reactive<Record<string, MiningOperationEvent | null>>({})

function answerLists(mine: MiningOperation[], archive: MiningOperation[]): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === MINE_PATH) return mine as never
    if (endpoint === ARCHIVE_PATH) return archive as never
    return [] as never
  })
}

function archiveRowOf(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('tr').find(row => row.text().includes(label))
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue([] as never)
  route.query = {}
})

enableAutoUnmount(afterEach)

describe('MiningOperationList', () => {
  it('disables the corporation visibility for a pilot without known corporation and says why', async () => {
    const wrapper = await mountList(DORN_WITHOUT_CORPORATION)

    await openCreation(wrapper)

    expect(radio(wrapper, 'corporation').attributes('disabled')).toBeDefined()
    expect(radio(wrapper, 'private').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('Unknown corporation: private visibility only.')
  })

  it('offers the corporation visibility to a pilot of a known corporation', async () => {
    const wrapper = await mountList(ARIA_OF_C1)

    await openCreation(wrapper)

    expect(radio(wrapper, 'corporation').attributes('disabled')).toBeUndefined()
  })

  it('shows the weighted time split mode as disabled and coming soon', async () => {
    const wrapper = await mountList(ARIA_OF_C1)

    await openCreation(wrapper)

    expect(radio(wrapper, 'weighted_time').attributes('disabled')).toBeDefined()
    expect(radio(wrapper, 'value_prorata').attributes('disabled')).toBeUndefined()
    expect(radio(wrapper, 'each_for_themselves').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('soon')
  })

  it('tells a pilot without known corporation that only operations shared by link are reachable', async () => {
    route.query = { tab: 'available' }

    const wrapper = await mountList(DORN_WITHOUT_CORPORATION)

    expect(wrapper.text()).toContain('Unknown corporation: only operations shared by link are reachable.')
  })

  it.each(TEST_LOCALES)('shows only %s texts on an empty list of my operations', async (locale) => {
    const wrapper = await mountList(ARIA_OF_C1, locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'miningOperation.title',
      'miningOperation.tabs.mine',
      'miningOperation.tabs.available',
      'miningOperation.list.new',
      'miningOperation.list.emptyMine',
    ])
  })

  it.each(TEST_LOCALES)('shows only %s texts in the creation form', async (locale) => {
    const wrapper = await mountList(ARIA_OF_C1, locale)

    await openCreation(wrapper, locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'miningOperation.create.title',
      'miningOperation.create.label',
      'miningOperation.create.start',
      'miningOperation.create.visibility',
      'miningOperation.create.splitMode',
      'miningOperation.create.characters',
      'miningOperation.create.submit',
      'miningOperation.visibility.private',
      'miningOperation.visibility.corporation',
      'miningOperation.splitMode.value_prorata',
      'miningOperation.splitMode.weighted_time',
      'miningOperation.splitMode.each_for_themselves',
    ])
  })
})

describe('MiningOperationList, archive', () => {
  it('lists the settled and completed operations with their value, my share and their payments', async () => {
    route.query = { tab: 'archive' }
    answerLists([], [settledG1Operation(), completedP2Operation()])

    const wrapper = await mountList(ARIA_OF_C1)

    const settledRow = archiveRowOf(wrapper, 'Anomalie Spodumain — 20:00')?.text() ?? ''
    const completedRow = archiveRowOf(wrapper, P2_LABEL)?.text() ?? ''
    expect({
      archiveTab: wrapper.findAll('[role="tab"]').map(tab => tab.text().replace(/\s+\d+$/, '').trim()),
      settled: ['5,567,729', '2,294,229', '3 / 3 paid', 'Settled', 'Aldranette'].every(text => settledRow.includes(text)),
      completed: ['812,300', 'my value · everyone keeps their own', 'nothing to settle', 'Completed'].every(text => completedRow.includes(text)),
    }).toEqual({
      archiveTab: ['My operations', 'Available', 'Archive'],
      settled: true,
      completed: true,
    })
  })

  it('says that a corporation member who did not take part has no share', async () => {
    route.query = { tab: 'archive' }
    answerLists([], [settledG1Operation({ myRole: null, myStatus: null, archiveSummary: settledArchiveSummary({ myMinedValue: null, myShare: null }) })])

    const wrapper = await mountList(ARIA_OF_C1)

    expect(archiveRowOf(wrapper, 'Anomalie Spodumain — 20:00')?.text().includes('not a participant')).toBe(true)
  })

  it('says that nothing is settled or completed yet', async () => {
    route.query = { tab: 'archive' }
    answerLists([], [])

    const wrapper = await mountList(ARIA_OF_C1)

    expect(wrapper.text()).toContain('No settled or completed operation yet.')
  })

  it('keeps a long operation label truncated with a tooltip in the archive', async () => {
    route.query = { tab: 'archive' }
    answerLists([], [settledG1Operation({ label: LONG_OPERATION_LABEL })])

    const wrapper = await mountList(ARIA_OF_C1)

    const labelCell = archiveRowOf(wrapper, '5,567,729')?.find(`[title="${LONG_OPERATION_LABEL}"]`)
    expect([labelCell?.exists() ?? false, labelCell?.classes().includes('truncate') ?? false]).toEqual([true, true])
  })

  it.each(['operation_settled', 'operation_completed'])('reloads my operations and the archive on %s of a listed operation', async action => {
    answerLists([g1Operation()], [])
    vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
    await mountList(ARIA_OF_C1)
    vi.mocked(apiRequest).mockClear()

    events[OPERATION_O] = { action, data: {}, timestamp: '2026-11-05T23:35:01+00:00' }
    await nextTick()
    await flushPromises()

    expect([callsTo(MINE_PATH), callsTo(ARCHIVE_PATH)]).toEqual([1, 1])
  })

  it.each(TEST_LOCALES)('shows only %s texts in the archive', async (locale) => {
    route.query = { tab: 'archive' }
    answerLists([], [settledG1Operation(), completedP2Operation()])

    const wrapper = await mountList(ARIA_OF_C1, locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, [
      'miningOperation.tabs.archive',
      'miningOperation.archive.hint',
      'miningOperation.archive.columns.operation',
      'miningOperation.archive.columns.archivedAt',
      'miningOperation.archive.columns.system',
      'miningOperation.archive.columns.participants',
      'miningOperation.archive.columns.totalValue',
      'miningOperation.archive.columns.myShare',
      'miningOperation.archive.columns.payments',
      'miningOperation.archive.columns.status',
      'miningOperation.archive.myValue',
      'miningOperation.archive.nothingToSettle',
      'miningOperation.status.settled',
      'miningOperation.status.completed',
    ])
  })
})
