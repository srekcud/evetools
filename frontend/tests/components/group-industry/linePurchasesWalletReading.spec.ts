import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LinePurchases from '@/components/group-industry/LinePurchases.vue'
import { dateFormat } from '@/composables/useFormatters'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject, PurchaseSuggestion, PurchaseSuggestions, WalletReading } from '@/stores/group-industry/types'
import { useSyncStore, type SyncProgress } from '@/stores/sync'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-titan-batch'
const SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const OPEN_PURCHASES = '[data-testid="purchases-open"]'
const SUGGESTION = '[data-testid="purchase-suggestion"]'
const SUGGESTION_NEW = '[data-testid="purchase-suggestion-new"]'
const CHIP = '[data-testid="wallet-reading"]'
const READING_IN_PROGRESS = '[data-testid="wallet-reading-in-progress"]'
const EMPTY_HINT = '[data-testid="wallet-reading-empty-hint"]'
const PLATES_TYPE_ID = 21010
const SHIELD_EMITTER_TYPE_ID = 21011

const FIRST_READ_AT = '2026-11-13T20:04:10+00:00'
const FIRST_NEXT_READ_AT = '2026-11-13T21:04:00+00:00'
const SECOND_READ_AT = '2026-11-13T21:05:20+00:00'
const SECOND_NEXT_READ_AT = '2026-11-13T22:04:30+00:00'

const READ_ONCE: WalletReading[] = [
  { characterName: 'Aria Solberg', status: 'read', readAt: FIRST_READ_AT, nextReadAt: FIRST_NEXT_READ_AT },
  { characterName: 'Brann Holt', status: 'missing_scope', readAt: null, nextReadAt: null },
]

const READ_TWICE: WalletReading[] = [
  { characterName: 'Aria Solberg', status: 'read', readAt: SECOND_READ_AT, nextReadAt: SECOND_NEXT_READ_AT },
  { characterName: 'Brann Holt', status: 'missing_scope', readAt: null, nextReadAt: null },
]

function plateSuggestion(transactionId: number, characterName: string, quantity: number, unitPrice: number, alreadyLinked: boolean): PurchaseSuggestion {
  return {
    transactionId,
    transactionUuid: `0192f0c4-7b1e-7a3c-9d2e-${String(transactionId).padStart(12, '0')}`,
    typeId: PLATES_TYPE_ID,
    typeName: 'Capital Armor Plates',
    quantity,
    unitPrice,
    totalPrice: quantity * unitPrice,
    date: '2026-11-13T19:30:00+00:00',
    characterName,
    locationId: 60003760,
    alreadyLinked,
  }
}

const EMITTER_SUGGESTION: PurchaseSuggestion = {
  ...plateSuggestion(6_400_000_900, 'Brann Holt', 3, 4_300_000, false),
  typeId: SHIELD_EMITTER_TYPE_ID,
  typeName: 'Capital Shield Emitter',
}

const PLATES_LINE = {
  id: 'job-capital-armor-plates',
  typeId: PLATES_TYPE_ID,
  typeName: 'Capital Armor Plates',
  purchases: [],
} as unknown as BomItem

let suggestionsAnswer: PurchaseSuggestions

function answer(suggestions: PurchaseSuggestion[], walletReadings: WalletReading[]): PurchaseSuggestions {
  return { suggestions, totalCount: suggestions.length, walletReadings }
}

function clockTime(iso: string): string {
  const date = new Date(iso)
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

function walletTransactionsSync(status: SyncProgress['status']): SyncProgress {
  return { syncType: 'wallet-transactions', status, progress: null, message: null, data: null, timestamp: '2026-11-13T21:05:00+00:00' }
}

function suggestionReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SUGGESTIONS_URL).length
}

function mountPurchases(): VueWrapper {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Titan batch', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
  const i18n = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: { en, fr } })
  return mount(LinePurchases, { props: { item: PLATES_LINE }, global: { plugins: [i18n], stubs: { teleport: true } } })
}

async function openPurchases(wrapper: VueWrapper): Promise<void> {
  await wrapper.get(OPEN_PURCHASES).trigger('click')
  await flushPromises()
}

async function finishWalletReading(): Promise<void> {
  useSyncStore().syncStatus['wallet-transactions'] = walletTransactionsSync('completed')
  await flushPromises()
}

