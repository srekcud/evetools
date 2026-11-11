import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BlueprintLibraryTab from '@/components/industry/BlueprintLibraryTab.vue'
import { useSyncStore, type SyncProgress } from '@/stores/sync'
import type { BlueprintLibrary, BlueprintLibraryEntry, CharacterSyncFailure } from '@/stores/industry/types'
import { apiRequest } from '@/services/api'
import { expectedMessage } from '../../expectedMessage'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LIBRARY_ENDPOINT = '/industry/blueprints'
const REFRESH_ENDPOINT = '/industry/blueprints/refresh'

const DRAKE_BPO: BlueprintLibraryEntry = {
  typeId: 1, name: 'Drake Blueprint', kind: 'bpo', me: 10, te: 20, runs: null,
  scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, count: 1, usedByRunningJob: false,
}
const RIFTER_BPO_STACK: BlueprintLibraryEntry = {
  typeId: 2, name: 'Rifter Blueprint', kind: 'bpo', me: 0, te: 0, runs: null,
  scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, count: 3, usedByRunningJob: false,
}
const HAMMERHEAD_BPC: BlueprintLibraryEntry = {
  typeId: 3, name: 'Hammerhead II Blueprint', kind: 'bpc', me: 2, te: 4, runs: 50,
  scope: 'corporation', ownerName: 'Stellar Corp', locationId: 1042000000001, divisionNumber: 3, count: 1, usedByRunningJob: false,
}
const UNNAMED_BPO: BlueprintLibraryEntry = {
  typeId: 99, name: 'unknown_type', kind: 'bpo', me: 1, te: 2, runs: null,
  scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, count: 1, usedByRunningJob: false,
}

const VEXOR_BPC_IN_JOB: BlueprintLibraryEntry = {
  typeId: 4, name: 'Vexor Blueprint', kind: 'bpc', me: 6, te: 12, runs: 25,
  scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, count: 1, usedByRunningJob: true,
}

const COMPOSITE_REACTION_FORMULA: BlueprintLibraryEntry = {
  typeId: 46166, name: 'Composite Reaction Formula', kind: 'bpr', me: 0, te: 0, runs: null,
  scope: 'personal', ownerName: 'Pilot Four', locationId: 60003760, divisionNumber: null, count: 1, usedByRunningJob: false,
}

function libraryOf(corporationState: BlueprintLibrary['corporationState'], blueprints: BlueprintLibraryEntry[]): BlueprintLibrary {
  return { corporationState, blueprints }
}

const ROWS = '[data-testid="blueprint-row"]'
const NO_DIRECTOR_BANNER = '[data-testid="blueprints-no-director"]'
const NO_VISIBILITY_BANNER = '[data-testid="blueprints-no-visibility"]'
const SYNC_ERROR_BANNER = '[data-testid="blueprints-sync-error"]'
const SYNC_FAILURES_BANNER = '[data-testid="blueprints-sync-failures"]'
const REFRESH_BUTTON = '[data-testid="blueprints-refresh"]'
const RETRY_BUTTON = '[data-testid="blueprints-retry"]'
const LOAD_ERROR = '[data-testid="blueprints-load-error"]'
const EMPTY = '[data-testid="blueprints-empty"]'

let libraryResponses: (BlueprintLibrary | Error)[] = []

function mockApi() {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === LIBRARY_ENDPOINT) {
      const next = libraryResponses.length > 1 ? libraryResponses.shift() : libraryResponses[0]
      return next instanceof Error ? Promise.reject(next) : Promise.resolve(next)
    }
    if (endpoint === REFRESH_ENDPOINT) return Promise.resolve({ status: 'pending', message: 'Blueprints sync started.' })
    return new Promise(() => {})
  })
}

async function mountTab(responses: (BlueprintLibrary | Error)[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  libraryResponses = [...responses]
  mockApi()
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(BlueprintLibraryTab, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function rowTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll(ROWS).map(row => row.text().replace(/\s+/g, ' '))
}

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([called]) => called === endpoint).length
}

