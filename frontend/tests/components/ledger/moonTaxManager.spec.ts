import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { apiRequest, ApiError, ValidationError, authFetch } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import { DENIED_CORPORATION, LONG_STRUCTURE_NAME, response, SYNCED_CORPORATION } from './moonChunksHarness'
import {
  CANCELLED_STATEMENT,
  CORPORATION_A,
  CORPORATION_B,
  DEEP_CORE_STRUCTURE_ID,
  DUE_STATEMENT,
  LATE_STATEMENT,
  NOW,
  PAID_STATEMENT,
  PARTIAL_STATEMENT,
  PROVISIONAL_STATEMENT,
  ROCK_HOLLOW_STRUCTURE_ID,
  STRANGE_STATEMENT,
  UNKNOWN_STATEMENT,
  WALLET_SCOPE,
  corporationPath,
  countOf,
  defaultRoutes,
  moonTaxCorporation,
  mountMoonTax,
  present,
  requestLines,
  requests,
  respondWithCsv,
  section,
  statementCell,
  statementRow,
  statementRows,
  statementsPath,
  type MoonTaxWrapper,
} from './moonTaxHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

enableAutoUnmount(afterEach)

const DENIED_MESSAGE = 'The Accountant role is required to manage the moon tax of corporation 98000001. Roles read: Ilyra Voss (Director)'
const UNREADABLE_MESSAGE = 'The corporation roles of Ilyra Voss could not be read'
const RATE_FIELDS = ['rateR4', 'rateR8', 'rateR16', 'rateR32', 'rateR64'] as const
const ROCK_HOLLOW = `[data-refinery="${ROCK_HOLLOW_STRUCTURE_ID}"]`
const DEEP_CORE = `[data-refinery="${DEEP_CORE_STRUCTURE_ID}"]`

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  vi.mocked(authFetch).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

function field(wrapper: MoonTaxWrapper, refinery: string, name: string) {
  return present(wrapper, `${section(CORPORATION_A, refinery)} [data-field="${name}"]`)
}

function fieldValue(wrapper: MoonTaxWrapper, refinery: string, name: string): string {
  return (field(wrapper, refinery, name).element as HTMLInputElement).value
}

function isChecked(wrapper: MoonTaxWrapper, refinery: string, name: string): boolean {
  return (field(wrapper, refinery, name).element as HTMLInputElement).checked
}

function saveButton(wrapper: MoonTaxWrapper, refinery: string) {
  return present(wrapper, `${section(CORPORATION_A, refinery)} [data-save]`)
}

function columnOf(wrapper: MoonTaxWrapper, name: string, rowCount: number): string[] {
  return Array.from({ length: rowCount }, (_, index) => statementCell(wrapper, index, name).text())
}

function withStatements(statements: unknown[]): Record<string, unknown> {
  return { ...defaultRoutes(), [`GET ${statementsPath(CORPORATION_A)}`]: statements }
}

