import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest, authFetch } from '@/services/api'
import MoonTaxTab from '@/components/ledger/moon-tax/MoonTaxTab.vue'
import type { CorporationMiningExtractionsResponse } from '@/composables/useCorporationMiningExtractions'
import { i18nPlugin } from './miningLedgerHarness'
import { CORPORATION_A, CORPORATION_B, SYNCED_CORPORATION, response } from './moonChunksHarness'

export { CORPORATION_A, CORPORATION_B }
export { i18nPlugin }

export const NOW = '2026-11-10T12:00:00Z'
export const ROCK_HOLLOW_STRUCTURE_ID = 1035000000101
export const DEEP_CORE_STRUCTURE_ID = 1035000000102
export const UNLISTED_STRUCTURE_ID = 1035000000999
export const DAX_ID = 2112000101
export const MIRA_ID = 2112000102
export const TOLA_ID = 2112000103
export const ALT_OF_DAX_ID = 2112000201
export const STRANGER_ALT_ID = 2112000202
export const PANDEMIC_LEGION_ID = 99000001
export const WALLET_SCOPE = 'esi-wallet.read_corporation_wallets.v1'

export interface MoonTaxAccess {
  ledger: string
  payments: string
}

export interface MoonTaxRefinerySetting {
  structureId: number
  openToAlliance: boolean
  period: string
  rateR4: number
  rateR8: number
  rateR16: number
  rateR32: number
  rateR64: number
  walletDivision: number
}

export interface MoonTaxCorporationPayload {
  corporationId: number
  refineries: MoonTaxRefinerySetting[]
  access: MoonTaxAccess
  observersSyncedAt: string | null
  walletSyncedAt: string | null
}

export interface MoonTaxStatementPayload {
  id: string | null
  corporationId: number
  structureId: number
  characterId: number
  characterName: string | null
  periodStart: string
  periodEnd: string
  reference: string | null
  tax: number | null
  paid: number
  remaining: number | null
  status: string
  outsideAlliance: boolean | null
  unknownTypeIds: number[]
  recordedCorporationId: number | null
  recordedCorporationName: string | null
  allianceId: number | null
  allianceName: string | null
  affiliationKnown: boolean
}

export interface MoonTaxDonationPayload {
  journalId: number
  corporationId: number
  date: string
  amount: number
  reason: string | null
  firstPartyId: number
  firstPartyName: string | null
  attributedCharacterId: number | null
  attributedCharacterName: string | null
  attributedAt: string | null
  payerIsMiner: boolean
}

export interface MoonTaxMinePayload {
  corporationId: number
  structureId: number
  characterId: number
  periodStart: string
  periodEnd: string
  reference: string | null
  tax: number | null
  paid: number
  remaining: number | null
  status: string
  outsideAlliance: boolean | null
  unknownTypeIds: number[]
}

export const ROCK_HOLLOW_SETTING: MoonTaxRefinerySetting = {
  structureId: ROCK_HOLLOW_STRUCTURE_ID,
  openToAlliance: true,
  period: 'monthly',
  rateR4: 5,
  rateR8: 7.5,
  rateR16: 10,
  rateR32: 12.5,
  rateR64: 15,
  walletDivision: 3,
}

export function moonTaxCorporation(overrides: Partial<MoonTaxCorporationPayload> = {}): MoonTaxCorporationPayload {
  return {
    corporationId: CORPORATION_A,
    refineries: [ROCK_HOLLOW_SETTING],
    access: { ledger: 'ok', payments: 'ok' },
    observersSyncedAt: '2026-11-10T11:00:00+00:00',
    walletSyncedAt: '2026-11-10T11:05:00+00:00',
    ...overrides,
  }
}

export function statement(overrides: Partial<MoonTaxStatementPayload>): MoonTaxStatementPayload {
  return {
    id: '0195f3a0-3000-7000-8000-000000000001',
    corporationId: CORPORATION_A,
    structureId: ROCK_HOLLOW_STRUCTURE_ID,
    characterId: DAX_ID,
    characterName: 'Dax Orlen',
    periodStart: '2026-10-26T00:00:00+00:00',
    periodEnd: '2026-11-02T00:00:00+00:00',
    reference: 'TAX-2026-W44-K9QM',
    tax: 6_000_000,
    paid: 0,
    remaining: 6_000_000,
    status: 'due',
    outsideAlliance: false,
    unknownTypeIds: [],
    recordedCorporationId: CORPORATION_A,
    recordedCorporationName: 'Rock Hollow Mining',
    allianceId: PANDEMIC_LEGION_ID,
    allianceName: 'Pandemic Legion',
    affiliationKnown: true,
    ...overrides,
  }
}

export const PARTIAL_STATEMENT = statement({
  id: '0195f3a0-3000-7000-8000-000000000002',
  reference: 'TAX-2026-W44-M3GS',
  paid: 4_000_000,
  remaining: 2_000_000,
  status: 'partial',
  outsideAlliance: true,
})