async function receive(message: Partial<SyncProgress> & Pick<SyncProgress, 'status'>) {
  const syncStore = useSyncStore()
  const progress: SyncProgress = {
    syncType: 'blueprints', progress: null, message: null, data: null, timestamp: '2026-11-08T01:00:00+00:00', ...message,
  }
  syncStore.syncStatus = { ...syncStore.syncStatus, blueprints: progress }
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

const IN_USE_BADGE = '[data-testid="blueprint-in-use"]'

describe('BlueprintLibraryTab blueprint held by a job in progress (issue 411)', () => {
  it('flags only the row of a blueprint used by a job in progress', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, VEXOR_BPC_IN_JOB])])

    const rows = wrapper.findAll(ROWS)
    expect(rows[0].find(IN_USE_BADGE).exists()).toBe(false)
    expect(rows[1].find(IN_USE_BADGE).exists()).toBe(true)
    expect(rows[1].text()).toContain(expectedMessage('en', 'industry.blueprints.inUse'))
  })

  it('shows the in-use flag in French', async () => {
    const wrapper = await mountTab([libraryOf('known', [VEXOR_BPC_IN_JOB])], 'fr')

    expect(wrapper.find(IN_USE_BADGE).text()).toBe(expectedMessage('fr', 'industry.blueprints.inUse'))
  })
})

describe('BlueprintLibraryTab reaction formulas (issue 656)', () => {
  it('labels a reaction formula BPR and shows neither efficiency nor runs', async () => {
    const wrapper = await mountTab([libraryOf('known', [COMPOSITE_REACTION_FORMULA])])

    const row = rowTexts(wrapper)[0]
    expect(row).toContain('Composite Reaction Formula')
    expect(row).toContain(expectedMessage('en', 'industry.blueprints.kind.bpr'))
    expect(row).not.toContain('ME 0')
    expect(row).not.toContain('TE 0')
    expect(row).not.toContain(expectedMessage('en', 'industry.blueprints.unlimitedRuns'))
  })

  it('shows the BPR label in French and the original blueprint image of the formula', async () => {
    const wrapper = await mountTab([libraryOf('known', [COMPOSITE_REACTION_FORMULA])], 'fr')

    expect(rowTexts(wrapper)[0]).toContain(expectedMessage('fr', 'industry.blueprints.kind.bpr'))
    expect(wrapper.find(`${ROWS} img`).attributes('src')).toBe('https://images.evetech.net/types/46166/bp?size=32')
  })

  it('keeps efficiency and runs on originals and copies', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])])

    const rows = rowTexts(wrapper)
    expect(rows[0]).toContain('ME 10')
    expect(rows[0]).toContain('∞')
    expect(rows[1]).toContain('ME 2')
    expect(rows[1]).toContain('50')
  })
})

describe('BlueprintLibraryTab rows (issue 409)', () => {
  it('shows the blueprint image of an original and of a copy, never the type icon', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])])

    const sources = wrapper.findAll(`${ROWS} img`).map(image => image.attributes('src'))
    expect(sources).toEqual([
      'https://images.evetech.net/types/1/bp?size=32',
      'https://images.evetech.net/types/3/bpc?size=32',
    ])
  })

  it('shows kind, ME/TE, runs and owner of personal and corporation blueprints, infinite runs for an original', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])])

    const rows = rowTexts(wrapper)
    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Drake Blueprint')
    expect(rows[0]).toContain('BPO')
    expect(rows[0]).toContain('ME 10')
    expect(rows[0]).toContain('TE 20')
    expect(rows[0]).toContain('∞')
    expect(rows[0]).toContain('Pilot Four')
    expect(rows[1]).toContain('Hammerhead II Blueprint')
    expect(rows[1]).toContain('BPC')
    expect(rows[1]).toContain('ME 2')
    expect(rows[1]).toContain('50')
    expect(rows[1]).toContain('Division 3')
    expect(rows[1]).toContain(expectedMessage('en', 'industry.blueprints.corporation'))
  })

  it('shows the stack size of a pile of originals and nothing for a single one', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, RIFTER_BPO_STACK])])

    const rows = rowTexts(wrapper)
    expect(rows[0]).not.toContain('×')
    expect(rows[1]).toContain('×3')
  })

  it('shows the location identifier because the API gives no name', async () => {
    const wrapper = await mountTab([libraryOf('known', [HAMMERHEAD_BPC])])

    expect(rowTexts(wrapper)[0]).toContain('#1042000000001')
  })

  it('replaces the raw unknown_type marker by the unknown type name label', async () => {
    const wrapper = await mountTab([libraryOf('known', [UNNAMED_BPO])])

    const row = rowTexts(wrapper)[0]
    expect(row).not.toContain('unknown_type')
    expect(row).toContain('Unknown name (#99)')
  })

  it('shows the interface in French', async () => {
    const wrapper = await mountTab([libraryOf('no_director', [DRAKE_BPO])], 'fr')

    expect(wrapper.find(NO_DIRECTOR_BANNER).text()).toContain(expectedMessage('fr', 'industry.blueprints.noDirector.title'))
    expect(wrapper.find(NO_DIRECTOR_BANNER).text()).not.toContain('industry.blueprints')
  })
})

