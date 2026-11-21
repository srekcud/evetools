import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import { messageOf } from '../../localeTexts'
import { TRITANIUM } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-hammerhead-batch'
const JOBS_SYNC_ENDPOINT = `/industry/projects/${PROJECT_ID}/jobs-sync`
const MATCH_JOBS_ENDPOINT = `/industry/projects/${PROJECT_ID}/match-jobs`
const NOW = new Date('2026-11-21T12:00:00+00:00')
const SYNC_INTERVAL_SECONDS = 1_800
const MINUTE_MS = 60_000

type Installer = { name: string; isViewer: boolean; lastSyncedAt: string | null }

function minutesAgo(minutes: number): string {
  return new Date(NOW.getTime() - minutes * MINUTE_MS).toISOString()
}

function answerWith(installers: Installer[]): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint) => {
    if (endpoint === JOBS_SYNC_ENDPOINT) return { id: PROJECT_ID, intervalSeconds: SYNC_INTERVAL_SECONDS, installers }
    if (endpoint === MATCH_JOBS_ENDPOINT) return { failedCharacters: [], failedLines: [] }
    if (endpoint === `/industry/projects/${PROJECT_ID}/reactions`) return { id: PROJECT_ID, steps: [] }
    return { id: PROJECT_ID, nodes: [TRITANIUM] }
  })
}

async function mountChain(installers: Installer[], props: { canMatchJobs?: boolean; canLinkJobs?: boolean } = {}): Promise<VueWrapper> {
  answerWith(installers)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID, ...props }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function installerRows(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="chain-jobs-sync-installer"]').map(row => row.text())
}

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([called]) => called === endpoint).length
}

describe('Production tab, jobs sync per installer', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows one line per installer with the name and when the jobs were synced', async () => {
    const wrapper = await mountChain([
      { name: 'Aria Solberg', isViewer: false, lastSyncedAt: minutesAgo(10) },
      { name: 'Brann Holt', isViewer: true, lastSyncedAt: minutesAgo(40) },
    ])

    const rows = installerRows(wrapper)
    expect({ count: rows.length, first: rows[0]?.includes('Aria Solberg'), second: rows[1]?.includes('Brann Holt') }).toEqual({ count: 2, first: true, second: true })
  })

  it('announces the next sync only while it is still to come', async () => {
    const wrapper = await mountChain([
      { name: 'Aria Solberg', isViewer: false, lastSyncedAt: minutesAgo(10) },
      { name: 'Brann Holt', isViewer: true, lastSyncedAt: minutesAgo(40) },
    ])

    const nextLabel = messageOf('en', 'groupIndustry.chain.jobsSync.next').split('{time}')[0]
    const rows = installerRows(wrapper)
    expect({ upcoming: rows[0]?.includes(nextLabel), overdue: rows[1]?.includes(nextLabel) }).toEqual({ upcoming: true, overdue: false })
  })

  it('says the sync of an installer is unknown when it has no date', async () => {
    const wrapper = await mountChain([{ name: 'Aria Solberg', isViewer: false, lastSyncedAt: null }])

    expect(installerRows(wrapper)[0]).toContain(messageOf('en', 'groupIndustry.chain.jobsSync.unknown'))
  })

  it('says the sync is unknown when there is no installer to list', async () => {
    const wrapper = await mountChain([])

    expect(wrapper.get('[data-testid="chain-jobs-synced-at"]').text()).toBe(messageOf('en', 'groupIndustry.chain.jobsSync.unknown'))
  })

  it('offers the sync to a participant who may only link his own jobs', async () => {
    const wrapper = await mountChain([{ name: 'Brann Holt', isViewer: true, lastSyncedAt: minutesAgo(10) }], { canLinkJobs: true })

    expect(wrapper.find('[data-testid="chain-match-jobs"]').exists()).toBe(true)
  })

  it('does not offer the sync to a reader who may neither match nor link jobs', async () => {
    const wrapper = await mountChain([{ name: 'Brann Holt', isViewer: true, lastSyncedAt: minutesAgo(10) }])

    expect(wrapper.find('[data-testid="chain-match-jobs"]').exists()).toBe(false)
  })

  it('reads the jobs sync of the installers again after the sync', async () => {
    const wrapper = await mountChain([{ name: 'Brann Holt', isViewer: true, lastSyncedAt: minutesAgo(40) }], { canLinkJobs: true })
    const readsBefore = callsTo(JOBS_SYNC_ENDPOINT)

    await wrapper.get('[data-testid="chain-match-jobs"]').trigger('click')
    await flushPromises()

    expect({ matches: callsTo(MATCH_JOBS_ENDPOINT), readsAfter: callsTo(JOBS_SYNC_ENDPOINT) - readsBefore }).toEqual({ matches: 1, readsAfter: 1 })
  })
})
