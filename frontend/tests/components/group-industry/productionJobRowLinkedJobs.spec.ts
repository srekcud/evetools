import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject } from '@/stores/group-industry/types'
import { callsTo, chainNodeOfLine, mountProductionJobRows } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const MATCH_JOBS_URL = `/industry/projects/${PROJECT_ID}/match-jobs`
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const CHAIN_URL = `/industry/projects/${PROJECT_ID}/chain`
const MATCHED_RUNS = '[data-testid="matched-runs"]'
const JOB_MATCH = '[data-testid="job-match"]'
const PURCHASED_QUANTITY = '[data-testid="purchased-quantity"]'

function bomLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'line',
    typeId: 0,
    typeName: '',
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    remainingQuantity: 0,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

const ISHTAR_JOB = bomLine({
  id: 'job-ishtar',
  typeId: 12005,
  typeName: 'Ishtar',
  requiredQuantity: 10,
  remainingQuantity: 10,
  isJob: true,
  jobGroup: 'final',
  activityType: 'manufacturing',
  meLevel: 10,
  teLevel: 20,
  runs: 10,
  matchedRuns: 5,
  jobMatches: [
    {
      id: 'match-aria',
      bomLineId: 'job-ishtar',
      esiJobId: 480012,
      cost: 1_250_000,
      status: 'active',
      endDate: '2026-11-07T10:00:00+00:00',
      runs: 3,
      characterName: 'Aria Solberg',
      matchedAt: '2026-11-06T08:00:00+00:00',
      facilityId: 60003760,
      facilityName: 'Jita IV - Moon 4',
      manual: false,
    },
    {
      id: 'match-brann',
      bomLineId: 'job-ishtar',
      esiJobId: 480013,
      cost: 830_000,
      status: 'delivered',
      endDate: '2026-11-05T22:00:00+00:00',
      runs: 2,
      characterName: 'Brann Holt',
      matchedAt: '2026-11-06T08:00:00+00:00',
      facilityId: null,
      facilityName: null,
      manual: false,
    },
  ],
})

function projectAs(myRole: GroupMemberRole): GroupProject {
  return { id: PROJECT_ID, name: 'Ishtar batch', status: 'in_progress', myRole, items: [] } as unknown as GroupProject
}

function mountBomTable(myRole: GroupMemberRole, lines: BomItem[]): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = projectAs(myRole)
  projectStore.bomItems = lines
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n] } })
}

function mountRows(myRole: GroupMemberRole, lines: BomItem[], answers: Record<string, unknown> = {}): Promise<VueWrapper> {
  return mountProductionJobRows({ lines, projectId: PROJECT_ID, role: myRole, answers })
}

function matchJobsButton(wrapper: VueWrapper) {
  return wrapper.find('[data-testid="chain-match-jobs"]')
}

