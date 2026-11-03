import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useMiningOperationStore, type MiningOperation, type MiningOperationValuation } from '@/stores/miningOperation'
import { useSyncStore, type MiningOperationEvent, type SyncProgress } from '@/stores/sync'
import { expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  DETAIL_PATH,
  ENDED_AT,
  LEDGER_PATH,
  MEMBERS_PATH,
  OPERATION_O,
  OPERATION_P2,
  ledger,
  spodumainOperation,
  startedSpodumainOperation,
} from './miningOperationLedgerFixtures'
import {
  CYRA_BOOSTING_ON_JITA_SELL,
  FROZEN_AT,
  MEMBERS_WITH_CYRA,
  REPROCESSING_PROFILE_PATH,
  VALUATION_PATH,
  e1Participants,
  e1Valuation,
  e1ValuationWithBrannUnknown,
  eachForThemselvesE1Valuation,
  frozenE1Valuation,
} from './miningOperationValuationFixtures'

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

const SNAPSHOT_SYNC_TYPE = 'mining-operation-snapshot'
const LONG_PILOT_NAME = 'Aria Solberg of the Very Long Named Deep Core Mining Directorate'

const events = reactive<Record<string, MiningOperationEvent | null>>({})

function closingOperation(overrides: Partial<MiningOperation> = {}): MiningOperation {
  return startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT, participantsCount: 3, charactersCount: 5, valuationSettings: CYRA_BOOSTING_ON_JITA_SELL, ...overrides })
}

function frozenOperation(): MiningOperation {
  return closingOperation({ status: 'shares', sharesFrozenAt: FROZEN_AT })
}

function answer(operation: MiningOperation, valuation: MiningOperationValuation, reprocessYield: number | null = null): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
    if (endpoint === LEDGER_PATH) return ledger({ status: operation.status, endedAt: operation.endedAt }) as never
    if (endpoint === VALUATION_PATH) return valuation as never
    if (endpoint === REPROCESSING_PROFILE_PATH) return { configured: reprocessYield !== null, reprocessYield, oreProcessingSkills: [] } as never
    return operation as never
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