function isMarkedAsNew(wrapper: VueWrapper, characterName: string): boolean {
  const row = wrapper.findAll(SUGGESTION).find(candidate => candidate.text().includes(characterName))
  if (row === undefined) throw new Error(`no suggestion of ${characterName}`)
  return row.find(SUGGESTION_NEW).exists()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  dateFormat.value = 'eu'
  vi.mocked(apiRequest).mockReset()
  suggestionsAnswer = answer([], READ_ONCE)
  vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === SUGGESTIONS_URL ? suggestionsAnswer : {})) as never)
})

describe('LinePurchases wallet reading', () => {
  it('shows the wallet reading of each character as soon as the window opens', async () => {
    const wrapper = mountPurchases()

    await openPurchases(wrapper)

    const chips = wrapper.findAll(CHIP).map(chip => normalized(chip.text()))
    expect(chips).toHaveLength(2)
    expect(chips[0]).toContain('Aria Solberg')
    expect(chips[0]).toContain(`Wallet read at ${clockTime(FIRST_READ_AT)} · next read around ${clockTime(FIRST_NEXT_READ_AT)}`)
    expect(chips[1]).toContain('Brann Holt')
    expect(chips[1].toLowerCase()).toContain('wallet scope missing')
  })

  it('gives the latest appearance time of an in-game transaction and the manual entry when no transaction matches the line', async () => {
    suggestionsAnswer = answer([EMITTER_SUGGESTION], READ_ONCE)
    const wrapper = mountPurchases()

    await openPurchases(wrapper)

    expect(wrapper.findAll(SUGGESTION)).toHaveLength(0)
    const hint = normalized(wrapper.get(EMPTY_HINT).text())
    expect(hint).toContain(clockTime(FIRST_NEXT_READ_AT))
    expect(hint.toLowerCase()).toContain('manual')
  })

  it('says the wallet is being read between the start and the completion of the wallet reading', async () => {
    const wrapper = mountPurchases()
    await openPurchases(wrapper)
    const beforeTheReading = wrapper.find(READING_IN_PROGRESS).exists()

    useSyncStore().syncStatus['wallet-transactions'] = walletTransactionsSync('started')
    await flushPromises()
    const duringTheReading = normalized(wrapper.get(READING_IN_PROGRESS).text())

    await finishWalletReading()

    expect({ beforeTheReading, duringTheReading, afterTheReading: wrapper.find(READING_IN_PROGRESS).exists() }).toEqual({
      beforeTheReading: false,
      duringTheReading: 'Reading the wallet…',
      afterTheReading: false,
    })
  })

  it('reads the suggestions again when the wallet reading completes while the window is open and not while it is closed', async () => {
    const wrapper = mountPurchases()

    await finishWalletReading()
    const readsWhileClosed = suggestionReads()
    await openPurchases(wrapper)
    suggestionsAnswer = answer([], READ_TWICE)
    useSyncStore().syncStatus['wallet-transactions'] = { ...walletTransactionsSync('completed'), timestamp: '2026-11-13T22:05:00+00:00' }
    await flushPromises()

    expect({ readsWhileClosed, readsAfterCompletion: suggestionReads() }).toEqual({ readsWhileClosed: 0, readsAfterCompletion: 2 })
    expect(normalized(wrapper.get(CHIP).text())).toContain(`Wallet read at ${clockTime(SECOND_READ_AT)} · next read around ${clockTime(SECOND_NEXT_READ_AT)}`)
  })

  it('marks as new the suggestion that appears and the one that becomes linked after the reload, and no other', async () => {
    const unchanged = plateSuggestion(6_400_000_201, 'Aria Solberg', 12, 980_000, false)
    const becomesLinked = plateSuggestion(6_400_000_202, 'Brann Holt', 8, 1_020_000, false)
    const appears = plateSuggestion(6_400_000_203, 'Cyra Lune', 5, 1_110_000, false)
    suggestionsAnswer = answer([unchanged, becomesLinked], READ_ONCE)
    const wrapper = mountPurchases()
    await openPurchases(wrapper)
    const newBeforeTheReload = wrapper.findAll(SUGGESTION_NEW).length
    suggestionsAnswer = answer([unchanged, { ...becomesLinked, alreadyLinked: true }, appears], READ_TWICE)

    await finishWalletReading()

    const markedAsNew = ['Aria Solberg', 'Brann Holt', 'Cyra Lune'].map(characterName => isMarkedAsNew(wrapper, characterName))
    expect({ newBeforeTheReload, markedAsNew }).toEqual({ newBeforeTheReload: 0, markedAsNew: [false, true, true] })
    expect(normalized(wrapper.get(SUGGESTION_NEW).text()).toLowerCase()).toBe('new')
  })
})
