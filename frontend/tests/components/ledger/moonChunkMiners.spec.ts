import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { ApiError, apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import type { CorporationMiningExtraction } from '@/composables/useCorporationMiningExtractions'
import { CORPORATION_A, NOW, READY_CHUNK, ROCK_HOLLOW_CHUNK, corporation, extraction, response } from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

interface ChunkMinerStatement {
  periodStart: string
  periodEnd: string
  reference: string | null
  status: string | null
  tax: number | null
  paid: number | null
  remaining: number | null
}

interface ChunkMiner {
  characterId: number
  characterName: string | null
  chunkTax: number | null
  unknownTypeIds: number[]
  statements: ChunkMinerStatement[]
}

interface ChunkMinersPayload {
  id: string
  taxBasis: string
  refineryOpen: boolean
  miners: ChunkMiner[]
}

const EXTRACTIONS_ENDPOINT = '/mining/corporation-extractions'
const MIRA_ID = 2112000102
const DAX_ID = 2112000101
const TOLA_ID = 2112000103
const XENOTIME_TYPE_ID = 45520
const HEADER = 'Mineurs de ce chunk, taxe estimée au prix actuel'
const NOT_ESTABLISHED = 'Relevé pas encore établi'
const DENIED = 'Réservé aux comptables de la corporation'
const NOT_CONFIGURED = 'Taxe lunaire non configurée pour cette raffinerie'
const NO_MINING = 'Aucun minage enregistré sur ce chunk'

const EXPLODED_TUESDAY_CHUNK = extraction({
  id: '0195f3a0-2740-7000-8000-000000000401',
  moonId: 40301021,
  extractionStartTime: '2026-10-30T19:00:00+00:00',
  chunkArrivalTime: '2026-11-03T19:00:00+00:00',
  naturalDecayTime: '2026-11-03T22:00:00+00:00',
  status: 'exploded',
})

const CHUNKS = [EXPLODED_TUESDAY_CHUNK, READY_CHUNK, ROCK_HOLLOW_CHUNK]

function statement(overrides: Partial<ChunkMinerStatement>): ChunkMinerStatement {
  return {
    periodStart: '2026-10-26T00:00:00+00:00',
    periodEnd: '2026-11-02T00:00:00+00:00',
    reference: 'TAX-2026-W44-QK7P',
    status: 'partial',
    tax: 500,
    paid: 300,
    remaining: 200,
    ...overrides,
  }
}

function miner(overrides: Partial<ChunkMiner>): ChunkMiner {
  return {
    characterId: DAX_ID,
    characterName: 'Dax Orlen',
    chunkTax: 500,
    unknownTypeIds: [],
    statements: [],
    ...overrides,
  }
}

function payload(miners: ChunkMiner[], overrides: Partial<ChunkMinersPayload> = {}): ChunkMinersPayload {
  return { id: READY_CHUNK.id, taxBasis: 'current_prices', refineryOpen: true, miners, ...overrides }
}

function minersPath(chunk: CorporationMiningExtraction): string {
  return `/mining/moon-tax/${CORPORATION_A}/chunks/${chunk.id}/miners`
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountTab(answers: Record<string, unknown> = {}) {
  setActivePinia(createPinia())
  const loaded = response(corporation({ extractions: CHUNKS }))
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === EXTRACTIONS_ENDPOINT) return loaded
    if (!(endpoint in answers)) throw new Error(`No answer prepared for ${endpoint}`)
    const answer = answers[endpoint]
    if (answer instanceof Error) throw answer
    return answer
  })
  const wrapper = mount(MoonChunksTab, {
    global: { plugins: [createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

type TabWrapper = Awaited<ReturnType<typeof mountTab>>

function present(wrapper: TabWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

function listToggle(wrapper: TabWrapper, chunk: CorporationMiningExtraction) {
  return wrapper.find(`[data-extraction-id="${chunk.id}"] [data-miners-toggle]`)
}

function calendarToggle(wrapper: TabWrapper, chunk: CorporationMiningExtraction) {
  return wrapper.find(`[data-chunk="${chunk.id}"] [data-miners-toggle]`)
}

async function openMiners(wrapper: TabWrapper, chunk: CorporationMiningExtraction) {
  await present(wrapper, `[data-extraction-id="${chunk.id}"] [data-miners-toggle]`).trigger('click')
  await flushPromises()
  return present(wrapper, '[data-miners], [data-miners-denied], [data-miners-error]')
}

function minerRows(wrapper: TabWrapper) {
  return wrapper.findAll('[data-miners] [data-miner-row]')
}

function minersRequests(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint.endsWith('/miners'))
}

describe('MoonChunksTab miners of a chunk access', () => {
  it('offers the miners of a ready and of an exploded chunk in the list, never of an extracting one', async () => {
    const wrapper = await mountTab()

    expect([listToggle(wrapper, EXPLODED_TUESDAY_CHUNK), listToggle(wrapper, READY_CHUNK), listToggle(wrapper, ROCK_HOLLOW_CHUNK)].map(toggle => toggle.exists())).toEqual([true, true, false])
  })

  it('offers the miners of a ready and of an exploded chunk in the calendar, never of an extracting one', async () => {
    const wrapper = await mountTab()

    await present(wrapper, '[data-view="week"]').trigger('click')

    expect([calendarToggle(wrapper, EXPLODED_TUESDAY_CHUNK), calendarToggle(wrapper, READY_CHUNK), calendarToggle(wrapper, ROCK_HOLLOW_CHUNK)].map(toggle => toggle.exists())).toEqual([true, true, false])
  })

  it('loads the miners of a chunk once, when its panel is opened, and once per chunk', async () => {
    const wrapper = await mountTab({
      [minersPath(READY_CHUNK)]: payload([miner({})]),
      [minersPath(EXPLODED_TUESDAY_CHUNK)]: payload([miner({})], { id: EXPLODED_TUESDAY_CHUNK.id }),
    })
    expect(minersRequests()).toEqual([])

    await openMiners(wrapper, READY_CHUNK)
    await listToggle(wrapper, READY_CHUNK).trigger('click')
    await listToggle(wrapper, READY_CHUNK).trigger('click')
    await flushPromises()
    expect(minersRequests()).toEqual([minersPath(READY_CHUNK)])

    await openMiners(wrapper, EXPLODED_TUESDAY_CHUNK)
    expect(minersRequests()).toEqual([minersPath(READY_CHUNK), minersPath(EXPLODED_TUESDAY_CHUNK)])
  })
})

describe('MoonChunksTab miners panel', () => {
  it('lists the miners of the chunk with their name or id and their chunk tax or its unknown types', async () => {
    const wrapper = await mountTab({
      [minersPath(READY_CHUNK)]: payload([
        miner({ characterId: MIRA_ID, characterName: 'Mira Venn', chunkTax: 640 }),
        miner({ characterId: DAX_ID, characterName: 'Dax Orlen', chunkTax: 500 }),
        miner({ characterId: TOLA_ID, characterName: null, chunkTax: null, unknownTypeIds: [XENOTIME_TYPE_ID] }),
      ]),
    })

    const panel = await openMiners(wrapper, READY_CHUNK)
    const rows = minerRows(wrapper).map(row => row.text())

    expect(panel.text()).toContain(HEADER)
    expect(rows).toHaveLength(3)
    expect([rows[0].includes('Mira Venn') && rows[0].includes('640'), rows[1].includes('Dax Orlen') && rows[1].includes('500')]).toEqual([true, true])
    expect([rows[2].includes(`#${TOLA_ID}`), rows[2].includes('inconnue'), rows[2].includes(String(XENOTIME_TYPE_ID))]).toEqual([true, true, true])
  })

  it('shows the statement of each period of a miner with its reference, status, paid and remaining, and says they cover the whole period', async () => {
    const wrapper = await mountTab({
      [minersPath(READY_CHUNK)]: payload([
        miner({
          statements: [
            statement({}),
            statement({ periodStart: '2026-11-02T00:00:00+00:00', periodEnd: '2026-11-09T00:00:00+00:00', reference: 'TAX-2026-W45-BC3D', status: 'due', tax: 450, paid: 0, remaining: 450 }),
          ],
        }),
        miner({
          characterId: MIRA_ID,
          characterName: 'Mira Venn',
          chunkTax: 640,
          statements: [statement({ periodStart: '2026-11-01T00:00:00+00:00', periodEnd: '2026-12-01T00:00:00+00:00', reference: 'TAX-2026-M11-ZR4T', status: 'due', tax: 640, paid: 0, remaining: 640 })],
        }),
      ]),
    })

    const panel = await openMiners(wrapper, READY_CHUNK)
    const daxStatements = minerRows(wrapper)[0].findAll('[data-miner-statement]')
    const miraStatements = minerRows(wrapper)[1].findAll('[data-miner-statement]')

    expect(daxStatements).toHaveLength(2)
    expect(daxStatements[0].text()).toMatch(/Relevé de la semaine du .*26/)
    expect(['TAX-2026-W44-QK7P', '300', '200', '500'].every(figure => daxStatements[0].text().includes(figure))).toBe(true)
    expect(daxStatements[0].find('[data-status]').attributes('data-status')).toBe('partial')
    expect(daxStatements[1].text()).toContain('TAX-2026-W45-BC3D')
    expect(daxStatements[1].find('[data-status]').attributes('data-status')).toBe('due')
    expect(miraStatements).toHaveLength(1)
    expect(miraStatements[0].text()).toMatch(/mois de novembre/)
    expect(panel.text()).toMatch(/période/i)
  })

  it('says a period has no statement yet instead of showing amounts or a status', async () => {
    const wrapper = await mountTab({
      [minersPath(READY_CHUNK)]: payload([miner({ statements: [statement({ reference: null, status: null, tax: null, paid: null, remaining: null })] })]),
    })

    await openMiners(wrapper, READY_CHUNK)
    const periodStatement = present(wrapper, '[data-miner-statement]')

    expect(periodStatement.text()).toContain(NOT_ESTABLISHED)
    expect(periodStatement.find('[data-status]').exists()).toBe(false)
    expect(periodStatement.text()).not.toMatch(/null|NaN|undefined/)
  })
})

describe('MoonChunksTab miners panel states', () => {
  it('tells a user who is not an accountant that the panel is reserved and shows no miner', async () => {
    const wrapper = await mountTab({
      [minersPath(READY_CHUNK)]: new ApiError('The Accountant role is required to manage the moon tax of corporation 98000001', 403),
    })

    await openMiners(wrapper, READY_CHUNK)

    expect(present(wrapper, '[data-miners-denied]').text()).toContain(DENIED)
    expect(wrapper.findAll('[data-miner-row]')).toHaveLength(0)
    expect(wrapper.find('[data-miners-error]').exists()).toBe(false)
  })

  it('shows an error banner when the roles are unreadable and never says there is no miner', async () => {
    const wrapper = await mountTab({
      [minersPath(READY_CHUNK)]: new ApiError('The corporation roles of Ilyra Voss could not be read', 503),
    })

    await openMiners(wrapper, READY_CHUNK)

    expect(present(wrapper, '[data-miners-error]').text().length).toBeGreaterThan(0)
    expect(wrapper.findAll('[data-miner-row]')).toHaveLength(0)
    expect(wrapper.find('[data-miners-denied]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain(NO_MINING)
  })

  it('says the moon tax is not configured for a refinery that is not open, never that nobody mined', async () => {
    const wrapper = await mountTab({ [minersPath(READY_CHUNK)]: payload([], { refineryOpen: false }) })

    const panel = await openMiners(wrapper, READY_CHUNK)

    expect(panel.text()).toContain(NOT_CONFIGURED)
    expect(panel.text()).not.toContain(NO_MINING)
    expect(wrapper.findAll('[data-miner-row]')).toHaveLength(0)
  })

  it('says nobody mined on the chunk only for an open refinery with an empty list', async () => {
    const wrapper = await mountTab({ [minersPath(READY_CHUNK)]: payload([]) })

    const panel = await openMiners(wrapper, READY_CHUNK)

    expect(panel.text()).toContain(NO_MINING)
    expect(panel.text()).not.toContain(NOT_CONFIGURED)
  })
})