function hasHeading(wrapper: VueWrapper, title: string): boolean {
  return wrapper.findAll('h2').some(heading => heading.text().trim() === title)
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

function shareTable(wrapper: VueWrapper): DOMWrapper<Element> | undefined {
  return wrapper.findAll('table').find(table => table.text().includes('Mined value'))
}

function shareRowOf(wrapper: VueWrapper, participantName: string): DOMWrapper<Element> | undefined {
  return shareTable(wrapper)?.findAll('tr').find(row => row.text().includes(participantName))
}

function inputLabelled(wrapper: VueWrapper, labelText: string): DOMWrapper<HTMLInputElement> | undefined {
  const label = wrapper.findAll('label').find(candidate => candidate.text().includes(labelText))
  if (label === undefined) return undefined
  const target = label.attributes('for')
  const input = target === undefined ? label.find('input') : wrapper.find(`#${target}`)
  return input.exists() ? (input as DOMWrapper<HTMLInputElement>) : undefined
}

function stepLabels(wrapper: VueWrapper): string[] {
  const stepper = wrapper.findAll('ol').find(list => list.text().includes('Planned'))
  return (stepper?.findAll('li') ?? []).map(step => step.text().trim())
}

function currentStep(wrapper: VueWrapper): string | undefined {
  return wrapper.find('[aria-current="step"]').exists() ? wrapper.find('[aria-current="step"]').text().trim() : undefined
}

async function receiveOperationEvent(operationId: string, action: string): Promise<void> {
  events[operationId] = { action, data: {}, timestamp: '2026-11-05T21:55:00+00:00' }
  await nextTick()
  await flushPromises()
}

async function receiveSnapshotProgress(operationId: string, status: SyncProgress['status']): Promise<void> {
  const syncStore = useSyncStore()
  syncStore.syncStatus = {
    ...syncStore.syncStatus,
    [SNAPSHOT_SYNC_TYPE]: {
      syncType: SNAPSHOT_SYNC_TYPE,
      status,
      progress: 100,
      message: null,
      data: { operationId, operationCharacterId: 'enrolment-Brann II', characterName: 'Brann II', kind: 'end', status: 'taken', readyAt: '2026-11-05T21:42:00+00:00', settled: 5, total: 5 },
      timestamp: '2026-11-05T21:42:01+00:00',
    },
  }
  await nextTick()
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, closing preview of the value prorata split', () => {
  it('shows the mined value, the percent of G, the share and the ISK/h of Aria and the booster cut on the line of Cyra', async () => {
    answer(closingOperation(), e1Valuation())

    const wrapper = await mountDetail()

    const ariaRow = shareRowOf(wrapper, 'Aria Solberg')?.text() ?? ''
    const cyraRow = shareRowOf(wrapper, 'Cyra Lune')?.text() ?? ''
    expect([
      ['2,699,093', '48.5', '2,429,184', '1,799,395'].every(value => ariaRow.includes(value)),
      ['Booster', '+ booster 556,773', '158'].every(value => cyraRow.includes(value)),
    ]).toEqual([true, true])
  })

  it('checks that the shares and the cuts add up to G with the rounding remainder', async () => {
    answer(closingOperation(), e1Valuation())

    const wrapper = await mountDetail()

    expect([
      wrapper.text().includes('Σ shares 5,010,956 + booster 556,773 + corporation 0 = 5,567,729 = G ✓'),
      wrapper.text().includes('rounding remainder 2 ISK'),
    ]).toEqual([true, true])
  })

  it('lists the unit prices read on the valuation basis', async () => {
    answer(closingOperation(), e1Valuation())

    const wrapper = await mountDetail()

    const pricesCard = wrapper.findAll('section').find(section => section.text().includes('Unit prices read'))?.text() ?? ''
    expect([pricesCard.includes('Bitumens'), pricesCard.includes('221.45'), pricesCard.includes('Veldspar'), pricesCard.includes('19.61')]).toEqual([true, true, true, true])
  })

  it('shows the unknown value of Brann and every share as unknown, never as 0, and blocks the freeze with his name', async () => {
    answer(closingOperation(), e1ValuationWithBrannUnknown())

    const wrapper = await mountDetail()

    const brannRow = shareRowOf(wrapper, 'Brann Holt')?.text() ?? ''
    const ariaRow = shareRowOf(wrapper, 'Aria Solberg')?.text() ?? ''
    const freezeButton = buttonNamed(wrapper, 'Freeze shares')
    expect({
      brannUnknown: brannRow.includes('unknown'),
      brannNeverZero: /(^|\s)0(\s|$)/.test(brannRow),
      ariaKeepsHerValue: ariaRow.includes('2,699,093'),
      ariaShareUnknown: ariaRow.includes('unknown'),
      banner: wrapper.text().includes('Total value and shares unknown. Nothing is counted as 0.'),
      check: wrapper.text().includes('check impossible: G unknown'),
      freezeDisabled: freezeButton?.attributes('disabled') !== undefined,
      blocker: wrapper.text().includes('Unknown or still measured quantity: Brann Holt'),
    }).toEqual({
      brannUnknown: true,
      brannNeverZero: false,
      ariaKeepsHerValue: true,
      ariaShareUnknown: true,
      banner: true,
      check: true,
      freezeDisabled: true,
      blocker: true,
    })
  })

  it('keeps long participant names truncated with a tooltip and their amounts intact', async () => {
    const [aria, brann, cyra] = e1Participants()
    answer(closingOperation(), e1Valuation({ participants: [{ ...aria, characterName: LONG_PILOT_NAME }, brann, cyra] }))

    const wrapper = await mountDetail()

    const longNameRow = shareRowOf(wrapper, '2,699,093')
    const nameCell = longNameRow?.find(`[title="${LONG_PILOT_NAME}"]`)
    expect([nameCell?.exists() ?? false, nameCell?.classes().includes('truncate') ?? false, longNameRow?.text().includes('2,429,184') ?? false]).toEqual([true, true, true])
  })

  it('marks Closing as the current step of the five value prorata steps', async () => {
    answer(closingOperation(), e1Valuation())

    const wrapper = await mountDetail()

    expect([stepLabels(wrapper), currentStep(wrapper)]).toEqual([['Planned', 'In progress', 'Closing', 'Shares', 'Settled'], 'Closing'])
  })

  it('shows the valuation basis, the booster cut and the booster in the rules card', async () => {
    answer(closingOperation(), e1Valuation())

    const wrapper = await mountDetail()

    const rules = wrapper.findAll('section').find(section => section.find('h2').exists() && section.find('h2').text().trim() === 'Rules')?.text() ?? ''
    expect(['Compressed, Jita Sell', 'Booster cut', 'Cyra Lune'].every(text => rules.includes(text))).toBe(true)
  })
})

