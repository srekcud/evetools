import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useMiningOperationStore, type MiningOperation, type MiningOperationLedger, type MiningOperationValuation, type MiningPayment } from '@/stores/miningOperation'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { User } from '@/types'
import { expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  DETAIL_PATH,
  ENDED_AT,
  LEDGER_PATH,
  MEMBERS_PATH,
  OPERATION_O,
  OPERATION_P2,
  ledger,
  ledgerCharacter,
  participant,
  startedSpodumainOperation,
} from './miningOperationLedgerFixtures'
import { MEMBERS_WITH_CYRA, VALUATION_PATH } from './miningOperationValuationFixtures'
import {
  PAYMENTS_PATH,
  closingEachForThemselvesWithBrannUnknown,
  completedE1Operation,
  completedE1Valuation,
  g1Operation,
  g1Payments,
  g1Valuation,
  settledG1Operation,
} from './miningOperationPaymentFixtures'

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

const LONG_PAYER_NAME = 'Brann Holt of the Very Long Named Deep Core Mining Directorate'
const LONG_BENEFICIARY_NAME = 'Cyra Lune of the Exceedingly Long Named Boosting Wing Alliance'
const BRANN_PILOT = { id: 'user-brann', authStatus: 'valid', mainCharacter: null, characters: [{ id: 'character-brann-holt' }, { id: 'character-brann-ii' }] } as unknown as User

const events = reactive<Record<string, MiningOperationEvent | null>>({})

function answer(operation: MiningOperation, valuation: MiningOperationValuation, payments: MiningPayment[] = [], operationLedger: MiningOperationLedger = ledger({ status: operation.status, endedAt: operation.endedAt })): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
    if (endpoint === LEDGER_PATH) return operationLedger as never
    if (endpoint === VALUATION_PATH) return valuation as never
    if (endpoint === PAYMENTS_PATH) return payments as never
    return [] as never
  })
}

