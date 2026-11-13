import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import {
  useMiningOperationStore,
  type MiningFleetStorage,
  type MiningFleetStorageVerification,
  type MiningOperation,
  type MiningOperationLedger,
  type MiningOperationValuation,
  type MiningPayment,
} from '@/stores/miningOperation'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { User } from '@/types'
import { expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS_PATH, OPERATION_O, OPERATION_P2 } from './miningOperationLedgerFixtures'
import { MEMBERS_WITH_CYRA, VALUATION_PATH, eachForThemselvesE1Valuation } from './miningOperationValuationFixtures'
import { PAYMENTS_PATH } from './miningOperationPaymentFixtures'
import {
  CONTAINER_NAME,
  CORPORATION_ASSETS_REFRESH_PATH,
  FLEET_STORAGE_PATH,
  VERIFICATION_PATH,
  declaration,
  e1ClosingLedger,
  fleetStorage,
  fs2ClosingOperation,
  fs2FleetStorage,
  fs2FleetStorageSeenByCyra,
  fs2FrozenValuation,
  fs2Payments,
  fs2SharesOperation,
  fs2Valuation,
  fs2ValuationAboveMined,
  nothingDeliveredVerification,
  shortVerification,
  syncedBeforeEndVerification,
  verification,
} from './miningOperationFleetStorageFixtures'
import { present } from '../../present'

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

const LONG_RECIPIENT_NAME = 'Brann Holt of the Very Long Named Deep Core Mining Directorate'
const LONG_DEPOSITOR_NAME = 'Aria Solberg of the Exceedingly Long Named Ore Hauling Wing'
const LONG_CONTAINER_NAME = 'OPS-HAUL Aldranette Station Container For The Spodumain Ops'
const CYRA_PILOT = { id: 'user-cyra', authStatus: 'valid', mainCharacter: null, characters: [{ id: 'character-cyra' }] } as unknown as User

const events = reactive<Record<string, MiningOperationEvent | null>>({})

interface Answers {
  operation?: MiningOperation
  valuation?: MiningOperationValuation
  ledger?: MiningOperationLedger
  fleetStorage?: MiningFleetStorage
  verification?: MiningFleetStorageVerification
  payments?: MiningPayment[]
}

function answer(answers: Answers = {}): void {
  const operation = answers.operation ?? fs2ClosingOperation()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
    if (endpoint === LEDGER_PATH) return (answers.ledger ?? e1ClosingLedger()) as never
    if (endpoint === VALUATION_PATH) return (answers.valuation ?? fs2Valuation()) as never
    if (endpoint === PAYMENTS_PATH) return (answers.payments ?? []) as never
    if (endpoint === FLEET_STORAGE_PATH) return (answers.fleetStorage ?? fs2FleetStorage()) as never
    if (endpoint === VERIFICATION_PATH) return (answers.verification ?? verification()) as never
    if (endpoint === CORPORATION_ASSETS_REFRESH_PATH) return { status: 'queued' } as never
    return [] as never
  })
}

function answerRefreshOfCorporationAssetsWith(status: string): void {
  const answerOthers = present(vi.mocked(apiRequest).getMockImplementation())
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, ...rest: unknown[]) =>
    endpoint === CORPORATION_ASSETS_REFRESH_PATH ? ({ status } as never) : (answerOthers as (...args: unknown[]) => Promise<never>)(endpoint, ...rest),
  )
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

function sectionTitled(wrapper: VueWrapper, title: string): DOMWrapper<Element> | undefined {
  return wrapper.findAll('section').find(section => section.find('h2').exists() && section.find('h2').text().trim() === title)
}

function fleetStorageCard(wrapper: VueWrapper): DOMWrapper<Element> | undefined {
  return sectionTitled(wrapper, 'Fleet storage')
}

function deliveryOf(wrapper: VueWrapper, participantName: string): DOMWrapper<Element> | undefined {
  const group = fleetStorageCard(wrapper)?.find(`[role="group"][aria-label="${participantName}"]`)
  return group?.exists() ? group : undefined
}

function pressedDeliveryOf(wrapper: VueWrapper, participantName: string): string[] {
  return (deliveryOf(wrapper, participantName)?.findAll('button[aria-pressed="true"]') ?? []).map(button => button.text().trim())
}

