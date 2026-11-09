import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { apiRequest, ApiError, authFetch } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import { DENIED_CORPORATION, SYNCED_CORPORATION, response } from './moonChunksHarness'
import {
  ALT_DONATION,
  ATTRIBUTED_DONATION,
  CORPORATION_A,
  CORPORATION_B,
  DAX_ID,
  DUE_STATEMENT,
  LATE_STATEMENT,
  MINER_DONATION,
  MIRA_ID,
  NOW,
  PAID_STATEMENT,
  TOLA_ID,
  UNMATCHED_DONATION,
  attributePath,
  corporationPath,
  countOf,
  defaultRoutes,
  donationRow,
  donationRows,
  donationsPath,
  donationsSection,
  mountMoonTax,
  present,
  requestLines,
  requests,
  section,
  statementsPath,
  type MoonTaxWrapper,
} from './moonTaxHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

enableAutoUnmount(afterEach)

const ALL_DONATIONS = [ALT_DONATION, UNMATCHED_DONATION, MINER_DONATION, ATTRIBUTED_DONATION]
const MINERS = [DUE_STATEMENT, LATE_STATEMENT, PAID_STATEMENT]
const NOT_ATTRIBUTED = 'Not attributed'
const NO_REASON = '—'
const NOTHING_TO_ATTRIBUTE = 'No donation to attribute'
const NO_DONATION_IN_HISTORY = 'No donation in 90 days'
const NOT_FOUND_MESSAGE = 'The donation 7003 was not found for the corporation 98000001'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  vi.mocked(authFetch).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

function withDonations(donations: unknown, extra: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    ...defaultRoutes(),
    [`GET ${statementsPath(CORPORATION_A)}`]: MINERS,
    [`GET ${donationsPath(CORPORATION_A)}`]: donations,
    ...extra,
  }
}

function journalIds(wrapper: MoonTaxWrapper): string[] {
  return donationRows(wrapper).map(row => row.attributes('data-journal-id') ?? '')
}

function filterButton(wrapper: MoonTaxWrapper, filter: 'unmatched' | 'all') {
  return present(wrapper, section(CORPORATION_A, `[data-donation-filter="${filter}"]`))
}

function attributeSelect(wrapper: MoonTaxWrapper, journalId: number) {
  return donationRow(wrapper, journalId).get('[data-attribute-select]')
}

function attributeButton(wrapper: MoonTaxWrapper, journalId: number) {
  return donationRow(wrapper, journalId).get('[data-action="attribute"]')
}

describe('MoonTaxTab donations access', () => {
  it('loads the donations of a corporation whose access is granted and never asks for those of a denied corporation', async () => {
    const wrapper = await mountMoonTax({
      ...defaultRoutes(response(SYNCED_CORPORATION, DENIED_CORPORATION)),
      [`GET ${statementsPath(CORPORATION_A)}`]: MINERS,
      [`GET ${donationsPath(CORPORATION_A)}`]: [ALT_DONATION],
      [`GET ${corporationPath(CORPORATION_B)}`]: new ApiError('The Accountant role is required to manage the moon tax of corporation 98000002', 403),
    })

    expect(countOf(`GET ${donationsPath(CORPORATION_A)}`)).toBe(1)
    expect(requestLines().filter(line => line.includes(donationsPath(CORPORATION_B)))).toEqual([])
    expect(wrapper.findAll(donationsSection(CORPORATION_A))).toHaveLength(1)
    expect(wrapper.findAll(donationsSection(CORPORATION_B))).toHaveLength(0)
  })

  it('shows no donations section for a corporation whose roles could not be read', async () => {
    const wrapper = await mountMoonTax({
      ...defaultRoutes(response(SYNCED_CORPORATION)),
      [`GET ${corporationPath(CORPORATION_A)}`]: new ApiError('The corporation roles of Ilyra Voss could not be read', 503),
    })

    expect(wrapper.findAll(donationsSection(CORPORATION_A))).toHaveLength(0)
    expect(requestLines().filter(line => line.includes('/donations'))).toEqual([])
  })
})

