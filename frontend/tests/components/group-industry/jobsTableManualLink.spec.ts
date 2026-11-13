import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject } from '@/stores/group-industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const AVAILABLE_JOBS_URL = `/industry/projects/${PROJECT_ID}/available-jobs`
const ISHTAR_LINE_ID = 'job-ishtar'
const LINK_JOB_URL = `/industry/projects/${PROJECT_ID}/bom/${ISHTAR_LINE_ID}/link-job`
const UNLINK_ARIA_URL = `/industry/projects/${PROJECT_ID}/job-matches/match-aria`
const OPEN_LINK_SCREEN = '[data-testid="link-job-open"]'
const AVAILABLE_JOB = '[data-testid="available-job"]'
const AVAILABLE_JOB_LINKED = '[data-testid="available-job-linked"]'
const AVAILABLE_JOB_LINK = '[data-testid="available-job-link"]'
const JOB_MATCH = '[data-testid="job-match"]'
const JOB_MATCH_UNLINK = '[data-testid="job-match-unlink"]'
const JOB_LINK_ERROR = '[data-testid="job-link-error"]'
const UNLINK_REFUSED = 'Job match belongs to a delivered job'

const ISHTAR_JOB: BomItem = {
  id: ISHTAR_LINE_ID,
  typeId: 12005,
  typeName: 'Ishtar',
  requiredQuantity: 10,
  fulfilledQuantity: 0,
  remainingQuantity: 10,
  fulfillmentPercent: 0,
  estimatedPrice: null,
  estimatedTotal: null,
  isJob: true,
  jobGroup: 'final',
  activityType: 'manufacturing',
  parentTypeId: null,
  meLevel: 10,
  teLevel: 20,
  runs: 10,
  isFulfilled: false,
  matchedRuns: 5,
  purchases: [],
  purchasedQuantity: 0,
  reservations: [],
  reservedQuantity: 0,
  freeQuantity: 0,
  jobMatches: [
    {
      id: 'match-aria',
      bomLineId: ISHTAR_LINE_ID,
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
      bomLineId: ISHTAR_LINE_ID,
      esiJobId: 480013,
      cost: 830_000,
      status: 'delivered',
      endDate: '2026-11-05T22:00:00+00:00',
      runs: 2,
      characterName: 'Brann Holt',
      matchedAt: '2026-11-06T08:00:00+00:00',
      facilityId: null,
      facilityName: null,
      manual: true,
    },
  ],
}

const AVAILABLE_JOBS = [
  {
    esiJobId: 9001,
    blueprintTypeId: 12006,
    productTypeId: 12005,
    productTypeName: 'Ishtar',
    runs: 4,
    cost: 910_000,
    status: 'active',
    startDate: '2026-11-06T06:00:00+00:00',
    endDate: '2026-11-07T06:00:00+00:00',
    characterName: 'Cyra Lune',
    linkedToBomLineId: null,
    linkedToBomLineName: null,
    matchId: null,
  },
  {
    esiJobId: 9002,
    blueprintTypeId: 12007,
    productTypeId: 12005,
    productTypeName: 'Ishtar',
    runs: 6,
    cost: 1_340_000,
    status: 'delivered',
    startDate: '2026-11-04T06:00:00+00:00',
    endDate: '2026-11-05T06:00:00+00:00',
    characterName: 'Dax Orlan',
    linkedToBomLineId: 'job-vexor',
    linkedToBomLineName: 'Vexor',
    matchId: 'match-dax',
  },
]

function projectAs(myRole: GroupMemberRole): GroupProject {
  return { id: PROJECT_ID, name: 'Ishtar batch', status: 'in_progress', myRole, items: [] } as unknown as GroupProject
}

function mountJobsTable(myRole: GroupMemberRole): VueWrapper {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = projectAs(myRole)
  projectStore.bomItems = [ISHTAR_JOB]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(JobsTable, { global: { plugins: [i18n], stubs: { teleport: true } } })
}

function callsTo(url: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledUrl]) => calledUrl === url).length
}

function rowOf(wrapper: VueWrapper, selector: string, text: string) {
  const row = wrapper.findAll(selector).find(candidate => candidate.text().includes(text))
  expect(row, `${selector} containing ${text}`).toBeDefined()
  return present(row)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (url: string) => {
    if (url === AVAILABLE_JOBS_URL) return AVAILABLE_JOBS
    if (url === BOM_URL) return [ISHTAR_JOB]
    return {}
  }) as never)
})

describe('JobsTable manual job link', () => {
  it('lists the available jobs of the project, flags the one already linked and links job 9001 to the line', async () => {
    const wrapper = mountJobsTable('owner')

    await wrapper.get(OPEN_LINK_SCREEN).trigger('click')
    await flushPromises()

    expect(callsTo(AVAILABLE_JOBS_URL)).toBe(1)
    expect(wrapper.findAll(AVAILABLE_JOB)).toHaveLength(2)
    expect(rowOf(wrapper, AVAILABLE_JOB, 'Cyra Lune').find(AVAILABLE_JOB_LINKED).exists()).toBe(false)
    expect(rowOf(wrapper, AVAILABLE_JOB, 'Dax Orlan').get(AVAILABLE_JOB_LINKED).text()).toContain('Vexor')

    await rowOf(wrapper, AVAILABLE_JOB, 'Cyra Lune').get(AVAILABLE_JOB_LINK).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(LINK_JOB_URL, { method: 'POST', body: JSON.stringify({ esiJobId: 9001 }) })
    expect(callsTo(BOM_URL)).toBe(1)
  })

  it('unlinks a job from the line then reloads the BOM, and shows the message of a refused unlink', async () => {
    const wrapper = mountJobsTable('owner')

    await rowOf(wrapper, JOB_MATCH, 'Aria Solberg').get(JOB_MATCH_UNLINK).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(UNLINK_ARIA_URL, { method: 'DELETE' })
    expect(callsTo(BOM_URL)).toBe(1)
    expect(wrapper.find(JOB_LINK_ERROR).exists()).toBe(false)

    vi.mocked(apiRequest).mockRejectedValue(new Error(UNLINK_REFUSED))
    await rowOf(wrapper, JOB_MATCH, 'Aria Solberg').get(JOB_MATCH_UNLINK).trigger('click')
    await flushPromises()

    expect(wrapper.get(JOB_LINK_ERROR).text()).toBe(UNLINK_REFUSED)
    expect(wrapper.get(JOB_LINK_ERROR).attributes('role')).toBe('alert')
    expect(callsTo(BOM_URL)).toBe(1)
  })

  it('offers the link and unlink actions to the owner only', () => {
    const actionsOf = (myRole: GroupMemberRole) => {
      const wrapper = mountJobsTable(myRole)
      return { link: wrapper.findAll(OPEN_LINK_SCREEN).length, unlink: wrapper.findAll(JOB_MATCH_UNLINK).length }
    }

    expect({ owner: actionsOf('owner'), admin: actionsOf('admin'), member: actionsOf('member') }).toEqual({
      owner: { link: 1, unlink: 2 },
      admin: { link: 0, unlink: 0 },
      member: { link: 0, unlink: 0 },
    })
  })
})