function enabledDeliveryButtonsOf(wrapper: VueWrapper, participantName: string): string[] {
  return (deliveryOf(wrapper, participantName)?.findAll('button') ?? [])
    .filter(button => button.attributes('disabled') === undefined)
    .map(button => button.text().trim())
}

function shareTable(wrapper: VueWrapper): DOMWrapper<Element> | undefined {
  return wrapper.findAll('table').find(table => table.text().includes('Mined value'))
}

function shareRowOf(wrapper: VueWrapper, participantName: string): DOMWrapper<Element> | undefined {
  return shareTable(wrapper)?.findAll('tbody tr').find(row => row.text().includes(participantName))
}

function buttonNamed(wrapper: VueWrapper, name: string) {
  return wrapper.findAll('button').find(button => button.text().trim() === name)
}

function inputValuesIn(element: DOMWrapper<Element> | undefined): string[] {
  return (element?.findAll('input') ?? []).map(input => (input.element as HTMLInputElement).value)
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

async function receiveOperationEvent(operationId: string, action: string, data: Record<string, unknown> = {}): Promise<void> {
  events[operationId] = { action, data, timestamp: '2026-11-05T21:55:01+00:00' }
  await nextTick()
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, fleet storage of a closing operation', () => {
  it('shows the recipient, the container, the partial delivery of Aria with the debt of Brann and who holds the ore', async () => {
    answer()

    const wrapper = await mountDetail()

    const card = fleetStorageCard(wrapper)
    const text = card?.text() ?? ''
    expect({
      recipient: (card?.find('select').element as HTMLSelectElement | undefined)?.value,
      inputs: inputValuesIn(card).filter(value => [CONTAINER_NAME, '100000'].includes(value)).sort(),
      ariaDelivery: pressedDeliveryOf(wrapper, 'Aria Solberg'),
      cyraDelivery: pressedDeliveryOf(wrapper, 'Cyra Lune'),
      minedVeldspar: text.includes('100,000 u'),
      debt: text.includes('Brann Holt owes them 1,691,091 ISK'),
      debtDetail: text.includes('share 2,429,184 − kept value 738,093'),
      keeps: text.includes('keeps their ore'),
      holderHeader: (shareTable(wrapper)?.findAll('th') ?? []).map(header => header.text().trim()).includes('Ore held by'),
      ariaHolder: shareRowOf(wrapper, 'Aria Solberg')?.text().includes('delivered: Veldspar 100,000 u') ?? false,
      ariaHeldBy: shareRowOf(wrapper, 'Aria Solberg')?.text().includes('Brann Holt') ?? false,
      brannHolder: shareRowOf(wrapper, 'Brann Holt')?.text().includes('recipient') ?? false,
      cyraHolder: shareRowOf(wrapper, 'Cyra Lune')?.text().includes('themselves') ?? false,
      depositors: shareTable(wrapper)?.text().includes('fleet storage: 1 depositor(s)') ?? false,
    }).toEqual({
      recipient: 'member-brann',
      inputs: ['100000', CONTAINER_NAME],
      ariaDelivery: ['Delivers part'],
      cyraDelivery: ['Keeps everything'],
      minedVeldspar: true,
      debt: true,
      debtDetail: true,
      keeps: true,
      holderHeader: true,
      ariaHolder: true,
      ariaHeldBy: true,
      brannHolder: true,
      cyraHolder: true,
      depositors: true,
    })
  })

  it('declares that Aria delivers everything with her segmented control', async () => {
    answer()
    const wrapper = await mountDetail()
    const declareFleetStorage = vi.spyOn(useMiningOperationStore(), 'declareFleetStorage').mockResolvedValue(null)

    await deliveryOf(wrapper, 'Aria Solberg')?.findAll('button').find(button => button.text().trim() === 'Delivers everything')?.trigger('click')
    await flushPromises()

    expect(declareFleetStorage.mock.calls).toEqual([[OPERATION_O, 'member-aria', { delivery: 'all' }]])
  })

  it('saves the recipient chosen by the owner', async () => {
    answer({ fleetStorage: fleetStorage({ containerName: CONTAINER_NAME }) })
    const wrapper = await mountDetail()
    const updateFleetStorage = vi.spyOn(useMiningOperationStore(), 'updateFleetStorage').mockResolvedValue(null)

    await fleetStorageCard(wrapper)?.find('select').setValue('member-cyra')
    await flushPromises()

    expect(updateFleetStorage.mock.calls).toEqual([[OPERATION_O, { recipientMemberId: 'member-cyra' }]])
  })

  it('shows a plain member the recipient read only and lets her declare only her own line', async () => {
    useAuthStore().user = CYRA_PILOT
    answer({ operation: fs2ClosingOperation({ myRole: 'member' }), fleetStorage: fs2FleetStorageSeenByCyra() })

    const wrapper = await mountDetail()

    const card = fleetStorageCard(wrapper)
    expect({
      recipientList: card?.find('select').exists() ?? null,
      containerField: inputValuesIn(card).includes(CONTAINER_NAME),
      recipientShown: card?.text().includes('Brann Holt') ?? false,
      aria: enabledDeliveryButtonsOf(wrapper, 'Aria Solberg'),
      cyra: enabledDeliveryButtonsOf(wrapper, 'Cyra Lune'),
    }).toEqual({
      recipientList: false,
      containerField: false,
      recipientShown: true,
      aria: [],
      cyra: ['Keeps everything', 'Delivers everything', 'Delivers part'],
    })
  })

  it('flags a delivered quantity above what was mined in amber, leaves the debt unknown and keeps the freeze disabled', async () => {
    answer({
      valuation: fs2ValuationAboveMined(),
      fleetStorage: fs2FleetStorage({
        declarations: [
          declaration('member-aria', 'Aria Solberg', { delivery: 'partial', quantities: [{ typeId: 1230, typeName: 'Veldspar', quantity: 100_001 }], canDeclare: true }),
          declaration('member-brann', 'Brann Holt'),
          declaration('member-cyra', 'Cyra Lune', { canDeclare: true }),
        ],
      }),
    })

    const wrapper = await mountDetail()

    const card = fleetStorageCard(wrapper)
    const exceedingField = card?.findAll('input').find(input => (input.element as HTMLInputElement).value === '100001')
    expect({
      exceeds: card?.text().includes('more than mined (100,000 u)') ?? false,
      amberField: exceedingField?.classes().some(className => className.includes('amber')) ?? false,
      unknownDebt: card?.find(`[title="${messageOf('en', 'common.unknownValue')}"]`).exists() ?? false,
      noZeroDebt: card?.text().includes('owes them 0 ISK') ?? true,
      freezeDisabled: buttonNamed(wrapper, 'Freeze shares')?.attributes('disabled') !== undefined,
      blocker: wrapper.text().includes('Delivered quantity above what was mined: Aria Solberg'),
    }).toEqual({ exceeds: true, amberField: true, unknownDebt: true, noZeroDebt: false, freezeDisabled: true, blocker: true })
  })

  it('reloads the fleet storage, the valuation and the verification once for a fleet_storage_updated of the displayed operation only', async () => {
    answer()
    await mountDetail()
    const before = [callsTo(FLEET_STORAGE_PATH), callsTo(VALUATION_PATH), callsTo(VERIFICATION_PATH)]

    await receiveOperationEvent(OPERATION_O, 'fleet_storage_updated', { memberId: 'member-aria' })
    const afterOwnEvent = [callsTo(FLEET_STORAGE_PATH), callsTo(VALUATION_PATH), callsTo(VERIFICATION_PATH)]
    await receiveOperationEvent(OPERATION_P2, 'fleet_storage_updated', { memberId: null })

    expect({
      firstLoad: before.every(count => count >= 1),
      ownEvent: afterOwnEvent.map((count, index) => count - (before[index] ?? 0)),
      otherOperation: [callsTo(FLEET_STORAGE_PATH), callsTo(VALUATION_PATH), callsTo(VERIFICATION_PATH)],
    }).toEqual({ firstLoad: true, ownEvent: [1, 1, 1], otherOperation: afterOwnEvent })
  })

  it('names the recipient in the rules of the operation', async () => {
    answer()

    const wrapper = await mountDetail()

    expect(sectionTitled(wrapper, 'Rules')?.text().includes('Fleet storage') && sectionTitled(wrapper, 'Rules')?.text().includes('Brann Holt')).toBe(true)
  })

  it('keeps long recipient, depositor and container names truncated with a tooltip and the amounts intact', async () => {
    answer({
      operation: fs2ClosingOperation({ fleetStorageRecipientName: LONG_RECIPIENT_NAME }),
      fleetStorage: fs2FleetStorage({
        recipientName: LONG_RECIPIENT_NAME,
        containerName: LONG_CONTAINER_NAME,
        declarations: [
          declaration('member-aria', LONG_DEPOSITOR_NAME, { delivery: 'partial', quantities: [{ typeId: 1230, typeName: 'Veldspar', quantity: 100_000 }], canDeclare: true }),
          declaration('member-brann', LONG_RECIPIENT_NAME),
          declaration('member-cyra', 'Cyra Lune', { canDeclare: true }),
        ],
      }),
      verification: verification({ containerName: LONG_CONTAINER_NAME }),
    })

    const wrapper = await mountDetail()

    const card = fleetStorageCard(wrapper)
    const truncatedWithTooltip = (name: string) => (card?.findAll(`[title="${name}"]`) ?? []).some(element => element.classes().includes('truncate'))
    expect({
      recipient: truncatedWithTooltip(LONG_RECIPIENT_NAME),
      depositor: truncatedWithTooltip(LONG_DEPOSITOR_NAME),
      container: truncatedWithTooltip(LONG_CONTAINER_NAME),
      debt: card?.text().includes('1,691,091') ?? false,
      verificationLine: card?.text().includes('100,000 / 100,000 u') ?? false,
    }).toEqual({ recipient: true, depositor: true, container: true, debt: true, verificationLine: true })
  })
})

