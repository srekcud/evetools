import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { ChainJob, ChainNode, ChainStatus } from '@/stores/group-industry/types'
import { messageOf, type TestLocale } from '../../localeTexts'
import { chainNode } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-hammerhead-batch'
const JOBS_SYNCED_AT = '2026-11-20T10:00:00+00:00'
const MATCH_JOBS_ENDPOINT = `/industry/projects/${PROJECT_ID}/match-jobs`
const SLOTS_ENDPOINT = '/industry/slots'

const LAUNCHED_JOB: ChainJob = {
  esiJobId: 4_001,
  status: 'active',
  runs: 5,
  characterName: 'Ulla Voss',
  endDate: '2026-11-21T10:00:00+00:00',
  cost: 1_200_000,
  facilityName: 'Sotiyo Forge',
}

function jobLine(id: string, status: ChainStatus, jobs: ChainJob[] = []): ChainNode {
  return chainNode({
    id,
    stage: 'product',
    typeId: 11_529,
    typeName: 'Hammerhead I',
    activity: 'manufacturing',
    runs: 5,
    status,
    jobs,
  })
}

function answerWith(nodes: ChainNode[], jobsSyncedAt: string | null): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint) => {
    if (endpoint === SLOTS_ENDPOINT) return { jobsSyncedAt, jobsSyncIntervalSeconds: 1_800, timeline: [], characters: [] }
    if (endpoint === MATCH_JOBS_ENDPOINT) return { failedCharacters: [], failedLines: [] }
    if (endpoint === `/industry/projects/${PROJECT_ID}/reactions`) return { id: PROJECT_ID, steps: [] }
    if (endpoint === `/industry/projects/${PROJECT_ID}/bom`) return { items: [] }
    return { id: PROJECT_ID, nodes }
  })
}

async function mountChain(
  nodes: ChainNode[],
  options: { jobsSyncedAt?: string | null; canMatchJobs?: boolean; locale?: TestLocale } = {},
): Promise<VueWrapper> {
  const { jobsSyncedAt = JOBS_SYNCED_AT, canMatchJobs = false, locale = 'en' } = options
  answerWith(nodes, jobsSyncedAt)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProductionChain, { props: { projectId: PROJECT_ID, canMatchJobs }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

async function openTree(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')
}

function hintOf(wrapper: VueWrapper, nodeId: string): string | null {
  const hint = wrapper.find(`[data-testid="chain-row-${nodeId}"] [data-testid="chain-job-not-seen-hint"]`)
  return hint.exists() ? hint.text() : null
}

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([called]) => called === endpoint).length
}

describe('Production tab, a job launched in game is not seen yet', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it.each<ChainStatus>(['todo', 'missing_materials'])('hints that a launched job shows up after the next sync on a %s line without a linked job', async (status) => {
    const wrapper = await mountChain([jobLine('line', status)])
    await openTree(wrapper)

    expect(hintOf(wrapper, 'line')).toBe(messageOf('en', 'groupIndustry.chain.jobNotSeenHint'))
  })

  it('hints in French when the interface is in French', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')], { locale: 'fr' })
    await openTree(wrapper)

    expect(hintOf(wrapper, 'line')).toBe(messageOf('fr', 'groupIndustry.chain.jobNotSeenHint'))
  })

  it.each<ChainStatus>(['active', 'done', 'blocked', 'unknown'])('does not hint on a %s line', async (status) => {
    const wrapper = await mountChain([jobLine('line', status)])
    await openTree(wrapper)

    expect(hintOf(wrapper, 'line')).toBeNull()
  })

  it('does not hint on a todo line that already has a linked job', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo', [LAUNCHED_JOB])])
    await openTree(wrapper)

    expect(hintOf(wrapper, 'line')).toBeNull()
  })

  it('does not change the status shown on the line', async () => {
    const wrapper = await mountChain([jobLine('line', 'missing_materials')])
    await openTree(wrapper)

    expect(wrapper.get('[data-testid="chain-row-line"]').text()).toContain(messageOf('en', 'groupIndustry.chain.status.missing_materials'))
  })
})

describe('Production tab, jobs sync freshness', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it('shows when the industry jobs were last synced', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')])

    const label = wrapper.find('[data-testid="chain-jobs-synced-at"]')
    expect({ shown: label.exists(), unknown: label.exists() && label.text() === messageOf('en', 'groupIndustry.chain.jobsSync.unknown') }).toEqual({ shown: true, unknown: false })
  })

  it('says the sync is unknown when no job was ever synced', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')], { jobsSyncedAt: null })

    expect(wrapper.find('[data-testid="chain-jobs-synced-at"]').text()).toBe(messageOf('en', 'groupIndustry.chain.jobsSync.unknown'))
  })
})

describe('Production tab, Match jobs', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it('offers Match jobs to a user who may match jobs', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')], { canMatchJobs: true })

    expect(wrapper.find('[data-testid="chain-match-jobs"]').text()).toBe(messageOf('en', 'groupIndustry.jobs.matchJobs'))
  })

  it('does not offer Match jobs to a user who may not match jobs', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')], { canMatchJobs: false })

    expect(wrapper.find('[data-testid="chain-match-jobs"]').exists()).toBe(false)
  })

  it('matches the jobs of the project and reads the chain again', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')], { canMatchJobs: true })
    const chainReadsBefore = callsTo(`/industry/projects/${PROJECT_ID}/chain`)

    await wrapper.get('[data-testid="chain-match-jobs"]').trigger('click')
    await flushPromises()

    expect({ matches: callsTo(MATCH_JOBS_ENDPOINT), chainReadsAfter: callsTo(`/industry/projects/${PROJECT_ID}/chain`) - chainReadsBefore }).toEqual({ matches: 1, chainReadsAfter: 1 })
  })

  it('says why the match failed', async () => {
    const wrapper = await mountChain([jobLine('line', 'todo')], { canMatchJobs: true })
    vi.mocked(apiRequest).mockImplementation(async (endpoint) => {
      if (endpoint === MATCH_JOBS_ENDPOINT) throw new Error('ESI unavailable')
      return { id: PROJECT_ID, nodes: [jobLine('line', 'todo')] }
    })

    await wrapper.get('[data-testid="chain-match-jobs"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="chain-match-jobs-error"]').text()).toContain('ESI unavailable')
  })
})