async function mountDetail(locale: TestLocale = 'en'): Promise<VueWrapper> {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

function buttonNamed(wrapper: VueWrapper, name: string) {
  return wrapper.findAll('button').find(button => button.text().trim() === name)
}

function sectionTitled(wrapper: VueWrapper, title: string): DOMWrapper<Element> | undefined {
  return wrapper.findAll('section').find(section => section.find('h2').exists() && section.find('h2').text().trim() === title)
}

function checkboxLabelled(wrapper: VueWrapper, label: string): DOMWrapper<Element> | undefined {
  const checkbox = wrapper.find(`input[type="checkbox"][aria-label="${label}"]`)
  return checkbox.exists() ? checkbox : undefined
}

function isDisabled(element: DOMWrapper<Element> | undefined): boolean | undefined {
  return element === undefined ? undefined : element.attributes('disabled') !== undefined
}

function kpiShowing(wrapper: VueWrapper, label: string): DOMWrapper<Element> | undefined {
  return wrapper.findAll('dl > div').find(kpi => kpi.text().includes(label))
}

function steps(wrapper: VueWrapper): { label: string; done: boolean; current: boolean }[] {
  const stepper = wrapper.findAll('ol').find(list => list.text().includes('Planned'))
  return (stepper?.findAll('li') ?? []).map(step => ({
    label: step.text().trim(),
    done: step.classes().includes('text-emerald-300'),
    current: step.attributes('aria-current') === 'step',
  }))
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

async function receiveOperationEvent(operationId: string, action: string, data: Record<string, unknown> = {}): Promise<void> {
  events[operationId] = { action, data, timestamp: '2026-11-05T22:24:01+00:00' }
  await nextTick()
  await flushPromises()
}

async function confirmWith(wrapper: VueWrapper, actionName: string): Promise<void> {
  const confirmButton = wrapper.findAll('button').filter(button => [actionName, 'Confirm'].includes(button.text().trim())).pop()
  await confirmButton?.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, payments of frozen shares', () => {
  it('lists who owes what to whom with the trace of the paid line, counts the paid lines in amber and keeps the settlement disabled', async () => {
    answer(g1Operation(), g1Valuation(), g1Payments([0]))

    const wrapper = await mountDetail()

    const paymentsCard = sectionTitled(wrapper, 'Payments')?.text() ?? ''
    const paymentsKpi = kpiShowing(wrapper, 'Payments')
    const settleCard = sectionTitled(wrapper, 'Mark settled')
    expect({
      ariaLine: paymentsCard.includes('Aria Solberg owes 404,864 ISK to Cyra Lune'),
      brannLine: paymentsCard.includes('Brann Holt owes 430,269 ISK to Cyra Lune'),
      corporationLine: paymentsCard.includes('Cyra Lune owes 278,386 ISK to Corpo Exemple'),
      collectedReason: paymentsCard.includes('corporation cut collected by the booster (5 % of G)'),
      paidTrace: paymentsCard.includes('marked paid by Aria Solberg on') && paymentsCard.includes('at 22:24'),
      pendingTrace: paymentsCard.includes('waiting for the ISK to be given in game'),
      kpi: paymentsKpi?.text().includes('1 / 3') ?? false,
      kpiInAmber: paymentsKpi?.find('dd').classes().some(name => name.includes('amber')) ?? false,
      settleDisabled: isDisabled(settleCard?.findAll('button').find(button => button.text().trim() === 'Mark settled')),
      paymentsLeft: settleCard?.text().includes('2 payment(s) not marked paid yet') ?? false,
    }).toEqual({
      ariaLine: true,
      brannLine: true,
      corporationLine: true,
      collectedReason: true,
      paidTrace: true,
      pendingTrace: true,
      kpi: true,
      kpiInAmber: true,
      settleDisabled: true,
      paymentsLeft: true,
    })
  })

  it('names the corporation generically when its name is unknown', async () => {
    const [aria, brann, corporationLine] = g1Payments()
    answer(g1Operation(), g1Valuation(), [aria, brann, { ...corporationLine, beneficiaryName: null }])

    const wrapper = await mountDetail()

    expect(sectionTitled(wrapper, 'Payments')?.text().includes('Cyra Lune owes 278,386 ISK to the corporation')).toBe(true)
  })

  it('marks the payment of Brann paid with its checkbox', async () => {
    answer(g1Operation(), g1Valuation(), g1Payments([0]))
    const markPayment = vi.spyOn(useMiningOperationStore(), 'markPayment').mockResolvedValue(null)
    const wrapper = await mountDetail()

    await checkboxLabelled(wrapper, 'Brann Holt paid 430,269 ISK to Cyra Lune')?.setValue(true)
    await flushPromises()

    expect(markPayment.mock.calls).toEqual([[OPERATION_O, 'payment-1', true]])
  })

  it('lets a plain member tick only his own payment and offers him no settlement', async () => {
    useAuthStore().user = BRANN_PILOT
    answer(g1Operation({ myRole: 'member' }), g1Valuation(), g1Payments())

    const wrapper = await mountDetail()

    expect({
      own: isDisabled(checkboxLabelled(wrapper, 'Brann Holt paid 430,269 ISK to Cyra Lune')),
      ofAria: isDisabled(checkboxLabelled(wrapper, 'Aria Solberg paid 404,864 ISK to Cyra Lune')),
      ofCyra: isDisabled(checkboxLabelled(wrapper, 'Cyra Lune paid 278,386 ISK to Corpo Exemple')),
      settleCard: sectionTitled(wrapper, 'Mark settled') !== undefined,
    }).toEqual({ own: false, ofAria: true, ofCyra: true, settleCard: false })
  })

  it('settles the operation once every payment is paid and the settlement is confirmed', async () => {
    answer(g1Operation(), g1Valuation(), g1Payments([0, 1, 2]))
    const settle = vi.spyOn(useMiningOperationStore(), 'settle').mockResolvedValue(settledG1Operation())
    const wrapper = await mountDetail()

    const paymentsKpi = kpiShowing(wrapper, 'Payments')
    await buttonNamed(wrapper, 'Mark settled')?.trigger('click')
    await flushPromises()
    const confirmationShown = wrapper.text().includes('Mark the operation settled? Nothing can change afterwards.')
    await confirmWith(wrapper, 'Mark settled')

    expect([paymentsKpi?.find('dd').classes().some(name => name.includes('emerald')) ?? false, confirmationShown, settle.mock.calls]).toEqual([true, true, [[OPERATION_O]]])
  })

  it('keeps long payer and beneficiary names truncated with a tooltip and the amount intact', async () => {
    const [, brann] = g1Payments()
    answer(g1Operation(), g1Valuation(), [{ ...brann, payerName: LONG_PAYER_NAME, beneficiaryName: LONG_BENEFICIARY_NAME }])

    const wrapper = await mountDetail()

    const paymentsCard = sectionTitled(wrapper, 'Payments')
    const payer = paymentsCard?.find(`[title="${LONG_PAYER_NAME}"]`)
    const beneficiary = paymentsCard?.find(`[title="${LONG_BENEFICIARY_NAME}"]`)
    expect([
      payer?.exists() && payer.classes().includes('truncate'),
      beneficiary?.exists() && beneficiary.classes().includes('truncate'),
      paymentsCard?.text().includes('430,269') ?? false,
    ]).toEqual([true, true, true])
  })
})

describe('MiningOperationDetail, settled operation', () => {
  it('shows the settled banner, read only payments, the summary and every step done up to Settled', async () => {
    answer(settledG1Operation(), g1Valuation({ status: 'settled' }), g1Payments([0, 1, 2]))

    const wrapper = await mountDetail()

    const checkboxes = sectionTitled(wrapper, 'Payments')?.findAll('input[type="checkbox"]') ?? []
    expect({
      banner: wrapper.text().includes('Operation settled on') && wrapper.text().includes('23:35 EVE'),
      readOnly: checkboxes.length === 3 && checkboxes.every(checkbox => checkbox.attributes('disabled') !== undefined),
      summary: sectionTitled(wrapper, 'Summary') !== undefined,
      settleCard: sectionTitled(wrapper, 'Mark settled') !== undefined,
      steps: steps(wrapper),
    }).toEqual({
      banner: true,
      readOnly: true,
      summary: true,
      settleCard: false,
      steps: [
        { label: 'Planned', done: true, current: false },
        { label: 'In progress', done: true, current: false },
        { label: 'Closing', done: true, current: false },
        { label: 'Shares', done: true, current: false },
        { label: 'Settled', done: true, current: true },
      ],
    })
  })
})

describe('MiningOperationDetail, completing an each for themselves operation', () => {
  it('shows the completion card disabled with the unknown quantity of Brann, never counted as 0', async () => {
    answer(startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT, splitMode: 'each_for_themselves' }), closingEachForThemselvesWithBrannUnknown())

    const wrapper = await mountDetail()

    const completeCard = sectionTitled(wrapper, 'Mark completed')
    const brannRow = wrapper.findAll('table').find(table => table.text().includes('Mined value'))?.findAll('tr').find(row => row.text().includes('Brann Holt'))?.text() ?? ''
    expect({
      disabled: isDisabled(completeCard?.findAll('button').find(button => button.text().trim() === 'Mark completed')),
      blocker: completeCard?.text().includes('Unknown or still measured quantity: Brann Holt') ?? false,
      brannUnknown: brannRow.includes('unknown'),
      brannNeverZero: /(^|\s)0(\s|$)/.test(brannRow),
    }).toEqual({ disabled: true, blocker: true, brannUnknown: true, brannNeverZero: false })
  })

  it('completes the operation once confirmed', async () => {
    answer(
      startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT, splitMode: 'each_for_themselves' }),
      { ...closingEachForThemselvesWithBrannUnknown(), ...completedE1Valuation(), status: 'closing', frozen: false, canComplete: true, completionBlockers: [] },
    )
    const complete = vi.spyOn(useMiningOperationStore(), 'complete').mockResolvedValue(completedE1Operation())
    const wrapper = await mountDetail()

    await buttonNamed(wrapper, 'Mark completed')?.trigger('click')
    await flushPromises()
    const confirmationShown = wrapper.text().includes('Mark the operation completed? Current prices are frozen.')
    await confirmWith(wrapper, 'Mark completed')

    expect([confirmationShown, complete.mock.calls]).toEqual([true, [[OPERATION_O]]])
  })

  it('shows the completed banner, the frozen mined values, no payment and every step done up to Completed', async () => {
    answer(completedE1Operation(), completedE1Valuation())

    const wrapper = await mountDetail()

    expect({
      banner: wrapper.text().includes('Operation completed on') && wrapper.text().includes('everyone keeps their own. Nothing to settle.'),
      ariaValue: wrapper.findAll('table').find(table => table.text().includes('Mined value'))?.text().includes('2,699,093') ?? false,
      payments: sectionTitled(wrapper, 'Payments') !== undefined,
      steps: steps(wrapper),
    }).toEqual({
      banner: true,
      ariaValue: true,
      payments: false,
      steps: [
        { label: 'Planned', done: true, current: false },
        { label: 'In progress', done: true, current: false },
        { label: 'Closing', done: true, current: false },
        { label: 'Completed', done: true, current: true },
      ],
    })
  })
})