describe('MiningOperationDetail, verification of the corporation container', () => {
  it('shows each delivered type found in the named container as verified with the time the assets were read', async () => {
    answer()

    const wrapper = await mountDetail()

    const text = fleetStorageCard(wrapper)?.text() ?? ''
    expect({
      title: text.includes('Corporation container “OPS-HAUL”'),
      readAt: text.includes('corporation assets read at 21:41'),
      line: text.includes('100,000 / 100,000 u'),
      verified: text.includes('verified'),
      noEffect: text.includes('This check does not block freezing the shares: the debt follows the declaration.'),
      refresh: buttonNamed(wrapper, 'Refresh corporation assets') !== undefined,
    }).toEqual({ title: true, readAt: true, line: true, verified: true, noEffect: true, refresh: false })
  })

  it('shows the missing units of a short container and offers to refresh the corporation assets', async () => {
    answer({ verification: shortVerification() })

    const wrapper = await mountDetail()

    const text = fleetStorageCard(wrapper)?.text() ?? ''
    expect({
      line: text.includes('96,400 / 100,000 u'),
      missing: text.includes('3,600 u missing'),
      refresh: buttonNamed(wrapper, 'Refresh corporation assets') !== undefined,
    }).toEqual({ line: true, missing: true, refresh: true })
  })

  it('says the corporation assets were read before the end of the operation and never shows 0 as found', async () => {
    answer({ verification: syncedBeforeEndVerification() })

    const wrapper = await mountDetail()

    const text = fleetStorageCard(wrapper)?.text() ?? ''
    expect({
      reason: text.includes('Corporation assets read at 20:40, before the operation ended (21:30).'),
      unknown: text.includes('unknown'),
      zeroFound: text.includes('0 / 100,000 u'),
      refresh: buttonNamed(wrapper, 'Refresh corporation assets') !== undefined,
    }).toEqual({ reason: true, unknown: true, zeroFound: false, refresh: true })
  })

  it('asks for a refresh of the corporation assets then reloads the verification once their sync has completed', async () => {
    answer({ verification: syncedBeforeEndVerification() })
    const wrapper = await mountDetail()
    const verificationsBefore = callsTo(VERIFICATION_PATH)

    await buttonNamed(wrapper, 'Refresh corporation assets')?.trigger('click')
    await flushPromises()
    const refreshCalls = callsTo(CORPORATION_ASSETS_REFRESH_PATH)
    useSyncStore().syncStatus = {
      'corporation-assets': { syncType: 'corporation-assets', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-05T21:56:00+00:00' },
    }
    await nextTick()
    await flushPromises()

    expect([refreshCalls, callsTo(VERIFICATION_PATH) - verificationsBefore]).toEqual([1, 1])
  })

  it.each(['up_to_date', 'in_progress'])('stops waiting for the sync when the refresh is answered %s', async status => {
    answer({ verification: syncedBeforeEndVerification() })
    answerRefreshOfCorporationAssetsWith(status)
    const wrapper = await mountDetail()

    const button = buttonNamed(wrapper, 'Refresh corporation assets')
    await button?.trigger('click')
    await flushPromises()

    expect([callsTo(CORPORATION_ASSETS_REFRESH_PATH), buttonNamed(wrapper, 'Refresh corporation assets')?.attributes('disabled')]).toEqual([1, undefined])
  })

  it('shows nothing to check when nobody delivers', async () => {
    answer({ fleetStorage: fleetStorage({ recipientMemberId: 'member-brann', recipientName: 'Brann Holt' }), verification: nothingDeliveredVerification() })

    const wrapper = await mountDetail()

    expect(fleetStorageCard(wrapper)?.text().includes('nothing to check')).toBe(true)
  })
})

describe('MiningOperationDetail, fleet storage of frozen shares', () => {
  it('lists the debt of the recipient among the payments and keeps the fleet storage card read only', async () => {
    answer({ operation: fs2SharesOperation(), valuation: fs2FrozenValuation(), fleetStorage: fs2FleetStorage({ editable: false }), payments: fs2Payments() })

    const wrapper = await mountDetail()

    const paymentsText = sectionTitled(wrapper, 'Payments')?.text() ?? ''
    const card = fleetStorageCard(wrapper)
    expect({
      line: paymentsText.includes('Brann Holt owes 1,691,091 ISK to Aria Solberg'),
      reason: paymentsText.includes('fleet storage: holds the delivered ore, owes their share minus what they kept'),
      rule: paymentsText.includes('The fleet storage recipient owes each depositor their share minus what they kept.'),
      card: card !== undefined,
      recipientList: card?.find('select').exists() ?? null,
      enabledButtons: (card?.findAll('[role="group"] button') ?? []).filter(button => button.attributes('disabled') === undefined).length,
      debt: card?.text().includes('Brann Holt owes them 1,691,091 ISK') ?? false,
    }).toEqual({ line: true, reason: true, rule: true, card: true, recipientList: false, enabledButtons: 0, debt: true })
  })
})

describe('MiningOperationDetail, each for themselves', () => {
  it('shows no fleet storage card and every participant holding his own ore', async () => {
    answer({
      operation: fs2ClosingOperation({ splitMode: 'each_for_themselves', valuationSettings: eachForThemselvesE1Valuation().settings, fleetStorageRecipientName: null }),
      valuation: eachForThemselvesE1Valuation(),
      fleetStorage: fleetStorage({ available: false, editable: false }),
      verification: nothingDeliveredVerification(),
    })

    const wrapper = await mountDetail()

    expect({
      card: fleetStorageCard(wrapper) !== undefined,
      holders: ['Aria Solberg', 'Brann Holt', 'Cyra Lune'].map(name => shareRowOf(wrapper, name)?.text().includes('themselves') ?? false),
      rule: sectionTitled(wrapper, 'Rules')?.text().includes('none (everyone keeps their own)') ?? false,
    }).toEqual({ card: false, holders: [true, true, true], rule: true })
  })
})

describe.each(TEST_LOCALES)('MiningOperationDetail, fleet storage texts in %s', locale => {
  it('shows only the texts of the locale', async () => {
    answer()
    const closing = await mountDetail(locale)
    expectOnlyLocaleTexts(closing.text(), locale, [
      'miningOperation.fleetStorage.recipient',
      'miningOperation.fleetStorage.container',
      'miningOperation.fleetStorage.containerHint',
      'miningOperation.fleetStorage.delivery.none',
      'miningOperation.fleetStorage.delivery.all',
      'miningOperation.fleetStorage.delivery.partial',
      'miningOperation.fleetStorage.keeps',
      'miningOperation.fleetStorage.hold',
      'miningOperation.fleetStorage.verification.status.verified',
      'miningOperation.fleetStorage.verification.noEffect',
      'miningOperation.fleetStorage.holder.self',
      'miningOperation.fleetStorage.holder.recipient',
      'miningOperation.shares.columns.holder',
      'miningOperation.rules.fleetStorage',
    ])
    closing.unmount()

    answer({ verification: shortVerification() })
    const short = await mountDetail(locale)
    expectOnlyLocaleTexts(short.text(), locale, ['miningOperation.fleetStorage.verification.refresh'])
    short.unmount()

    answer({ operation: fs2SharesOperation(), valuation: fs2FrozenValuation(), fleetStorage: fs2FleetStorage({ editable: false }), payments: fs2Payments() })
    const shares = await mountDetail(locale)
    expectOnlyLocaleTexts(shares.text(), locale, ['miningOperation.payments.kind.fleet_storage_share', 'miningOperation.payments.ruleFleetStorage'])
  })
})