describe('MoonTaxTab access states', () => {
  it('shows the server message in a denied panel or an unreadable panel for each corporation, never an empty table', async () => {
    const wrapper = await mountMoonTax({
      ...defaultRoutes(response(SYNCED_CORPORATION, DENIED_CORPORATION)),
      [`GET ${corporationPath(CORPORATION_A)}`]: new ApiError(DENIED_MESSAGE, 403),
      [`GET ${corporationPath(CORPORATION_B)}`]: new ApiError(UNREADABLE_MESSAGE, 503),
    })

    expect(present(wrapper, section(CORPORATION_A, '[data-moon-tax-denied]')).text()).toContain(DENIED_MESSAGE)
    expect(present(wrapper, section(CORPORATION_B, '[data-moon-tax-unreadable]')).text()).toContain(UNREADABLE_MESSAGE)
    expect(wrapper.find(section(CORPORATION_A, '[data-moon-tax-unreadable]')).exists()).toBe(false)
    expect(wrapper.find(section(CORPORATION_B, '[data-moon-tax-denied]')).exists()).toBe(false)
    for (const corporationId of [CORPORATION_A, CORPORATION_B]) {
      expect(wrapper.findAll(section(corporationId, '[data-statement-row], [data-refinery], [data-save], [data-empty-statements]'))).toHaveLength(0)
    }
  })

  it('names the ledger access state, its last read date and the scope to grant again, and keeps the known statements', async () => {
    const wrapper = await mountMoonTax({
      ...withStatements([DUE_STATEMENT, PARTIAL_STATEMENT]),
      [`GET ${corporationPath(CORPORATION_A)}`]: moonTaxCorporation({
        access: { ledger: 'denied', payments: 'reconnect' },
        observersSyncedAt: '2026-10-28T19:40:00+00:00',
        walletSyncedAt: '2026-10-27T08:15:00+00:00',
      }),
    })
    const ledger = present(wrapper, section(CORPORATION_A, '[data-moon-tax-access="ledger"]'))
    const payments = present(wrapper, section(CORPORATION_A, '[data-moon-tax-access="payments"]'))

    expect([ledger.attributes('data-state'), payments.attributes('data-state')]).toEqual(['denied', 'reconnect'])
    expect(present(wrapper, section(CORPORATION_A, '[data-moon-tax-access="ledger"] [data-last-read]')).attributes('datetime')).toBe('2026-10-28T19:40:00+00:00')
    expect(present(wrapper, section(CORPORATION_A, '[data-moon-tax-access="ledger"] [data-last-read]')).text()).toContain('2026-10-28 19:40')
    expect(present(wrapper, section(CORPORATION_A, '[data-moon-tax-access="payments"] [data-last-read]')).attributes('datetime')).toBe('2026-10-27T08:15:00+00:00')
    expect(payments.text()).toContain(WALLET_SCOPE)
    expect(statementRows(wrapper)).toHaveLength(2)
    expect(wrapper.find(section(CORPORATION_A, '[data-empty-statements]')).exists()).toBe(false)
  })

  it('says there is no statement only for a ledger read successfully, never for a ledger not synced yet', async () => {
    const wrapper = await mountMoonTax({
      ...defaultRoutes(response(SYNCED_CORPORATION, DENIED_CORPORATION)),
      [`GET ${corporationPath(CORPORATION_A)}`]: moonTaxCorporation({
        access: { ledger: 'never_synced', payments: 'never_synced' },
        observersSyncedAt: null,
        walletSyncedAt: null,
      }),
      [`GET ${corporationPath(CORPORATION_B)}`]: moonTaxCorporation({ corporationId: CORPORATION_B, refineries: [] }),
      [`GET ${statementsPath(CORPORATION_B)}`]: [],
    })

    expect(present(wrapper, section(CORPORATION_A, '[data-moon-tax-access="ledger"]')).attributes('data-state')).toBe('never_synced')
    expect(wrapper.find(section(CORPORATION_A, '[data-empty-statements]')).exists()).toBe(false)
    expect(wrapper.find(section(CORPORATION_B, '[data-empty-statements]')).exists()).toBe(true)
    expect(wrapper.find(section(CORPORATION_B, '[data-moon-tax-access][data-state]')).exists()).toBe(false)
  })
})