function matchJobsAnswer(failedCharacters: unknown[], failedLines: unknown[]): Record<string, unknown> {
  return { [MATCH_JOBS_URL]: { jobMatches: [], jobsCost: null, syncedCharacters: 1, failedCharacters, failedLines } }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Production job row linked jobs', () => {
  it('shows the matched runs and each linked job of a job line', async () => {
    const wrapper = await mountRows('owner', [ISHTAR_JOB])

    expect(wrapper.get(MATCHED_RUNS).text()).toContain('5')
    const linkedJobs = wrapper.findAll(JOB_MATCH).map(linkedJob => linkedJob.text())
    expect(linkedJobs).toHaveLength(2)
    expect(linkedJobs[0]).toContain('Aria Solberg')
    expect(linkedJobs[0]).toContain('3')
    expect(linkedJobs[1]).toContain('Brann Holt')
    expect(linkedJobs[1]).toContain('2')
  })

  it('offers the match jobs button to the owner only', async () => {
    expect({
      owner: matchJobsButton(await mountRows('owner', [ISHTAR_JOB])).exists(),
      admin: matchJobsButton(await mountRows('admin', [ISHTAR_JOB])).exists(),
      member: matchJobsButton(await mountRows('member', [ISHTAR_JOB])).exists(),
    }).toEqual({ owner: true, admin: false, member: false })
  })

  it('posts the matching, reloads the BOM and the chain and names the character whose jobs could not be synced', async () => {
    const wrapper = await mountRows('owner', [ISHTAR_JOB], matchJobsAnswer([{ characterName: 'Cyra Lune', reason: 'ESI token expired', statusCode: 403 }], []))

    expect(matchJobsButton(wrapper).exists()).toBe(true)
    await matchJobsButton(wrapper).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(MATCH_JOBS_URL, { method: 'POST', body: '{}' })
    expect(callsTo(BOM_URL)).toBe(1)
    expect(callsTo(CHAIN_URL)).toBe(2)
    expect(wrapper.text()).toContain('Cyra Lune')
    expect(wrapper.text()).toContain('ESI token expired')
  })

  it('lists the characters not synced with a readable reason and the lines not matched with their type and reason', async () => {
    const wrapper = await mountRows('owner', [ISHTAR_JOB], matchJobsAnswer(
      [{ characterName: 'Cyra Lune', reason: 'sync_failed', statusCode: null }],
      [
        { typeId: 12005, typeName: 'Ishtar', reason: 'producer_missing_from_sde' },
        { typeId: 11478, typeName: 'R.A.M.- Starship Tech', reason: 'no_runs' },
        { typeId: 16683, typeName: 'Ferrogel', reason: 'contribution_not_refreshed' },
      ],
    ))

    await matchJobsButton(wrapper).trigger('click')
    await flushPromises()

    const failedLines = wrapper.findAll('[data-testid="match-failed-line"]').map(line => line.text())
    expect(failedLines).toHaveLength(3)
    expect(failedLines[0]).toContain('Ishtar')
    expect(failedLines[0]).toContain('Blueprint or reaction missing from the SDE')
    expect(failedLines[1]).toContain('R.A.M.- Starship Tech')
    expect(failedLines[1]).toContain('No runs set on this line')
    expect(failedLines[2]).toContain('Ferrogel')
    expect(failedLines[2]).toContain('Contribution not recalculated')
    expect(wrapper.text()).toContain('Some lines could not be matched:')
    expect(wrapper.text()).toContain('Cyra Lune')
    expect(wrapper.text()).toContain('sync failed')
  })

  it('shows no failed lines panel when every line was matched', async () => {
    const wrapper = await mountRows('owner', [ISHTAR_JOB], matchJobsAnswer([], []))

    await matchJobsButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="match-failed-line"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Some lines could not be matched:')
    expect(wrapper.text()).not.toContain('Some characters could not be synced:')
  })

  it('shows the message of a refused matching and keeps the failure lists empty', async () => {
    const wrapper = await mountRows('owner', [ISHTAR_JOB])
    vi.mocked(apiRequest).mockRejectedValue(new Error('Match refused'))

    await matchJobsButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="chain-match-jobs-error"]').text()).toBe('Match refused')
    expect(wrapper.find('[data-testid="match-failed-line"]').exists()).toBe(false)
  })
})

describe('Production job row without a loaded BOM line', () => {
  it('lists the job node without any action and without crashing while the BOM lines are not loaded', async () => {
    const wrapper = await mountProductionJobRows({ lines: [], nodes: [chainNodeOfLine(ISHTAR_JOB)], projectId: PROJECT_ID, role: 'owner' })

    const row = wrapper.get('[data-testid="chain-row-job-ishtar"]')
    expect(row.text()).toContain('Ishtar')
    expect(row.find('[data-testid="link-job-open"]').exists()).toBe(false)
    expect(row.find(JOB_MATCH).exists()).toBe(false)
    expect(row.find(MATCHED_RUNS).exists()).toBe(false)
  })
})

describe('BOM lines without linked job data', () => {
  it('renders no matched runs and no purchased quantity when the line does not carry them', async () => {
    const jobWithoutMatching = { ...ISHTAR_JOB } as Partial<BomItem>
    delete jobWithoutMatching.matchedRuns
    delete jobWithoutMatching.jobMatches
    const tritaniumWithoutPurchases = { ...bomLine({ id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', requiredQuantity: 120_000 }) } as Partial<BomItem>
    delete tritaniumWithoutPurchases.purchasedQuantity
    const pyeriteWithPurchases = bomLine({ id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', requiredQuantity: 45_000, purchasedQuantity: 15_000 })

    const productionRows = await mountRows('owner', [jobWithoutMatching as BomItem])
    const jobsRendering = { matchedRuns: productionRows.findAll(MATCHED_RUNS).length, linkedJobs: productionRows.findAll(JOB_MATCH).length }
    const bomTable = mountBomTable('owner', [tritaniumWithoutPurchases as BomItem, pyeriteWithPurchases])

    expect({
      ...jobsRendering,
      purchasedQuantities: bomTable.findAll(PURCHASED_QUANTITY).map(quantity => quantity.text().replace(/\D/g, '')),
    }).toEqual({ matchedRuns: 0, linkedJobs: 0, purchasedQuantities: ['15000'] })
  })
})