describe('BlueprintLibraryTab filter (issue 409)', () => {
  const MIXED = libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])

  it('lists every blueprint by default', async () => {
    const wrapper = await mountTab([MIXED])

    expect(wrapper.findAll(ROWS)).toHaveLength(2)
  })

  it('keeps only the personal blueprints, then only the corporation ones, without a new request', async () => {
    const wrapper = await mountTab([MIXED])

    await wrapper.get('[data-testid="blueprints-filter-personal"]').trigger('click')
    expect(rowTexts(wrapper)).toHaveLength(1)
    expect(rowTexts(wrapper)[0]).toContain('Drake Blueprint')

    await wrapper.get('[data-testid="blueprints-filter-corporation"]').trigger('click')
    expect(rowTexts(wrapper)).toHaveLength(1)
    expect(rowTexts(wrapper)[0]).toContain('Hammerhead II Blueprint')

    await wrapper.get('[data-testid="blueprints-filter-all"]').trigger('click')
    expect(wrapper.findAll(ROWS)).toHaveLength(2)
    expect(callsTo(LIBRARY_ENDPOINT)).toBe(1)
  })

  it('shows the empty state, not an error, when the filter matches nothing', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO])])

    await wrapper.get('[data-testid="blueprints-filter-corporation"]').trigger('click')

    expect(wrapper.findAll(ROWS)).toHaveLength(0)
    expect(wrapper.find(EMPTY).exists()).toBe(true)
    expect(wrapper.find(LOAD_ERROR).exists()).toBe(false)
  })
})

