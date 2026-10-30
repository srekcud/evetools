import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import { useSyncStore, type SyncProgress } from '@/stores/sync'
import type { CharacterSyncFailure } from '@/stores/industry/types'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FAILURES_BANNER = '[data-testid="last-jobs-sync-failures"]'
const ERROR_BANNER = '[data-testid="jobs-sync-error"]'
const EmptyView = { render: () => null }

const PILOT_FOUR = {
  characterId: 2150004, characterName: 'Pilot Four', isMain: true,
  slots: {
    manufacturing: { used: 4, max: 11 },
    reaction: { used: 0, max: 5 },
    science: { used: 2, max: 9 },
  },
  jobs: [], freeSlots: [], skillsUnknown: false, jobsStale: false,
}

const SLOT_TRACKER_RESPONSE = {
  globalKpis: {
    manufacturing: { used: 4, max: 11, percent: 36.4 },
    reaction: { used: 0, max: 5, percent: 0 },
    science: { used: 2, max: 9, percent: 22.2 },
  },
  characters: [PILOT_FOUR],
  timeline: [],
  skillsMayBeStale: false,
  unknownSkillsCharacterCount: 0,
}

const PILOT_FOUR_MISSING_SCOPE: CharacterSyncFailure = { characterName: 'Pilot Four', reason: 'missing_scope', statusCode: null }
const PILOT_FIVE_ESI_ERROR: CharacterSyncFailure = { characterName: 'Pilot Five', reason: 'esi_error', statusCode: 403 }
const PILOT_FIVE_ESI_UNAVAILABLE: CharacterSyncFailure = { characterName: 'Pilot Five', reason: 'esi_unavailable', statusCode: 503 }

function userJobsSyncEnd(failedCharacters: CharacterSyncFailure[], timestamp: string): SyncProgress {
  return {
    syncType: 'industry-jobs',
    status: 'completed',
    progress: 100,
    message: '2 active jobs, 1 ready to deliver',
    data: { total: 3, active: 2, ready: 1, failedCharacters },
    timestamp,
  }
}

const CHARACTER_JOBS_SYNC_END: SyncProgress = {
  syncType: 'industry-jobs',
  status: 'completed',
  progress: 100,
  message: '1 active jobs, 0 ready to deliver',
  data: { total: 1, active: 1, ready: 0 },
  timestamp: '2026-10-30T08:00:30+00:00',
}

async function receive(message: SyncProgress) {
  const syncStore = useSyncStore()
  syncStore.syncStatus = { ...syncStore.syncStatus, [message.syncType]: message }
  await flushPromises()
}

async function mountSlotsSection(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/slots') return Promise.resolve(SLOT_TRACKER_RESPONSE)
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: EmptyView },
      { path: '/characters', component: EmptyView },
    ],
  })
  const wrapper = mount(IndustrySlotsSection, { global: { plugins: [i18n, router] } })
  await flushPromises()
  return wrapper
}

function slotsRequestCount(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === '/industry/slots').length
}

function bannerLines(wrapper: VueWrapper, banner: string): string[] {
  return wrapper.find(banner).findAll('li').map(line => line.text())
}

function reconnectLinkTargets(wrapper: VueWrapper): (string | undefined)[] {
  return wrapper.find(FAILURES_BANNER).findAll('a').filter(link => link.text() === 'Reconnect characters').map(link => link.attributes('href'))
}

