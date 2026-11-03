import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import type { MiningOperation, MiningOperationLedger } from '@/stores/miningOperation'
import { expectOnlyLocaleTexts, TEST_LOCALES, type TestLocale } from '../../localeTexts'
import {
  BRANN_II_ENROLMENT_ID,
  DETAIL_PATH,
  ENDED_AT,
  LEDGER_PATH,
  MEMBERS,
  MEMBERS_PATH,
  OPERATION_O,
  S1,
  SPODUMAIN_TYPE_ID,
  STARTED_AT,
  ledger,
  ledgerCharacter,
  participant,
  snapshot,
  spodumainOperation,
  startedSpodumainOperation,
} from './miningOperationLedgerFixtures'

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

const ALDRANETTE_SEARCH = [{ solarSystemId: S1, solarSystemName: 'Aldranette', security: 0.8, regionName: 'Sinq Laison' }]

function answer(operation: MiningOperation, operationLedger: MiningOperationLedger): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS as never
    if (endpoint === LEDGER_PATH) return operationLedger as never
    if (endpoint.startsWith('/sde/solar-systems')) return ALDRANETTE_SEARCH as never
    return operation as never
  })
}

async function mountDetail(locale: TestLocale = 'en'): Promise<VueWrapper> {
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

function callsTo(path: string) {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path)
}

function brannWithBrannIIToReconnect(): MiningOperationLedger {
  return ledger({
    participants: [
      participant('member-aria', 'Aria Solberg', [ledgerCharacter('Aria II'), ledgerCharacter('Aria Solberg')]),
      participant('member-brann', 'Brann Holt', [
        ledgerCharacter('Brann Holt'),
        ledgerCharacter('Brann II', {
          id: BRANN_II_ENROLMENT_ID,
          state: 'unknown',
          unknownReason: 'reconnect_required',
          startSnapshot: snapshot('reconnect_required', STARTED_AT, STARTED_AT),
          canRetry: true,
        }),
      ], { state: 'unknown' }),
    ],
  })
}