describe('MoonTaxTab refinery settings', () => {
  it('loads each corporation, starts on the manager view and keeps the save disabled until every rate is filled', async () => {
    const wrapper = await mountMoonTax()

    expect([...requestLines()].sort()).toEqual([
      'GET /mining/corporation-extractions',
      `GET ${corporationPath(CORPORATION_A)}`,
      `GET ${statementsPath(CORPORATION_A)}`,
    ])
    expect([present(wrapper, '[data-moon-tax-view="manager"]').attributes('aria-pressed'), present(wrapper, '[data-moon-tax-view="miner"]').attributes('aria-pressed')]).toEqual(['true', 'false'])
    expect(present(wrapper, section(CORPORATION_A, ROCK_HOLLOW)).text()).toContain('NOL-M9 - Rock Hollow')
    expect(present(wrapper, section(CORPORATION_A, DEEP_CORE)).text()).toContain(LONG_STRUCTURE_NAME)
    expect([
      isChecked(wrapper, ROCK_HOLLOW, 'openToAlliance'),
      fieldValue(wrapper, ROCK_HOLLOW, 'period'),
      ...RATE_FIELDS.map(name => fieldValue(wrapper, ROCK_HOLLOW, name)),
      fieldValue(wrapper, ROCK_HOLLOW, 'walletDivision'),
    ]).toEqual([true, 'monthly', '5', '7.5', '10', '12.5', '15', '3'])
    expect([isChecked(wrapper, DEEP_CORE, 'openToAlliance'), fieldValue(wrapper, DEEP_CORE, 'period'), ...RATE_FIELDS.map(name => fieldValue(wrapper, DEEP_CORE, name))]).toEqual([
      false,
      'weekly',
      '',
      '',
      '',
      '',
      '',
    ])
    expect(saveButton(wrapper, ROCK_HOLLOW).attributes('disabled')).toBeUndefined()
    expect(saveButton(wrapper, DEEP_CORE).attributes('disabled')).toBeDefined()

    await field(wrapper, DEEP_CORE, 'walletDivision').setValue('2')
    for (const [name, value] of [['rateR4', '4'], ['rateR8', '6.5'], ['rateR32', '11.25'], ['rateR64', '14']]) {
      await field(wrapper, DEEP_CORE, name).setValue(value)
    }
    expect(saveButton(wrapper, DEEP_CORE).attributes('disabled')).toBeDefined()

    await field(wrapper, DEEP_CORE, 'rateR16').setValue('9')
    expect(saveButton(wrapper, DEEP_CORE).attributes('disabled')).toBeUndefined()
  })

  it('saves the eight fields of a refinery as numbers, a boolean and a string, then reloads the corporation', async () => {
    const savePath = `${corporationPath(CORPORATION_A)}/refineries/${DEEP_CORE_STRUCTURE_ID}`
    const wrapper = await mountMoonTax({ ...defaultRoutes(), [`PUT ${savePath}`]: {} })
    await field(wrapper, DEEP_CORE, 'openToAlliance').setValue(true)
    await field(wrapper, DEEP_CORE, 'period').setValue('monthly')
    await field(wrapper, DEEP_CORE, 'walletDivision').setValue('2')
    for (const [name, value] of [['rateR4', '4'], ['rateR8', '6.5'], ['rateR16', '9'], ['rateR32', '11.25'], ['rateR64', '14']]) {
      await field(wrapper, DEEP_CORE, name).setValue(value)
    }

    await saveButton(wrapper, DEEP_CORE).trigger('click')
    await flushPromises()

    const saves = requests().filter(request => request.method === 'PUT')
    expect(saves).toHaveLength(1)
    expect(saves[0].endpoint).toBe(savePath)
    expect(saves[0].body).toEqual({
      openToAlliance: true,
      period: 'monthly',
      rateR4: 4,
      rateR8: 6.5,
      rateR16: 9,
      rateR32: 11.25,
      rateR64: 14,
      walletDivision: 2,
    })
    expect(countOf(`GET ${corporationPath(CORPORATION_A)}`)).toBe(2)
  })

  it('shows the server validation message under the form and keeps the typed values when the save is refused', async () => {
    const savePath = `${corporationPath(CORPORATION_A)}/refineries/${ROCK_HOLLOW_STRUCTURE_ID}`
    const message = 'This value should be between 0 and 100.'
    const wrapper = await mountMoonTax({
      ...defaultRoutes(),
      [`PUT ${savePath}`]: new ValidationError({
        '@type': 'ConstraintViolationList',
        status: 422,
        violations: [{ propertyPath: 'rateR16', message }],
        description: `rateR16: ${message}`,
        detail: `rateR16: ${message}`,
      }),
    })
    await field(wrapper, ROCK_HOLLOW, 'rateR16').setValue('120')

    await saveButton(wrapper, ROCK_HOLLOW).trigger('click')
    await flushPromises()

    expect(present(wrapper, section(CORPORATION_A, `${ROCK_HOLLOW} [data-form-error]`)).text()).toContain(message)
    expect(wrapper.find(section(CORPORATION_A, `${DEEP_CORE} [data-form-error]`)).exists()).toBe(false)
    expect(fieldValue(wrapper, ROCK_HOLLOW, 'rateR16')).toBe('120')
    expect(fieldValue(wrapper, ROCK_HOLLOW, 'rateR4')).toBe('5')
    expect(countOf(`GET ${corporationPath(CORPORATION_A)}`)).toBe(1)
  })
})

