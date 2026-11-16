import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import { apiRequest } from '@/services/api'
import { slotTrackerWithoutCharacters } from '../../industrySlotsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

function clockTime(isoDate: string): string {
  return new Date(isoDate).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
}

const EmptyView = { render: () => null }
const CADENCE = '[data-testid="jobs-sync-cadence"]'
const NEXT_SYNC = '[data-testid="jobs-sync-next"]'

const JOBS_SYNCED_AT = '2026-11-16T10:00:00+00:00'
const EXPECTED_NEXT_SYNC = '2026-11-16T10:30:00+00:00'
const TEN_MINUTES_AFTER_SYNC = '2026-11-16T10:10:00+00:00'
const FORTY_FIVE_MINUTES_AFTER_SYNC = '2026-11-16T10:45:00+00:00'
const JOBS_SYNC_INTERVAL_SECONDS = 2700

const TIMELINE_JOB = {
  jobId: 7_540_001,
  characterName: 'Ilyra Castell',
  productTypeId: 587,
  productTypeName: 'Rifter',
  activityType: 'manufacturing',
  runs: 5,
  timeLeftSeconds: 3_600,
  endDate: '2026-11-16T12:00:00+00:00',
  project: null,
}

const CHARACTER = {
  characterId: 2_150_001,
  characterName: 'Ilyra Castell',
  isMain: true,
  slots: {
    manufacturing: { used: 1, max: 10 },
    reaction: { used: 0, max: 5 },
    science: { used: 0, max: 8 },
  },
  skillsUnknown: false,
  jobsStale: false,
  jobs: [],
  freeSlots: [],
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountSlotsSection(jobsSyncedAt: string | null, now: string, intervalSeconds = 1800): Promise<VueWrapper> {
  vi.setSystemTime(new Date(now))
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/slots') {
      return Promise.resolve({
        ...slotTrackerWithoutCharacters([TIMELINE_JOB], jobsSyncedAt),
        characters: [CHARACTER],
        jobsSyncIntervalSeconds: intervalSeconds,
      })
    }
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: EmptyView }] })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustrySlotsSection, { global: { plugins: [i18n, router] } })
  await flushPromises()
  return wrapper
}

describe('IndustrySlotsSection jobs sync cadence', () => {
  it('states the cadence read from the scheduler and the next sync expected one interval after the last one', async () => {
    const wrapper = await mountSlotsSection(JOBS_SYNCED_AT, TEN_MINUTES_AFTER_SYNC)

    expect(wrapper.get(CADENCE).text()).toContain('every 30 minutes')
    expect(wrapper.get(NEXT_SYNC).text()).toContain(clockTime(EXPECTED_NEXT_SYNC))
  })

  it('follows the interval the API sends instead of a fixed text', async () => {
    const wrapper = await mountSlotsSection(JOBS_SYNCED_AT, TEN_MINUTES_AFTER_SYNC, JOBS_SYNC_INTERVAL_SECONDS)

    expect(wrapper.get(CADENCE).text()).toContain('every 45 minutes')
    expect(wrapper.get(NEXT_SYNC).text()).toContain(clockTime('2026-11-16T10:45:00+00:00'))
  })

  it('states the cadence without inventing a next sync when the jobs were never synced', async () => {
    const wrapper = await mountSlotsSection(null, TEN_MINUTES_AFTER_SYNC)

    expect(wrapper.get(CADENCE).text()).toContain('every 30 minutes')
    expect(wrapper.find(NEXT_SYNC).exists()).toBe(false)
  })

  it('states the cadence without a next sync already in the past', async () => {
    const wrapper = await mountSlotsSection(JOBS_SYNCED_AT, FORTY_FIVE_MINUTES_AFTER_SYNC)

    expect(wrapper.get(CADENCE).text()).toContain('every 30 minutes')
    expect(wrapper.find(NEXT_SYNC).exists()).toBe(false)
  })

  it('keeps the last sync label of the page', async () => {
    const wrapper = await mountSlotsSection(JOBS_SYNCED_AT, TEN_MINUTES_AFTER_SYNC)

    expect(wrapper.text()).toContain('Last sync')
  })
})