describe('MoonTaxDonationsPanel list', () => {
  it('starts on the donations to attribute, the payers that are not miners, then lists them all', async () => {
    const wrapper = await mountMoonTax(withDonations(ALL_DONATIONS))

    expect([filterButton(wrapper, 'unmatched').attributes('aria-pressed'), filterButton(wrapper, 'all').attributes('aria-pressed')]).toEqual(['true', 'false'])
    expect(journalIds(wrapper)).toEqual(['7003', '7002'])

    await filterButton(wrapper, 'all').trigger('click')

    expect([filterButton(wrapper, 'unmatched').attributes('aria-pressed'), filterButton(wrapper, 'all').attributes('aria-pressed')]).toEqual(['false', 'true'])
    expect(journalIds(wrapper)).toEqual(['7003', '7002', '7001', '7004'])
  })

  it('shows the donor, the amount, the reason and the attribution of each donation with explicit fallbacks', async () => {
    const wrapper = await mountMoonTax(withDonations(ALL_DONATIONS))
    await filterButton(wrapper, 'all').trigger('click')

    const text = (journalId: number) => donationRow(wrapper, journalId).text()
    expect(text(7003)).toContain('2026-11-07 14:45')
    expect(text(7003)).toContain('Vex Orlen')
    expect(text(7003)).toContain('2.75 M')
    expect(text(7003)).toContain('moon rent')
    expect(text(7003)).toContain(NOT_ATTRIBUTED)
    expect(text(7002)).toContain('#2112000202')
    expect(text(7002)).toContain('1.25 M')
    expect(text(7002)).toContain(NO_REASON)
    expect(text(7002)).toContain(NOT_ATTRIBUTED)
    expect(text(7001)).toContain('Dax Orlen')
    expect(text(7001)).toContain('4.00 M')
    expect(text(7001)).toContain('weekly moon tax')
    expect(text(7004)).toContain('Mira Venn')
    expect(text(7004)).toContain('2026-10-21 08:00')
    expect(text(7004)).not.toContain(NOT_ATTRIBUTED)
  })

  it('says there is no donation to attribute while the miners donations stay behind the all filter', async () => {
    const wrapper = await mountMoonTax(withDonations([MINER_DONATION]))

    expect(present(wrapper, section(CORPORATION_A, '[data-donations-empty]')).text()).toContain(NOTHING_TO_ATTRIBUTE)
    expect(donationRows(wrapper)).toHaveLength(0)

    await filterButton(wrapper, 'all').trigger('click')

    expect(wrapper.find(section(CORPORATION_A, '[data-donations-empty]')).exists()).toBe(false)
    expect(journalIds(wrapper)).toEqual(['7001'])
  })

  it('says there is no donation over 90 days under the all filter when the list is empty', async () => {
    const wrapper = await mountMoonTax(withDonations([]))
    await filterButton(wrapper, 'all').trigger('click')

    expect(present(wrapper, section(CORPORATION_A, '[data-donations-empty]')).text()).toContain(NO_DONATION_IN_HISTORY)
    expect(wrapper.find(section(CORPORATION_A, '[data-donations-error]')).exists()).toBe(false)
  })

  it('shows an error banner and never the empty message when the donations cannot be loaded', async () => {
    const wrapper = await mountMoonTax(withDonations(new ApiError('Service unavailable', 503)))

    expect(wrapper.find(section(CORPORATION_A, '[data-donations-error]')).exists()).toBe(true)
    expect(wrapper.find(section(CORPORATION_A, '[data-donations-empty]')).exists()).toBe(false)
    expect(donationRows(wrapper)).toHaveLength(0)
  })

  it('shows an error banner and never the empty message when the answer is not a list', async () => {
    const wrapper = await mountMoonTax(withDonations({ donations: [] }))

    expect(wrapper.find(section(CORPORATION_A, '[data-donations-error]')).exists()).toBe(true)
    expect(wrapper.find(section(CORPORATION_A, '[data-donations-empty]')).exists()).toBe(false)
    expect(donationRows(wrapper)).toHaveLength(0)
  })
})