export const UNKNOWN_STATEMENT = statement({
  id: '0195f3a0-3000-7000-8000-000000000003',
  characterId: MIRA_ID,
  characterName: 'Mira Venn',
  reference: 'TAX-2026-W44-P4TZ',
  tax: null,
  remaining: null,
  status: 'unknown',
  outsideAlliance: true,
  unknownTypeIds: [1, 2],
})

export const CANCELLED_STATEMENT = statement({
  id: '0195f3a0-3000-7000-8000-000000000004',
  characterId: TOLA_ID,
  characterName: 'Tola Rask',
  reference: 'TAX-2026-W44-C7WD',
  tax: 9_000_000,
  paid: 1_000_000,
  remaining: 8_000_000,
  status: 'cancelled',
  outsideAlliance: true,
})

export const DUE_STATEMENT = statement({ id: '0195f3a0-3000-7000-8000-000000000011' })

export const LATE_STATEMENT = statement({
  id: '0195f3a0-3000-7000-8000-000000000012',
  characterId: MIRA_ID,
  characterName: 'Mira Venn',
  structureId: DEEP_CORE_STRUCTURE_ID,
  reference: 'TAX-2026-W43-F2HN',
  tax: 3_500_000,
  remaining: 3_500_000,
  status: 'late',
})

export const PAID_STATEMENT = statement({
  id: '0195f3a0-3000-7000-8000-000000000013',
  characterId: TOLA_ID,
  characterName: null,
  structureId: UNLISTED_STRUCTURE_ID,
  reference: 'TAX-2026-W43-R8VB',
  tax: 2_250_000,
  paid: 2_250_000,
  remaining: 0,
  status: 'paid',
})

export const PROVISIONAL_STATEMENT = statement({
  id: null,
  periodStart: '2026-11-09T00:00:00+00:00',
  periodEnd: '2026-11-16T00:00:00+00:00',
  reference: null,
  tax: 1_500_000,
  remaining: 1_500_000,
  status: 'provisional',
})

export const STRANGE_STATEMENT = statement({
  id: '0195f3a0-3000-7000-8000-000000000014',
  reference: 'TAX-2026-W42-J5XM',
  status: 'frozen_by_audit',
})

export function donation(overrides: Partial<MoonTaxDonationPayload>): MoonTaxDonationPayload {
  return {
    journalId: 7001,
    corporationId: CORPORATION_A,
    date: '2026-11-03T10:00:00+00:00',
    amount: 4_000_000,
    reason: 'weekly moon tax',
    firstPartyId: DAX_ID,
    firstPartyName: 'Dax Orlen',
    attributedCharacterId: null,
    attributedCharacterName: null,
    attributedAt: null,
    payerIsMiner: true,
    ...overrides,
  }
}

export const MINER_DONATION = donation({})

export const UNMATCHED_DONATION = donation({
  journalId: 7002,
  date: '2026-11-05T09:30:00+00:00',
  amount: 1_250_000,
  reason: null,
  firstPartyId: STRANGER_ALT_ID,
  firstPartyName: null,
  payerIsMiner: false,
})

export const ALT_DONATION = donation({
  journalId: 7003,
  date: '2026-11-07T14:45:00+00:00',
  amount: 2_750_000,
  reason: 'moon rent',
  firstPartyId: ALT_OF_DAX_ID,
  firstPartyName: 'Vex Orlen',
  payerIsMiner: false,
})

export const ATTRIBUTED_DONATION = donation({
  journalId: 7004,
  date: '2026-10-20T18:15:00+00:00',
  amount: 3_500_000,
  reason: null,
  firstPartyId: ALT_OF_DAX_ID,
  firstPartyName: 'Vex Orlen',
  attributedCharacterId: MIRA_ID,
  attributedCharacterName: 'Mira Venn',
  attributedAt: '2026-10-21T08:00:00+00:00',
  payerIsMiner: true,
})

export const MINE_DUE: MoonTaxMinePayload = {
  corporationId: CORPORATION_A,
  structureId: ROCK_HOLLOW_STRUCTURE_ID,
  characterId: DAX_ID,
  periodStart: '2026-10-26T00:00:00+00:00',
  periodEnd: '2026-11-02T00:00:00+00:00',
  reference: 'TAX-2026-W44-7F3K',
  tax: 6_000_000,
  paid: 0,
  remaining: 6_000_000,
  status: 'due',
  outsideAlliance: false,
  unknownTypeIds: [],
}

export const MINE_PROVISIONAL: MoonTaxMinePayload = {
  ...MINE_DUE,
  periodStart: '2026-11-09T00:00:00+00:00',
  periodEnd: '2026-11-16T00:00:00+00:00',
  reference: null,
  tax: 1_500_000,
  remaining: 1_500_000,
  status: 'provisional',
}