describe('BlueprintLibraryTab type filter (issue 657)', () => {
  const MIXED = libraryOf('known', [DRAKE_BPO, RIFTER_BPO_STACK, HAMMERHEAD_BPC, VEXOR_BPC_IN_JOB, COMPOSITE_REACTION_FORMULA])
  const TYPE_BUTTON = (kind: string) => `[data-testid="blueprints-type-${kind}"]`

  function namesShown(wrapper: VueWrapper): string[] {
    return rowTexts(wrapper).map(text => text.split(' ')[0])
  }

  it('selects every type by default and shows the number of blueprints of each type on its button', async () => {
    const wrapper = await mountTab([MIXED])

    expect(wrapper.findAll(ROWS)).toHaveLength(5)
    for (const kind of ['bpo', 'bpc', 'bpr']) {
      expect(wrapper.get(TYPE_BUTTON(kind)).attributes('aria-pressed')).toBe('true')
    }
    expect(wrapper.get(TYPE_BUTTON('bpo')).text()).toContain('4')
    expect(wrapper.get(TYPE_BUTTON('bpc')).text()).toContain('2')
    expect(wrapper.get(TYPE_BUTTON('bpr')).text()).toContain('1')
  })

  it('hides a type when its button is toggled off and brings it back when toggled on, without a new request', async () => {
    const wrapper = await mountTab([MIXED])

    await wrapper.get(TYPE_BUTTON('bpc')).trigger('click')
    expect(wrapper.get(TYPE_BUTTON('bpc')).attributes('aria-pressed')).toBe('false')
    expect(namesShown(wrapper)).toEqual(['Drake', 'Rifter', 'Composite'])

    await wrapper.get(TYPE_BUTTON('bpc')).trigger('click')
    expect(wrapper.findAll(ROWS)).toHaveLength(5)
    expect(callsTo(LIBRARY_ENDPOINT)).toBe(1)
  })

  it('shows only the types that stay selected', async () => {
    const wrapper = await mountTab([MIXED])

    await wrapper.get(TYPE_BUTTON('bpo')).trigger('click')
    await wrapper.get(TYPE_BUTTON('bpc')).trigger('click')

    expect(namesShown(wrapper)).toEqual(['Composite'])
  })

  it('keeps the last selected type selected', async () => {
    const wrapper = await mountTab([MIXED])

    await wrapper.get(TYPE_BUTTON('bpo')).trigger('click')
    await wrapper.get(TYPE_BUTTON('bpc')).trigger('click')
    await wrapper.get(TYPE_BUTTON('bpr')).trigger('click')

    expect(wrapper.get(TYPE_BUTTON('bpr')).attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll(ROWS)).toHaveLength(1)
  })

  it('combines with the personal and corporation filter, and the counts follow it', async () => {
    const wrapper = await mountTab([MIXED])

    await wrapper.get('[data-testid="blueprints-filter-corporation"]').trigger('click')
    expect(wrapper.get(TYPE_BUTTON('bpo')).text()).toContain('0')
    expect(wrapper.get(TYPE_BUTTON('bpc')).text()).toContain('1')
    expect(namesShown(wrapper)).toEqual(['Hammerhead'])

    await wrapper.get('[data-testid="blueprints-filter-personal"]').trigger('click')
    await wrapper.get(TYPE_BUTTON('bpo')).trigger('click')
    expect(namesShown(wrapper)).toEqual(['Vexor', 'Composite'])
  })

  it('shows the empty state when the selected types match nothing in the scope', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])])

    await wrapper.get(TYPE_BUTTON('bpr')).trigger('click')
    await wrapper.get(TYPE_BUTTON('bpo')).trigger('click')
    await wrapper.get('[data-testid="blueprints-filter-personal"]').trigger('click')

    expect(wrapper.findAll(ROWS)).toHaveLength(0)
    expect(wrapper.find(EMPTY).exists()).toBe(true)
  })

  it('labels the group of buttons in English and in French', async () => {
    const english = await mountTab([MIXED])
    const french = await mountTab([MIXED], 'fr')

    expect(english.get('[data-testid="blueprints-type-filter"]').attributes('aria-label')).toBe(expectedMessage('en', 'industry.blueprints.typeFilter'))
    expect(french.get('[data-testid="blueprints-type-filter"]').attributes('aria-label')).toBe(expectedMessage('fr', 'industry.blueprints.typeFilter'))
  })
})

describe('BlueprintLibraryTab corporation state (issue 409)', () => {
  it('warns that the corporation blueprints are unknown without a director and keeps the personal list', async () => {
    const wrapper = await mountTab([libraryOf('no_director', [DRAKE_BPO])])

    expect(wrapper.find(NO_DIRECTOR_BANNER).exists()).toBe(true)
    expect(wrapper.find(NO_DIRECTOR_BANNER).text()).toContain(expectedMessage('en', 'industry.blueprints.noDirector.title'))
    expect(wrapper.find(NO_VISIBILITY_BANNER).exists()).toBe(false)
    expect(wrapper.findAll(ROWS)).toHaveLength(1)
  })

  it('warns that no corporation division is visible and keeps the personal list', async () => {
    const wrapper = await mountTab([libraryOf('no_visibility', [DRAKE_BPO])])

    expect(wrapper.find(NO_VISIBILITY_BANNER).exists()).toBe(true)
    expect(wrapper.find(NO_VISIBILITY_BANNER).text()).toContain(expectedMessage('en', 'industry.blueprints.noVisibility.title'))
    expect(wrapper.find(NO_DIRECTOR_BANNER).exists()).toBe(false)
    expect(wrapper.findAll(ROWS)).toHaveLength(1)
  })

  it('shows no banner when the corporation blueprints are known', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])])

    expect(wrapper.find(NO_DIRECTOR_BANNER).exists()).toBe(false)
    expect(wrapper.find(NO_VISIBILITY_BANNER).exists()).toBe(false)
  })

  it('keeps the banner and shows the empty state when there is no blueprint at all', async () => {
    const wrapper = await mountTab([libraryOf('no_director', [])])

    expect(wrapper.find(NO_DIRECTOR_BANNER).exists()).toBe(true)
    expect(wrapper.find(EMPTY).exists()).toBe(true)
  })
})

