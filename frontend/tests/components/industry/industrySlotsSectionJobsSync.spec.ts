import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import { useSyncStore, type SyncProgress } from '@/stores/sync'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BANNER = '[data-testid="jobs-sync-error"]'

const PILOT_ONE = {
  characterId: 2150001, characterName: 'Pilot One', isMain: true,
  slots: {
    manufacturing: { used: 2, max: 10 },
    reaction: { used: 1, max: 5 },
    science: { used: 3, max: 8 },
  },
  jobs: [], freeSlots: [], skillsUnknown: false, jobsStale: false,
}

const SLOT_TRACKER_RESPONSE = {
  globalKpis: {
    manufacturing: { used: 2, max: 10, percent: 20.0 },
    reaction: { used: 1, max: 5, percent: 20.0 },
    science: { used: 3, max: 8, percent: 37.5 },
  },
  characters: [PILOT_ONE],
  timeline: [],
  skillsMayBeStale: false,
  unknownSkillsCharacterCount: 0,
}

function jobsSync(status: SyncProgress['status'], progress: number | null, message: string, data: Record<string, unknown> | null, timestamp: string): SyncProgress {
  return { syncType: 'industry-jobs', status, progress, message, data, timestamp }
}

const PILOT_ONE_JOBS_SYNC_END = jobsSync('completed', 100, '3 active jobs, 1 ready to deliver', { total: 4, active: 3, ready: 1 }, '2026-10-29T09:00:05+00:00')
const PILOT_TWO_JOBS_SYNC_END = jobsSync('completed', 100, '5 active jobs, 2 ready to deliver', { total: 7, active: 5, ready: 2 }, '2026-10-29T09:00:11+00:00')
const PILOT_THREE_JOBS_SYNC_END = jobsSync('completed', 100, '0 active jobs, 0 ready to deliver', { total: 0, active: 0, ready: 0 }, '2026-10-29T09:00:17+00:00')
const JOBS_SYNC_STARTED = jobsSync('started', 0, 'Fetching industry jobs...', null, '2026-10-29T09:10:00+00:00')
const JOBS_SYNC_AT_30 = jobsSync('in_progress', 30, 'Fetching character jobs...', null, '2026-10-29T09:10:02+00:00')
const JOBS_SYNC_AT_60 = jobsSync('in_progress', 60, 'Matching jobs...', null, '2026-10-29T09:10:04+00:00')

function jobsSyncError(message: string, timestamp: string): SyncProgress {
  return jobsSync('error', null, message, null, timestamp)
}

async function receive(message: SyncProgress) {
  const syncStore = useSyncStore()
  syncStore.syncStatus = { ...syncStore.syncStatus, [message.syncType]: message }
  await flushPromises()
}

async function mountSlotsSection(locale: 'en' | 'fr' = 'en', syncStatusBeforeMount: SyncProgress | null = null): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/slots') return Promise.resolve(SLOT_TRACKER_RESPONSE)
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  if (syncStatusBeforeMount) {
    useSyncStore().syncStatus = { [syncStatusBeforeMount.syncType]: syncStatusBeforeMount }
  }
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(IndustrySlotsSection, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function slotsRequestCount(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === '/industry/slots').length
}

function bannerLines(wrapper: VueWrapper): string[] {
  return wrapper.find(BANNER).findAll('li').map(line => line.text())
}

async function closeBanner(wrapper: VueWrapper) {
  const closeButton = wrapper.find(BANNER).findAll('button').find(button => button.attributes('aria-label') === 'Close')
  if (!closeButton) throw new Error('No close button with an accessible name')
  await closeButton.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('IndustrySlotsSection on jobs sync events (issue 215)', () => {
  it('reloads the slots on every jobs sync end received after mount', async () => {
    await mountSlotsSection()

    await receive(PILOT_ONE_JOBS_SYNC_END)
    await receive(PILOT_TWO_JOBS_SYNC_END)
    await receive(PILOT_THREE_JOBS_SYNC_END)

    expect(slotsRequestCount()).toBe(4)
  })

  it('does not reload the slots on a started or in-progress jobs sync and shows no banner', async () => {
    const wrapper = await mountSlotsSection()

    await receive(JOBS_SYNC_STARTED)
    await receive(JOBS_SYNC_AT_30)
    await receive(JOBS_SYNC_AT_60)

    expect(slotsRequestCount()).toBe(1)
    expect(wrapper.find(BANNER).exists()).toBe(false)
  })

  it('shows a jobs sync error in a banner without reloading the slots', async () => {
    const wrapper = await mountSlotsSection()

    await receive(jobsSyncError('ESI unavailable (fictif)', '2026-10-29T09:20:00+00:00'))

    expect(slotsRequestCount()).toBe(1)
    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Industry jobs sync failed (1 error), the jobs shown may be out of date:')
    expect(bannerLines(wrapper)).toEqual(['ESI unavailable (fictif)'])
  })

  it('keeps the jobs sync error banner after a later jobs sync end that reloads the slots', async () => {
    const wrapper = await mountSlotsSection()

    await receive(jobsSyncError('boom A', '2026-10-29T09:30:00+00:00'))
    await receive(PILOT_TWO_JOBS_SYNC_END)

    expect(slotsRequestCount()).toBe(2)
    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper)).toEqual(['boom A'])
  })

  it('lists two jobs sync errors with the same text as two lines under the plural title', async () => {
    const wrapper = await mountSlotsSection()

    await receive(jobsSyncError('boom A', '2026-10-29T09:40:00+00:00'))
    await receive(jobsSyncError('boom A', '2026-10-29T09:40:07+00:00'))

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Industry jobs sync failed (2 errors), the jobs shown may be out of date:')
    expect(bannerLines(wrapper)).toEqual(['boom A', 'boom A'])
  })

  it('closes the banner from its accessible close button and reopens it with only the next error', async () => {
    const wrapper = await mountSlotsSection()

    await receive(jobsSyncError('boom A', '2026-10-29T09:50:00+00:00'))

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    await closeBanner(wrapper)

    expect(wrapper.find(BANNER).exists()).toBe(false)

    await receive(jobsSyncError('boom B', '2026-10-29T09:50:09+00:00'))

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Industry jobs sync failed (1 error), the jobs shown may be out of date:')
    expect(bannerLines(wrapper)).toEqual(['boom B'])
  })

  it('titles the jobs sync error banner in french', async () => {
    const wrapper = await mountSlotsSection('fr')

    await receive(jobsSyncError('boom A', '2026-10-29T10:00:00+00:00'))

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Échec de la synchronisation des jobs (1 erreur), les jobs affichés peuvent être périmés :')
    expect(bannerLines(wrapper)).toEqual(['boom A'])
  })

  it('does not reload the slots on a jobs sync end received after unmount', async () => {
    const wrapper = await mountSlotsSection()
    wrapper.unmount()

    await receive(PILOT_ONE_JOBS_SYNC_END)

    expect(slotsRequestCount()).toBe(1)
  })

  it('ignores a jobs sync end already received before mount', async () => {
    await mountSlotsSection('en', PILOT_THREE_JOBS_SYNC_END)

    expect(slotsRequestCount()).toBe(1)
  })
})