describe('MoonTaxTab statements', () => {
  it('shows each statement with its character, refinery, period, reference, amounts and status, with explicit fallbacks', async () => {
    const wrapper = await mountMoonTax(withStatements([DUE_STATEMENT, LATE_STATEMENT, PAID_STATEMENT, PROVISIONAL_STATEMENT, STRANGE_STATEMENT, PARTIAL_STATEMENT]))
    const rowCount = 6

    expect(statementRows(wrapper)).toHaveLength(rowCount)
    expect(columnOf(wrapper, 'character', rowCount)).toEqual(['Dax Orlen', 'Mira Venn', '#2112000103', 'Dax Orlen', 'Dax Orlen', 'Dax Orlen'])
    expect(columnOf(wrapper, 'refinery', rowCount)).toEqual([
      'NOL-M9 - Rock Hollow',
      LONG_STRUCTURE_NAME,
      'Structure 1035000000999',
      'NOL-M9 - Rock Hollow',
      'NOL-M9 - Rock Hollow',
      'NOL-M9 - Rock Hollow',
    ])
    expect(columnOf(wrapper, 'tax', rowCount)).toEqual(['6.00 M', '3.50 M', '2.25 M', '1.50 M', '6.00 M', '6.00 M'])
    expect(columnOf(wrapper, 'paid', rowCount)).toEqual(['0', '0', '2.25 M', '0', '0', '4.00 M'])
    expect(columnOf(wrapper, 'remaining', rowCount)).toEqual(['6.00 M', '3.50 M', '0', '1.50 M', '6.00 M', '2.00 M'])
    expect([0, 1, 2, 4, 5].map(index => statementCell(wrapper, index, 'reference').text())).toEqual([
      'TAX-2026-W44-K9QM',
      'TAX-2026-W43-F2HN',
      'TAX-2026-W43-R8VB',
      'TAX-2026-W42-J5XM',
      'TAX-2026-W44-M3GS',
    ])
    expect(statementCell(wrapper, 3, 'reference').text()).not.toContain('TAX-')
    expect(statementCell(wrapper, 0, 'period').text()).toContain('2026-10-26')
    expect(statementCell(wrapper, 0, 'period').text()).toContain('2026-11-02')
    expect(statementCell(wrapper, 3, 'period').text()).toContain('2026-11-09')
    expect(statementCell(wrapper, 3, 'period').text()).toContain('2026-11-16')
    expect(Array.from({ length: rowCount }, (_, index) => statementCell(wrapper, index, 'status').attributes('data-status'))).toEqual([
      'due',
      'late',
      'paid',
      'provisional',
      'frozen_by_audit',
      'partial',
    ])
    const knownLabels = [0, 1, 2, 3, 5].map(index => statementCell(wrapper, index, 'status').text())
    expect(knownLabels.every(label => label.length > 0)).toBe(true)
    expect(new Set(knownLabels).size).toBe(knownLabels.length)
    expect(statementCell(wrapper, 4, 'status').text()).toContain('frozen_by_audit')
    expect(statementCell(wrapper, 0, 'outside').text()).toBe('')
    expect(statementCell(wrapper, 5, 'outside').text().length).toBeGreaterThan(0)
  })

  it('shows an unknown tax with the type ids without price and totals that never present a partial sum as complete', async () => {
    const wrapper = await mountMoonTax(withStatements([PARTIAL_STATEMENT, UNKNOWN_STATEMENT, CANCELLED_STATEMENT]))

    expect(statementCell(wrapper, 0, 'tax').attributes('data-unknown')).toBeUndefined()
    expect(statementCell(wrapper, 1, 'tax').attributes('data-unknown')).toBeDefined()
    expect(statementCell(wrapper, 1, 'tax').text()).toContain('1, 2')
    expect(statementCell(wrapper, 1, 'remaining').attributes('data-unknown')).toBeDefined()
    expect(statementCell(wrapper, 1, 'remaining').text()).toContain('1, 2')

    const due = present(wrapper, section(CORPORATION_A, '[data-total="due"]'))
    const remaining = present(wrapper, section(CORPORATION_A, '[data-total="remaining"]'))
    const paid = present(wrapper, section(CORPORATION_A, '[data-total="paid"]'))
    const outside = present(wrapper, section(CORPORATION_A, '[data-total="outside"]'))
    expect([due.attributes('data-unknown') !== undefined, remaining.attributes('data-unknown') !== undefined, paid.attributes('data-unknown') !== undefined]).toEqual([true, true, false])
    expect(due.get('[data-known-part]').text()).toContain('6.00 M')
    expect(remaining.get('[data-known-part]').text()).toContain('2.00 M')
    expect(paid.text()).toContain('4.00 M')
    expect(paid.text()).not.toContain('5.00 M')
    expect(outside.text()).toMatch(/(^|\D)2(\D|$)/)
  })

  it('exports the CSV of the active filter through the same request and reloads the late statements only when asked', async () => {
    const downloads: string[] = []
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      downloads.push(this.download)
    })
    URL.createObjectURL = vi.fn(() => 'blob:moon-tax')
    URL.revokeObjectURL = vi.fn()
    respondWithCsv()
    const wrapper = await mountMoonTax({
      ...defaultRoutes(),
      [`GET ${statementsPath(CORPORATION_A)}`]: [DUE_STATEMENT],
      [`GET ${statementsPath(CORPORATION_A, '?late=true')}`]: [LATE_STATEMENT],
    })

    await present(wrapper, section(CORPORATION_A, '[data-export-csv]')).trigger('click')
    await flushPromises()
    await present(wrapper, section(CORPORATION_A, '[data-filter="late"]')).trigger('click')
    await flushPromises()
    const lateReference = statementCell(wrapper, 0, 'reference').text()
    await present(wrapper, section(CORPORATION_A, '[data-export-csv]')).trigger('click')
    await flushPromises()
    await present(wrapper, section(CORPORATION_A, '[data-filter="all"]')).trigger('click')
    await flushPromises()
    clickSpy.mockRestore()

    const exports = vi.mocked(authFetch).mock.calls.map(([input, init]) => ({ url: String(input), accept: new Headers(init?.headers).get('Accept') }))
    expect(exports).toHaveLength(2)
    expect(exports[0].url.endsWith(statementsPath(CORPORATION_A))).toBe(true)
    expect(exports[1].url.endsWith(statementsPath(CORPORATION_A, '?late=true'))).toBe(true)
    expect(exports.map(exported => exported.accept)).toEqual(['text/csv', 'text/csv'])
    expect(downloads).toEqual([`moon-tax-${CORPORATION_A}.csv`, `moon-tax-${CORPORATION_A}.csv`])
    expect(lateReference).toBe('TAX-2026-W43-F2HN')
    expect(countOf(`GET ${statementsPath(CORPORATION_A, '?late=true')}`)).toBe(1)
    expect(countOf(`GET ${statementsPath(CORPORATION_A)}`)).toBe(2)
  })
})