describe('MiningOperationDetail, valuation settings', () => {
  it('prefills the reprocessing yield from the profile and saves only the changed settings as a merge patch', async () => {
    answer(spodumainOperation(), e1Valuation({ status: 'planned', settings: CYRA_BOOSTING_ON_JITA_SELL }), 0.72358)
    const store = useMiningOperationStore()
    const updateValuation = vi.spyOn(store, 'updateValuation').mockResolvedValue(null)
    const wrapper = await mountDetail()

    const reprocessedRadio = wrapper.find('input[type="radio"][value="reprocessed"]')
    expect(reprocessedRadio.exists()).toBe(true)
    await reprocessedRadio.setValue(true)
    await flushPromises()
    const yieldInput = inputLabelled(wrapper, 'Reprocessing yield')
    const prefilled = yieldInput?.element.value
    await buttonNamed(wrapper, 'Save settings')?.trigger('click')
    await flushPromises()

    expect([prefilled, updateValuation.mock.calls]).toEqual(['72.36', [[OPERATION_O, { valuationForm: 'reprocessed', reprocessYield: 0.72358 }]]])
  })

  it('shows the settings read only to a plain member without the freeze card', async () => {
    answer(closingOperation({ myRole: 'member' }), e1Valuation({ canFreeze: false }))

    const wrapper = await mountDetail()

    const radios = wrapper.findAll('input[type="radio"]')
    expect({
      valuationCard: hasHeading(wrapper, 'Valuation'),
      saveButton: buttonNamed(wrapper, 'Save settings') !== undefined,
      everyRadioDisabled: radios.length > 0 && radios.every(radio => radio.attributes('disabled') !== undefined),
      freezeButton: buttonNamed(wrapper, 'Freeze shares') !== undefined,
    }).toEqual({ valuationCard: true, saveButton: false, everyRadioDisabled: true, freezeButton: false })
  })
})

describe('MiningOperationDetail, each for themselves', () => {
  it('shows the mined values without share column, cuts disabled at 0, four steps and no freeze', async () => {
    answer(closingOperation({ splitMode: 'each_for_themselves', valuationSettings: eachForThemselvesE1Valuation().settings }), eachForThemselvesE1Valuation())

    const wrapper = await mountDetail()

    const headers = (shareTable(wrapper)?.findAll('th') ?? []).map(header => header.text().trim())
    const boosterCut = inputLabelled(wrapper, 'Booster cut')
    const corporationCut = inputLabelled(wrapper, 'Corporation cut')
    expect({
      title: wrapper.text().includes('Mined value by participant'),
      banner: wrapper.text().includes('Everyone keeps their own ore: no share, no payment.'),
      shareColumn: headers.includes('Share'),
      cuts: [boosterCut?.element.value, boosterCut?.attributes('disabled') !== undefined, corporationCut?.element.value, corporationCut?.attributes('disabled') !== undefined],
      cutHelp: wrapper.text().includes('no cut when everyone keeps their own'),
      steps: stepLabels(wrapper),
      freezeButton: buttonNamed(wrapper, 'Freeze shares') !== undefined,
      ariaValue: shareRowOf(wrapper, 'Aria Solberg')?.text().includes('2,699,093') ?? false,
    }).toEqual({
      title: true,
      banner: true,
      shareColumn: false,
      cuts: ['0', true, '0', true],
      cutHelp: true,
      steps: ['Planned', 'In progress', 'Closing', 'Completed'],
      freezeButton: false,
      ariaValue: true,
    })
  })
})

describe('MiningOperationDetail, freezing the shares', () => {
  it('freezes the shares once confirmed and reloads the operation and its valuation on shares_frozen', async () => {
    answer(closingOperation(), e1Valuation())
    const store = useMiningOperationStore()
    const freezeShares = vi.spyOn(store, 'freezeShares').mockResolvedValue(frozenOperation())
    const wrapper = await mountDetail()

    await buttonNamed(wrapper, 'Freeze shares')?.trigger('click')
    await flushPromises()
    const confirmationShown = wrapper.text().includes('Freeze the shares now? Settings can no longer change.')
    const confirmButton = wrapper.findAll('button').filter(button => ['Freeze shares', 'Confirm'].includes(button.text().trim())).pop()
    await confirmButton?.trigger('click')
    await flushPromises()
    vi.mocked(apiRequest).mockClear()
    await receiveOperationEvent(OPERATION_O, 'shares_frozen')

    expect([confirmationShown, freezeShares.mock.calls, callsTo(DETAIL_PATH) >= 1, callsTo(VALUATION_PATH) >= 1]).toEqual([true, [[OPERATION_O]], true, true])
  })

  it('shows the frozen shares with their totals, the rounding marks and no more settings nor correction', async () => {
    answer(frozenOperation(), frozenE1Valuation())

    const wrapper = await mountDetail()

    expect({
      banner: wrapper.text().includes('Shares frozen on') && wrapper.text().includes('Quantities, prices and shares no longer change.'),
      kpis: ['Total value G', '5,567,729', 'Booster cut, 10 %', '556,773', 'To share, 90 %', '5,010,956'].every(text => wrapper.text().includes(text)),
      title: wrapper.text().includes('Value prorata split') && !wrapper.text().includes('Value prorata split preview'),
      frozenPrices: wrapper.text().includes('Frozen unit prices'),
      roundingMarks: [shareRowOf(wrapper, 'Aria Solberg')?.text().includes('+1') ?? false, shareRowOf(wrapper, 'Brann Holt')?.text().includes('+1') ?? true],
      settingsCard: hasHeading(wrapper, 'Valuation') || buttonNamed(wrapper, 'Save settings') !== undefined || wrapper.findAll('input[type="radio"]').length > 0,
      correction: buttonNamed(wrapper, 'Correct a quantity') !== undefined,
      currentStep: currentStep(wrapper),
    }).toEqual({
      banner: true,
      kpis: true,
      title: true,
      frozenPrices: true,
      roundingMarks: [true, false],
      settingsCard: false,
      correction: false,
      currentStep: 'Shares',
    })
  })
})