describe('BlueprintLibraryTab refresh and Mercure (issue 409)', () => {
  it('queues a refresh through the API when the button is clicked', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO])])

    await wrapper.get(REFRESH_BUTTON).trigger('click')
    await flushPromises()

    expect(callsTo(REFRESH_ENDPOINT)).toBe(1)
    expect(vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === REFRESH_ENDPOINT)?.[1]).toMatchObject({ method: 'POST' })
  })

  it('disables the button while the blueprints sync runs and enables it again once completed', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO])])

    await receive({ status: 'started' })
    expect(wrapper.get(REFRESH_BUTTON).attributes('disabled')).toBeDefined()

    await receive({ status: 'completed', progress: 100 })
    expect(wrapper.get(REFRESH_BUTTON).attributes('disabled')).toBeUndefined()
  })

  it('reloads the library when the sync completes', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO]), libraryOf('known', [DRAKE_BPO, HAMMERHEAD_BPC])])

    expect(wrapper.findAll(ROWS)).toHaveLength(1)

    await receive({ status: 'completed', progress: 100, data: { characters: 1, corporations: 1, failedCharacters: [] } })

    expect(callsTo(LIBRARY_ENDPOINT)).toBe(2)
    expect(wrapper.findAll(ROWS)).toHaveLength(2)
  })

  it('does not reload the library while the sync is only in progress', async () => {
    await mountTab([libraryOf('known', [DRAKE_BPO])])

    await receive({ status: 'in_progress', progress: 50 })

    expect(callsTo(LIBRARY_ENDPOINT)).toBe(1)
  })

  it('names the characters not synced by the last sync', async () => {
    const failure: CharacterSyncFailure = { characterName: 'Pilot Five', reason: 'missing_scope', statusCode: null }
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO])])

    await receive({ status: 'completed', progress: 100, data: { failedCharacters: [failure] } })

    expect(wrapper.find(SYNC_FAILURES_BANNER).exists()).toBe(true)
    expect(wrapper.find(SYNC_FAILURES_BANNER).text()).toContain('Pilot Five')
  })

  it('shows the sync error message and keeps the previous list', async () => {
    const wrapper = await mountTab([libraryOf('known', [DRAKE_BPO])])

    await receive({ status: 'error', message: 'No blueprints could be synced' })

    expect(wrapper.find(SYNC_ERROR_BANNER).text()).toContain('No blueprints could be synced')
    expect(wrapper.findAll(ROWS)).toHaveLength(1)
  })
})

describe('BlueprintLibraryTab loading failure (issue 409)', () => {
  it('shows the read error with a retry button that loads the library again', async () => {
    const wrapper = await mountTab([new Error('Server exploded'), libraryOf('known', [DRAKE_BPO])])

    expect(wrapper.find(LOAD_ERROR).text()).toContain('Server exploded')
    expect(wrapper.findAll(ROWS)).toHaveLength(0)

    await wrapper.get(RETRY_BUTTON).trigger('click')
    await flushPromises()

    expect(wrapper.find(LOAD_ERROR).exists()).toBe(false)
    expect(wrapper.findAll(ROWS)).toHaveLength(1)
  })
})