export const MINE_UNKNOWN: MoonTaxMinePayload = {
  ...MINE_DUE,
  corporationId: CORPORATION_B,
  structureId: DEEP_CORE_STRUCTURE_ID,
  characterId: MIRA_ID,
  reference: 'TAX-2026-W43-Q2XN',
  tax: null,
  remaining: null,
  status: 'unknown',
  unknownTypeIds: [3, 4],
}

export function corporationPath(corporationId: number): string {
  return `/mining/moon-tax/${corporationId}`
}

export function statementsPath(corporationId: number, query = ''): string {
  return `${corporationPath(corporationId)}/statements${query}`
}

export function donationsPath(corporationId: number): string {
  return `${corporationPath(corporationId)}/donations`
}

export function attributePath(corporationId: number, journalId: number): string {
  return `${donationsPath(corporationId)}/${journalId}/attribute`
}

export function defaultRoutes(extractions: CorporationMiningExtractionsResponse = response(SYNCED_CORPORATION)): Record<string, unknown> {
  return {
    'GET /mining/corporation-extractions': extractions,
    [`GET ${corporationPath(CORPORATION_A)}`]: moonTaxCorporation(),
    [`GET ${statementsPath(CORPORATION_A)}`]: [],
    [`GET ${donationsPath(CORPORATION_A)}`]: [],
  }
}

export interface Request {
  method: string
  endpoint: string
  body: unknown
}

export function requests(): Request[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => ({
    method: options?.method?.toUpperCase() ?? 'GET',
    endpoint,
    body: typeof options?.body === 'string' ? JSON.parse(options.body) : null,
  }))
}

export function requestLines(): string[] {
  return requests().map(request => `${request.method} ${request.endpoint}`)
}

export function countOf(line: string): number {
  return requestLines().filter(requested => requested === line).length
}

export function route(routes: Record<string, unknown>) {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    const key = `${options?.method?.toUpperCase() ?? 'GET'} ${endpoint}`
    if (!(key in routes)) throw new Error(`No route prepared for ${key}`)
    const handler = routes[key]
    if (handler instanceof Error) throw handler
    return typeof handler === 'function' ? handler(options) : handler
  })
}

export async function mountMoonTax(routes: Record<string, unknown> = defaultRoutes()) {
  setActivePinia(createPinia())
  route(routes)
  const wrapper = mount(MoonTaxTab, { global: { plugins: [i18nPlugin()] } })
  await flushPromises()
  return wrapper
}

export type MoonTaxWrapper = Awaited<ReturnType<typeof mountMoonTax>>

export function present(wrapper: MoonTaxWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

export function section(corporationId: number, selector = ''): string {
  return `[data-moon-tax-corporation="${corporationId}"] ${selector}`.trim()
}

export function statementRows(wrapper: MoonTaxWrapper, corporationId = CORPORATION_A) {
  return wrapper.findAll(section(corporationId, '[data-statement-row]'))
}

export function statementRow(wrapper: MoonTaxWrapper, index: number, corporationId = CORPORATION_A) {
  const found = statementRows(wrapper, corporationId)[index]
  expect(found, `statement row ${index}`).toBeDefined()
  return found
}

export function statementCell(wrapper: MoonTaxWrapper, index: number, name: string, corporationId = CORPORATION_A) {
  const found = statementRow(wrapper, index, corporationId).find(`[data-cell="${name}"]`)
  expect(found.exists(), `cell ${name} of row ${index}`).toBe(true)
  return found
}

export function donationsSection(corporationId = CORPORATION_A): string {
  return section(corporationId, '[data-donations]')
}

export function donationRows(wrapper: MoonTaxWrapper, corporationId = CORPORATION_A) {
  return wrapper.findAll(section(corporationId, '[data-donation-row]'))
}

export function donationRow(wrapper: MoonTaxWrapper, journalId: number, corporationId = CORPORATION_A) {
  const found = wrapper.find(section(corporationId, `[data-donation-row][data-journal-id="${journalId}"]`))
  expect(found.exists(), `donation row ${journalId}`).toBe(true)
  return found
}

export function mineRows(wrapper: MoonTaxWrapper) {
  return wrapper.findAll('[data-mine-row]')
}

export function mineRow(wrapper: MoonTaxWrapper, index: number) {
  const found = mineRows(wrapper)[index]
  expect(found, `mine row ${index}`).toBeDefined()
  return found
}

export function mineCell(wrapper: MoonTaxWrapper, index: number, name: string) {
  const found = mineRow(wrapper, index).find(`[data-cell="${name}"]`)
  expect(found.exists(), `cell ${name} of mine row ${index}`).toBe(true)
  return found
}

export function respondWithCsv(body = 'character;tax\n') {
  vi.mocked(authFetch).mockResolvedValue(new Response(body, { status: 200, headers: { 'Content-Type': 'text/csv' } }))
}