describe('MiningOperationDetail, character without end snapshot', () => {
  it('says that the end snapshot is missing and the quantity unknown', async () => {
    const closingLedger = ledger({
      status: 'closing',
      endedAt: ENDED_AT,
      participants: [
        participant('member-cyra', 'Cyra Lune', [ledgerCharacter('Cyra Lune', { state: 'unknown', unknownReason: 'no_end_snapshot', endSnapshot: null })], { state: 'unknown' }),
      ],
    })
    answer(startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT }), closingEachForThemselvesWithBrannUnknown(), [], closingLedger)

    const wrapper = await mountDetail()

    const cyraRow = wrapper.findAll('tr').find(row => row.text().includes('Cyra Lune') && row.text().includes('✗'))?.text() ?? ''
    expect(cyraRow.includes('✗ no end snapshot · quantity unknown')).toBe(true)
  })
})

describe('MiningOperationDetail, live payments', () => {
  it('reloads the payments once for a payment_updated of the displayed operation and not for another operation', async () => {
    answer(g1Operation(), g1Valuation(), g1Payments())
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await receiveOperationEvent(OPERATION_O, 'payment_updated', { paymentId: 'payment-0', paid: true })
    await receiveOperationEvent(OPERATION_P2, 'payment_updated', { paymentId: 'payment-9', paid: true })

    expect(callsTo(PAYMENTS_PATH)).toBe(1)
  })

  it('reloads the payments on shares_frozen', async () => {
    answer(g1Operation(), g1Valuation(), g1Payments())
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await receiveOperationEvent(OPERATION_O, 'shares_frozen')

    expect(callsTo(PAYMENTS_PATH) >= 1).toBe(true)
  })

  it.each(['operation_settled', 'operation_completed'])('reloads the operation, its valuation and its payments on %s', async action => {
    answer(g1Operation(), g1Valuation(), g1Payments([0, 1, 2]))
    await mountDetail()
    vi.mocked(apiRequest).mockClear()
    answer(settledG1Operation(), g1Valuation({ status: 'settled' }), g1Payments([0, 1, 2]))

    await receiveOperationEvent(OPERATION_O, action)

    expect([callsTo(DETAIL_PATH) >= 1, callsTo(VALUATION_PATH) >= 1, callsTo(PAYMENTS_PATH) >= 1]).toEqual([true, true, true])
  })
})