describe('MoonTaxTab statement actions', () => {
  it('offers the actions each status allows and marks a due statement paid before reloading the statements', async () => {
    const markPaidPath = `${statementsPath(CORPORATION_A)}/${DUE_STATEMENT.id}/mark-paid`
    const wrapper = await mountMoonTax({
      ...withStatements([DUE_STATEMENT, LATE_STATEMENT, PAID_STATEMENT, PROVISIONAL_STATEMENT, CANCELLED_STATEMENT, PARTIAL_STATEMENT, UNKNOWN_STATEMENT]),
      [`POST ${markPaidPath}`]: {},
    })
    const actionsOf = (action: string) => statementRows(wrapper).map(row => row.find(`[data-action="${action}"]`).exists())

    expect(actionsOf('mark-paid')).toEqual([true, true, false, false, false, true, false])
    expect(actionsOf('cancel')).toEqual([true, true, true, false, false, true, true])

    await statementRow(wrapper, 0).get('[data-action="mark-paid"]').trigger('click')
    await flushPromises()

    const lines = requestLines()
    expect(lines.slice(-2)).toEqual([`POST ${markPaidPath}`, `GET ${statementsPath(CORPORATION_A)}`])
    expect(requests().find(request => request.method === 'POST')?.body).toEqual({})
  })

  it('asks for a confirmation before cancelling and posts nothing until it is given', async () => {
    const cancelPath = `${statementsPath(CORPORATION_A)}/${DUE_STATEMENT.id}/cancel`
    const wrapper = await mountMoonTax({ ...withStatements([DUE_STATEMENT]), [`POST ${cancelPath}`]: {} })
    expect(statementRow(wrapper, 0).find('[data-action="confirm-cancel"]').exists()).toBe(false)

    await statementRow(wrapper, 0).get('[data-action="cancel"]').trigger('click')
    await flushPromises()

    expect(statementRow(wrapper, 0).find('[data-action="confirm-cancel"]').exists()).toBe(true)
    expect(requests().filter(request => request.method === 'POST')).toEqual([])

    await statementRow(wrapper, 0).get('[data-action="confirm-cancel"]').trigger('click')
    await flushPromises()

    expect(requestLines().slice(-2)).toEqual([`POST ${cancelPath}`, `GET ${statementsPath(CORPORATION_A)}`])
    expect(requests().find(request => request.method === 'POST')?.body).toEqual({})
  })
})

