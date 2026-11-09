import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const MATCH_JOBS_URL = `/industry/projects/${PROJECT_ID}/match-jobs`
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const MATCH_JOBS_LABEL = 'Match jobs'
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

function mountComponent(component: typeof JobsTable | typeof BomTable, myRole: GroupMemberRole, lines: BomItem[]): VueWrapper {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = projectAs(myRole)
  projectStore.bomItems = lines
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(component, { global: { plugins: [i18n] } })
}

function matchJobsButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find(button => button.text() === MATCH_JOBS_LABEL)
}

function callsTo(url: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledUrl]) => calledUrl === url).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('JobsTable linked jobs', () => {
  it('shows the matched runs and each linked job of a job line', () => {
    const wrapper = mountComponent(JobsTable, 'owner', [ISHTAR_JOB])

    expect(wrapper.get(MATCHED_RUNS).text()).toContain('5')
    const linkedJobs = wrapper.findAll(JOB_MATCH).map(linkedJob => linkedJob.text())
    expect(linkedJobs).toHaveLength(2)
    expect(linkedJobs[0]).toContain('Aria Solberg')
    expect(linkedJobs[0]).toContain('3')
    expect(linkedJobs[1]).toContain('Brann Holt')
    expect(linkedJobs[1]).toContain('2')
  })

  it('offers the match jobs button to the owner only', () => {
    expect({
      owner: matchJobsButton(mountComponent(JobsTable, 'owner', [ISHTAR_JOB])) !== undefined,
      admin: matchJobsButton(mountComponent(JobsTable, 'admin', [ISHTAR_JOB])) !== undefined,
      member: matchJobsButton(mountComponent(JobsTable, 'member', [ISHTAR_JOB])) !== undefined,
    }).toEqual({ owner: true, admin: false, member: false })
  })

  it('posts the matching, reloads the BOM and names the character whose jobs could not be synced', async () => {
    vi.mocked(apiRequest).mockImplementation((async (url: string) => {
      if (url === MATCH_JOBS_URL) {
        return {
          jobMatches: [],
          jobsCost: null,
          syncedCharacters: 1,
          failedCharacters: [{ characterName: 'Cyra Lune', reason: 'ESI token expired', statusCode: 403 }],
        }
      }
      return [ISHTAR_JOB]
    }) as never)
    const wrapper = mountComponent(JobsTable, 'owner', [ISHTAR_JOB])

    expect(matchJobsButton(wrapper)).toBeDefined()
    await matchJobsButton(wrapper)!.trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(MATCH_JOBS_URL, { method: 'POST', body: '{}' })
    expect(callsTo(BOM_URL)).toBe(1)
    expect(wrapper.text()).toContain('Cyra Lune')
    expect(wrapper.text()).toContain('ESI token expired')
  })
})

describe('BOM lines without linked job data', () => {
  it('renders no matched runs and no purchased quantity when the line does not carry them', () => {
    const jobWithoutMatching = { ...ISHTAR_JOB } as Partial<BomItem>
    delete jobWithoutMatching.matchedRuns
    delete jobWithoutMatching.jobMatches
    const tritaniumWithoutPurchases = { ...bomLine({ id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', requiredQuantity: 120_000 }) } as Partial<BomItem>
    delete tritaniumWithoutPurchases.purchasedQuantity
    const pyeriteWithPurchases = bomLine({ id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', requiredQuantity: 45_000, purchasedQuantity: 15_000 })

    const jobsTable = mountComponent(JobsTable, 'owner', [jobWithoutMatching as BomItem])
    const jobsRendering = { matchedRuns: jobsTable.findAll(MATCHED_RUNS).length, linkedJobs: jobsTable.findAll(JOB_MATCH).length }
    const bomTable = mountComponent(BomTable, 'owner', [tritaniumWithoutPurchases as BomItem, pyeriteWithPurchases])

    expect({
      ...jobsRendering,
      purchasedQuantities: bomTable.findAll(PURCHASED_QUANTITY).map(quantity => quantity.text().replace(/\D/g, '')),
    }).toEqual({ matchedRuns: 0, linkedJobs: 0, purchasedQuantities: ['15000'] })
  })
})