function closingLedgerWhereBrannIIIsUnknown(): MiningOperationLedger {
  const endTaken = snapshot('taken', ENDED_AT, '2026-11-05T21:37:17+00:00', '2026-11-05T21:37:18+00:00')
  return ledger({
    status: 'closing',
    endedAt: ENDED_AT,
    endSnapshotsSettled: 3,
    endSnapshotsTotal: 3,
    participants: [
      participant('member-aria', 'Aria Solberg', [
        ledgerCharacter('Aria II', { state: 'known', totalQuantity: 6_017, endSnapshot: endTaken, quantities: [{ typeId: SPODUMAIN_TYPE_ID, typeName: 'Spodumain', quantity: 6_017, measuredQuantity: 6_017, correction: null }] }),
        ledgerCharacter('Aria Solberg', { state: 'known', totalQuantity: 0, endSnapshot: endTaken }),
      ], { state: 'known', totalQuantity: 6_017, quantities: [{ typeId: SPODUMAIN_TYPE_ID, typeName: 'Spodumain', quantity: 6_017 }] }),
      participant('member-brann', 'Brann Holt', [
        ledgerCharacter('Brann Holt', { state: 'known', totalQuantity: 35_500, endSnapshot: endTaken, quantities: [{ typeId: SPODUMAIN_TYPE_ID, typeName: 'Spodumain', quantity: 35_500, measuredQuantity: 35_500, correction: null }] }),
        ledgerCharacter('Brann II', { state: 'unknown', unknownReason: 'reconnect_required', startSnapshot: snapshot('reconnect_required', STARTED_AT, STARTED_AT) }),
      ], { state: 'unknown' }),
    ],
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, start', () => {
  it('offers to start a planned operation to its owner only', async () => {
    answer(spodumainOperation(), ledger({ status: 'planned', startedAt: null, solarSystemId: null, solarSystemName: null }))
    const ownerView = await mountDetail()
    const ownerSeesStart = buttonNamed(ownerView, 'Start operation') !== undefined
    ownerView.unmount()

    answer(spodumainOperation({ myRole: 'member' }), ledger({ status: 'planned', startedAt: null, solarSystemId: null, solarSystemName: null }))
    const memberView = await mountDetail()

    expect([ownerSeesStart, buttonNamed(memberView, 'Start operation') !== undefined]).toEqual([true, false])
  })

  it('starts the operation in the solar system picked in the start window', async () => {
    answer(spodumainOperation(), ledger({ status: 'planned' }))
    const wrapper = await mountDetail()
    expect(buttonNamed(wrapper, 'Start operation')).toBeDefined()

    await buttonNamed(wrapper, 'Start operation')?.trigger('click')
    await wrapper.find('input[name="solarSystem"]').setValue('Aldranette')
    await vi.waitFor(() => expect(buttonNamed(wrapper, 'Aldranette')).toBeDefined())
    await buttonNamed(wrapper, 'Aldranette')?.trigger('click')
    await buttonNamed(wrapper, 'Start')?.trigger('click')
    await flushPromises()

    expect(callsTo(`/mining-operations/${OPERATION_O}/start`)).toEqual([[`/mining-operations/${OPERATION_O}/start`, { method: 'POST', body: JSON.stringify({ solarSystemId: S1 }) }]])
  })

  it('starts the operation without solar system when none is picked', async () => {
    answer(spodumainOperation(), ledger({ status: 'planned' }))
    const wrapper = await mountDetail()
    expect(buttonNamed(wrapper, 'Start operation')).toBeDefined()

    await buttonNamed(wrapper, 'Start operation')?.trigger('click')
    await buttonNamed(wrapper, 'Start')?.trigger('click')
    await flushPromises()

    expect(callsTo(`/mining-operations/${OPERATION_O}/start`)).toEqual([[`/mining-operations/${OPERATION_O}/start`, { method: 'POST', body: JSON.stringify({ solarSystemId: null }) }]])
  })
})

describe('MiningOperationDetail, operation in progress', () => {
  it('lets a participant leave early but only an administrator end the operation', async () => {
    answer(startedSpodumainOperation({ myRole: 'member' }), ledger())
    const brannView = await mountDetail()
    const brannButtons = [buttonNamed(brannView, "I'm leaving") !== undefined, buttonNamed(brannView, 'End operation') !== undefined]
    brannView.unmount()

    answer(startedSpodumainOperation(), ledger())
    const ariaView = await mountDetail()

    expect([brannButtons, buttonNamed(ariaView, 'End operation') !== undefined]).toEqual([[true, false], true])
  })

  it('shows the start snapshots of each participant and offers to retry the one to reconnect', async () => {
    answer(startedSpodumainOperation({ myRole: 'member' }), brannWithBrannIIToReconnect())
    const wrapper = await mountDetail()

    const text = wrapper.text()
    expect(text).toContain('✓ start 2/2')
    expect(text).toContain('✗ start 1/2')
    expect(text).toContain('Brann II needs reconnecting: start snapshot not read, quantity unknown')

    await buttonNamed(wrapper, 'Retry')?.trigger('click')
    await flushPromises()

    expect(callsTo(`/mining-operations/${OPERATION_O}/characters/${BRANN_II_ENROLMENT_ID}/snapshot-retry`)).toHaveLength(1)
  })
})

describe('MiningOperationDetail, closing', () => {
  it('waits for the end ledger until its read time with a countdown', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(new Date('2026-11-05T21:31:00Z'))
    answer(
      startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT }),
      ledger({ status: 'closing', endedAt: ENDED_AT, endSnapshotsReadyAt: '2026-11-05T21:39:50+00:00', endSnapshotsTotal: 4 }),
    )
    const wrapper = await mountDetail()

    const text = wrapper.text()
    expect(text).toContain('Waiting for the end ledger')
    expect(text).toContain("The operation ended at 21:30. ESI caches the mining ledger for 10 min: each character's end snapshot will be read at 21:39.")
    expect(text).toContain('read in')
    expect(text).toContain('08:50')

    vi.advanceTimersByTime(10_000)
    await flushPromises()

    expect(wrapper.text()).toContain('08:40')
  })

  it('says the read time is being worked out while an end snapshot is still scheduling', async () => {
    answer(
      startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT }),
      ledger({ status: 'closing', endedAt: ENDED_AT, endSnapshotsPending: true, endSnapshotsTotal: 4 }),
    )
    const wrapper = await mountDetail()

    expect(wrapper.text()).toContain('working out the read time…')
  })

  it('shows an unknown quantity as unknown and never as zero', async () => {
    answer(startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT }), closingLedgerWhereBrannIIIsUnknown())
    const wrapper = await mountDetail()

    const text = wrapper.text()
    expect(text).toContain('Brann Holt has an unknown quantity: it is not counted as 0. An owner or admin can correct it.')
    expect(text).toContain('Mined quantities')
    expect(text.match(/\bunknown\b/g)?.length ?? 0).toBeGreaterThanOrEqual(2)
    expect(text).not.toMatch(/\b0\s?u\b/)
  })

  it('marks a corrected quantity with its author, time and measured value', async () => {
    const corrected = closingLedgerWhereBrannIIIsUnknown()
    corrected.participants[1].characters[0].quantities = [{
      typeId: SPODUMAIN_TYPE_ID,
      typeName: 'Spodumain',
      quantity: 33_000,
      measuredQuantity: 35_500,
      correction: { originalQuantity: 35_500, correctedQuantity: 33_000, correctedByName: 'Aria Solberg', correctedAt: '2026-11-05T21:52:00+00:00' },
    }]
    answer(startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT }), corrected)
    const wrapper = await mountDetail()

    const correctedMark = wrapper.findAll('[title]').find(element => element.text().includes('corrected'))
    expect(correctedMark).toBeDefined()
    expect(correctedMark?.attributes('title') ?? '').toMatch(/^Aria Solberg, 21:52 · measured: 35[\s,.\u00a0\u202f]?500$/)
  })
})

describe('MiningOperationDetail, lifecycle texts', () => {
  it.each(TEST_LOCALES)('shows the start window and the in-progress actions only in %s', async locale => {
    answer(spodumainOperation(), ledger({ status: 'planned' }))
    const planned = await mountDetail(locale)
    await planned.findAll('button').find(button => button.text().trim() === (locale === 'fr' ? "Démarrer l'opération" : 'Start operation'))?.trigger('click')
    expectOnlyLocaleTexts(planned.text(), locale, ['miningOperation.start.action', 'miningOperation.start.hint', 'miningOperation.start.system', 'miningOperation.start.submit'])
    planned.unmount()

    answer(startedSpodumainOperation({ status: 'closing', endedAt: ENDED_AT }), closingLedgerWhereBrannIIIsUnknown())
    const closing = await mountDetail(locale)
    expectOnlyLocaleTexts(closing.text(), locale, [
      'miningOperation.quantities.title',
      'miningOperation.closing.snapshots',
      'miningOperation.closing.departures',
      'miningOperation.closing.noDeparture',
      'miningOperation.correction.action',
      'miningOperation.correction.card',
    ])
  })
})