describe('MoonTaxTab synchronisation', () => {
  it('disables the sync button while it runs, shows its progress, reloads on completion and enables the button again on error', async () => {
    const syncPath = `${corporationPath(CORPORATION_A)}/sync`
    const wrapper = await mountMoonTax({ ...defaultRoutes(), [`POST ${syncPath}`]: { status: 'queued' } })
    const syncStore = useSyncStore()
    const syncButton = () => present(wrapper, section(CORPORATION_A, '[data-sync]'))
    const progress = (status: 'in_progress' | 'completed' | 'error', percent: number | null) => {
      syncStore.syncStatus['moon-tax'] = { syncType: 'moon-tax', status, progress: percent, message: null, data: null, timestamp: '2026-11-10T12:00:05+00:00' }
    }
    expect(syncButton().attributes('disabled')).toBeUndefined()

    await syncButton().trigger('click')
    progress('in_progress', 40)
    await flushPromises()

    expect(requests().filter(request => request.method === 'POST').map(request => [request.endpoint, request.body])).toEqual([[syncPath, {}]])
    expect(syncButton().attributes('disabled')).toBeDefined()
    expect(present(wrapper, section(CORPORATION_A, '[data-sync-progress]')).text()).toContain('40')

    progress('completed', 100)
    await flushPromises()

    expect(countOf(`GET ${corporationPath(CORPORATION_A)}`)).toBe(2)
    expect(countOf(`GET ${statementsPath(CORPORATION_A)}`)).toBe(2)
    expect(syncButton().attributes('disabled')).toBeUndefined()

    await syncButton().trigger('click')
    syncStore.syncStatus['moon-tax'] = { syncType: 'moon-tax', status: 'error', progress: null, message: 'ESI unavailable', data: null, timestamp: '2026-11-10T12:01:00+00:00' }
    await flushPromises()

    expect(requests().filter(request => request.method === 'POST')).toHaveLength(2)
    expect(syncButton().attributes('disabled')).toBeUndefined()
    expect(countOf(`GET ${corporationPath(CORPORATION_A)}`)).toBe(2)
  })
})