describe('MiningOperationDetail, live valuation', () => {
  it('reloads the valuation once for a valuation_updated of the displayed operation and not for another operation', async () => {
    answer(closingOperation(), e1Valuation())
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await receiveOperationEvent(OPERATION_O, 'valuation_updated')
    await receiveOperationEvent(OPERATION_P2, 'valuation_updated')

    expect(callsTo(VALUATION_PATH)).toBe(1)
  })

  it.each(['quantity_corrected', 'operation_ended', 'member_departed'])('reloads the valuation on %s', async action => {
    answer(closingOperation(), e1Valuation())
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await receiveOperationEvent(OPERATION_O, action)

    expect(callsTo(VALUATION_PATH)).toBe(1)
  })

  it('reloads the valuation when a snapshot of the displayed operation is read', async () => {
    answer(closingOperation(), e1Valuation())
    await mountDetail()
    vi.mocked(apiRequest).mockClear()

    await receiveSnapshotProgress(OPERATION_O, 'completed')
    await receiveSnapshotProgress(OPERATION_P2, 'completed')

    expect(callsTo(VALUATION_PATH)).toBe(1)
  })
})

describe.each(TEST_LOCALES)('MiningOperationDetail, valuation texts in %s', locale => {
  it('shows only the texts of the locale', async () => {
    answer(closingOperation(), e1Valuation())
    const closing = await mountDetail(locale)
    expectOnlyLocaleTexts(closing.text(), locale, [
      'miningOperation.steps.planned',
      'miningOperation.steps.in_progress',
      'miningOperation.steps.closing',
      'miningOperation.steps.shares',
      'miningOperation.steps.settled',
      'miningOperation.valuation.title',
      'miningOperation.valuation.form.label',
      'miningOperation.valuation.form.raw',
      'miningOperation.valuation.form.compressed',
      'miningOperation.valuation.form.reprocessed',
      'miningOperation.valuation.market.label',
      'miningOperation.valuation.market.jita',
      'miningOperation.valuation.market.structure',
      'miningOperation.valuation.side.label',
      'miningOperation.valuation.side.buy',
      'miningOperation.valuation.side.sell',
      'miningOperation.valuation.boosterCut',
      'miningOperation.valuation.corporationCut',
      'miningOperation.valuation.save',
      'miningOperation.shares.previewTitle',
      'miningOperation.shares.columns.quantity',
      'miningOperation.shares.columns.minedValue',
      'miningOperation.shares.columns.valuePercent',
      'miningOperation.shares.columns.iskPerHour',
      'miningOperation.shares.reread',
      'miningOperation.shares.unitPricesRead',
      'miningOperation.freeze.title',
      'miningOperation.freeze.hint',
    ])
    closing.unmount()

    answer(closingOperation({ splitMode: 'each_for_themselves', valuationSettings: eachForThemselvesE1Valuation().settings }), eachForThemselvesE1Valuation())
    const eachForThemselves = await mountDetail(locale)
    expectOnlyLocaleTexts(eachForThemselves.text(), locale, [
      'miningOperation.steps.completed',
      'miningOperation.shares.valueTitle',
      'miningOperation.shares.eachForThemselvesBanner',
      'miningOperation.valuation.noCutEachForThemselves',
      'miningOperation.valuation.ruleEachForThemselves',
    ])
    eachForThemselves.unmount()

    answer(closingOperation(), e1ValuationWithBrannUnknown())
    const unknown = await mountDetail(locale)
    expectOnlyLocaleTexts(unknown.text(), locale, ['miningOperation.shares.checkUnknown'])
    unknown.unmount()

    answer(frozenOperation(), frozenE1Valuation())
    const frozen = await mountDetail(locale)
    expectOnlyLocaleTexts(frozen.text(), locale, [
      'miningOperation.status.shares',
      'miningOperation.shares.title',
      'miningOperation.shares.unitPricesFrozen',
      'miningOperation.shares.kpi.total',
      'miningOperation.shares.kpi.iskPerHour',
    ])
  })
})