describe('MoonTaxDonationsPanel attribution', () => {
  it('offers the distinct miners of the loaded statements sorted by name with the id as fallback and preselects the attributed one', async () => {
    const wrapper = await mountMoonTax(withDonations([ALT_DONATION, ATTRIBUTED_DONATION], { [`GET ${statementsPath(CORPORATION_A)}`]: [...MINERS, DUE_STATEMENT] }))
    await filterButton(wrapper, 'all').trigger('click')

    const options = attributeSelect(wrapper, 7003)
      .findAll('option')
      .filter(option => option.attributes('value') !== '')
      .map(option => [option.attributes('value'), option.text()])
    expect(options).toEqual([
      [String(TOLA_ID), `#${TOLA_ID}`],
      [String(DAX_ID), 'Dax Orlen'],
      [String(MIRA_ID), 'Mira Venn'],
    ])
    expect((attributeSelect(wrapper, 7003).element as HTMLSelectElement).value).toBe('')
    expect((attributeSelect(wrapper, 7004).element as HTMLSelectElement).value).toBe(String(MIRA_ID))
  })

  it('keeps the attribute button disabled until a miner is chosen', async () => {
    const wrapper = await mountMoonTax(withDonations([ALT_DONATION]))
    expect(attributeButton(wrapper, 7003).attributes('disabled')).toBeDefined()

    await attributeSelect(wrapper, 7003).setValue(String(DAX_ID))

    expect(attributeButton(wrapper, 7003).attributes('disabled')).toBeUndefined()
  })

  it('posts the chosen character then reloads both the donations and the statements', async () => {
    const path = attributePath(CORPORATION_A, 7003)
    const wrapper = await mountMoonTax(withDonations([ALT_DONATION], { [`POST ${path}`]: {} }))
    await attributeSelect(wrapper, 7003).setValue(String(DAX_ID))

    await attributeButton(wrapper, 7003).trigger('click')
    await flushPromises()

    const lines = requestLines()
    const afterPost = lines.slice(lines.indexOf(`POST ${path}`) + 1).sort()
    expect(requests().filter(request => request.method === 'POST').map(request => [request.endpoint, request.body])).toEqual([[path, { characterId: DAX_ID }]])
    expect(afterPost).toEqual([`GET ${donationsPath(CORPORATION_A)}`, `GET ${statementsPath(CORPORATION_A)}`].sort())
  })

  it('shows the server message on the row and keeps the chosen miner when the attribution is refused', async () => {
    const path = attributePath(CORPORATION_A, 7003)
    const wrapper = await mountMoonTax(withDonations([ALT_DONATION, UNMATCHED_DONATION], { [`POST ${path}`]: new ApiError(NOT_FOUND_MESSAGE, 404) }))
    await attributeSelect(wrapper, 7003).setValue(String(DAX_ID))

    await attributeButton(wrapper, 7003).trigger('click')
    await flushPromises()

    expect(donationRow(wrapper, 7003).get('[data-donation-error]').text()).toContain(NOT_FOUND_MESSAGE)
    expect(donationRow(wrapper, 7002).find('[data-donation-error]').exists()).toBe(false)
    expect((attributeSelect(wrapper, 7003).element as HTMLSelectElement).value).toBe(String(DAX_ID))
    expect(countOf(`GET ${donationsPath(CORPORATION_A)}`)).toBe(1)
    expect(countOf(`GET ${statementsPath(CORPORATION_A)}`)).toBe(1)
  })
})

describe('MoonTaxDonationsPanel synchronisation', () => {
  it('reloads the donations when the moon tax sync completes', async () => {
    const wrapper = await mountMoonTax(withDonations([ALT_DONATION], { [`POST ${corporationPath(CORPORATION_A)}/sync`]: { status: 'queued' } }))
    const syncStore = useSyncStore()

    await present(wrapper, section(CORPORATION_A, '[data-sync]')).trigger('click')
    syncStore.syncStatus['moon-tax'] = { syncType: 'moon-tax', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-10T12:00:05+00:00' }
    await flushPromises()

    expect(countOf(`GET ${donationsPath(CORPORATION_A)}`)).toBe(2)
  })
})