async function closeFailuresBanner(wrapper: VueWrapper) {
  const closeButton = wrapper.find(FAILURES_BANNER).findAll('button').find(button => button.attributes('aria-label') === en.common.actions.close)
  if (!closeButton) throw new Error('No close button with an accessible name')
  await closeButton.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('IndustrySlotsSection on the characters not synced by the last user jobs sync (issue 215)', () => {
  it('names the characters not synced by the last jobs sync with the plural title and a link to reconnect them', async () => {
    const wrapper = await mountSlotsSection()

    await receive(userJobsSyncEnd([PILOT_FOUR_MISSING_SCOPE, PILOT_FIVE_ESI_ERROR], '2026-10-30T08:00:05+00:00'))

    expect(slotsRequestCount()).toBe(2)
    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)
    expect(wrapper.find(FAILURES_BANNER).text()).toContain('Jobs not synced for 2 characters at the last sync, the jobs shown may be out of date:')
    expect(bannerLines(wrapper, FAILURES_BANNER)).toEqual([
      'Pilot Four: the token lacks a required ESI permission, reconnect the character',
      'Pilot Five: ESI error 403',
    ])
    expect(reconnectLinkTargets(wrapper)).toEqual(['/characters'])
  })

  it('removes the banner on a later jobs sync end without character not synced', async () => {
    const wrapper = await mountSlotsSection()

    await receive(userJobsSyncEnd([PILOT_FOUR_MISSING_SCOPE, PILOT_FIVE_ESI_ERROR], '2026-10-30T08:10:05+00:00'))

    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)

    await receive(userJobsSyncEnd([], '2026-10-30T08:40:05+00:00'))

    expect(wrapper.find(FAILURES_BANNER).exists()).toBe(false)
    expect(slotsRequestCount()).toBe(3)
  })

  it('keeps the banner on a later per-character jobs sync end that carries no failed characters key', async () => {
    const wrapper = await mountSlotsSection()

    await receive(userJobsSyncEnd([PILOT_FOUR_MISSING_SCOPE, PILOT_FIVE_ESI_ERROR], '2026-10-30T08:20:05+00:00'))
    await receive(CHARACTER_JOBS_SYNC_END)

    expect(slotsRequestCount()).toBe(3)
    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, FAILURES_BANNER)).toEqual([
      'Pilot Four: the token lacks a required ESI permission, reconnect the character',
      'Pilot Five: ESI error 403',
    ])
  })

  it('closes the banner from its accessible close button and reopens it with only the next jobs sync failures', async () => {
    const wrapper = await mountSlotsSection()

    await receive(userJobsSyncEnd([PILOT_FOUR_MISSING_SCOPE, PILOT_FIVE_ESI_ERROR], '2026-10-30T08:30:05+00:00'))

    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)
    await closeFailuresBanner(wrapper)

    expect(wrapper.find(FAILURES_BANNER).exists()).toBe(false)

    await receive(userJobsSyncEnd([PILOT_FIVE_ESI_UNAVAILABLE], '2026-10-30T09:00:05+00:00'))

    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, FAILURES_BANNER)).toEqual(['Pilot Five: ESI unavailable (maintenance or server error), retry later'])
  })

  it('titles the banner in french with the singular for one character not synced', async () => {
    const wrapper = await mountSlotsSection('fr')

    await receive(userJobsSyncEnd([PILOT_FOUR_MISSING_SCOPE], '2026-10-30T09:10:05+00:00'))

    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)
    expect(wrapper.find(FAILURES_BANNER).text()).toContain('Jobs non synchronisés pour 1 personnage lors de la dernière synchronisation, les jobs affichés peuvent être périmés :')
  })

  it('shows the jobs sync error banner and the last jobs sync failures banner together', async () => {
    const wrapper = await mountSlotsSection()

    await receive({ syncType: 'industry-jobs', status: 'error', progress: null, message: 'boom A', data: null, timestamp: '2026-10-30T09:20:00+00:00' })
    await receive(userJobsSyncEnd([PILOT_FOUR_MISSING_SCOPE], '2026-10-30T09:20:05+00:00'))

    expect(wrapper.findAll(ERROR_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, ERROR_BANNER)).toEqual(['boom A'])
    expect(wrapper.findAll(FAILURES_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, FAILURES_BANNER)).toEqual(['Pilot Four: the token lacks a required ESI permission, reconnect the character'])
  })
})