describe.each(TEST_LOCALES)('MiningOperationDetail, settlement texts in %s', locale => {
  it('shows only the texts of the locale', async () => {
    answer(g1Operation(), g1Valuation(), g1Payments([0]))
    const shares = await mountDetail(locale)
    expectOnlyLocaleTexts(shares.text(), locale, [
      'miningOperation.payments.title',
      'miningOperation.payments.rule',
      'miningOperation.payments.paid',
      'miningOperation.payments.pending',
      'miningOperation.payments.pendingTrace',
      'miningOperation.payments.kind.booster_cut',
      'miningOperation.shares.kpi.payments',
      'miningOperation.settle.title',
      'miningOperation.settle.hint',
    ])
    shares.unmount()

    answer(settledG1Operation(), g1Valuation({ status: 'settled' }), g1Payments([0, 1, 2]))
    const settled = await mountDetail(locale)
    expectOnlyLocaleTexts(settled.text(), locale, ['miningOperation.status.settled', 'miningOperation.recap.title', 'miningOperation.recap.period', 'miningOperation.recap.totalValue', 'miningOperation.recap.payments'])
    settled.unmount()

    answer(startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT, splitMode: 'each_for_themselves' }), closingEachForThemselvesWithBrannUnknown())
    const closing = await mountDetail(locale)
    expectOnlyLocaleTexts(closing.text(), locale, ['miningOperation.complete.title', 'miningOperation.complete.hint'])
    closing.unmount()

    answer(completedE1Operation(), completedE1Valuation())
    const completed = await mountDetail(locale)
    expectOnlyLocaleTexts(completed.text(), locale, ['miningOperation.status.completed', 'miningOperation.recap.title'])
  })
})
