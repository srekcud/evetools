import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject, JobLocation, JobLocations, JobMatch } from '@/stores/group-industry/types'

const LOCATIONS_LINE = '[data-testid="job-match-locations"]'
const LOCATIONS_CHECK = '[data-testid="job-match-check"]'

function side(overrides: Partial<JobLocation>): JobLocation {
  return {
    locationId: 1_049_000_000_001,
    kind: 'container',
    name: 'ISHTAR-319',
    divisionName: 'Corporation Hangar 2',
    status: 'inside',
    unknownReason: null,
    ...overrides,
  }
}

function jobLineWith(locations: JobLocations | null): BomItem {
  const match: JobMatch = {
    id: 'match-aria',
    bomLineId: 'job-ishtar',
    esiJobId: 480012,
    cost: 1_250_000,
    status: 'delivered',
    endDate: '2026-11-05T22:00:00+00:00',
    runs: 3,
    characterName: 'Aria Solberg',
    matchedAt: '2026-11-06T08:00:00+00:00',
    facilityId: null,
    facilityName: null,
    manual: false,
    locations,
  }
  return {
    id: 'job-ishtar',
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
    jobMatches: [match],
    matchedRuns: 3,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 0,
  }
}

function mountWith(locations: JobLocations | null) {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: 'project-ishtar-batch', name: 'Ishtar batch', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
  projectStore.bomItems = [jobLineWith(locations)]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(JobsTable, { global: { plugins: [i18n] } })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('JobsTable job locations', () => {
  it('shows the entry and the exit of a linked job under the job with its check', () => {
    const wrapper = mountWith({
      blueprint: side({ name: 'Perso box', divisionName: null, status: 'outside' }),
      output: side({ kind: 'hangar', name: 'Jita IV - Moon 4', divisionName: null, status: 'outside' }),
    })

    const line = wrapper.get(LOCATIONS_LINE).text()
    expect(line).toContain('Perso box')
    expect(line).toContain('Jita IV - Moon 4')
    expect(line.indexOf('Perso box')).toBeLessThan(line.indexOf('Jita IV - Moon 4'))
    expect(wrapper.get(LOCATIONS_CHECK).attributes('data-check')).toBe('output-outside')
  })

  it('checks a job whose entry and exit are in the project container', () => {
    const wrapper = mountWith({ blueprint: side({}), output: side({}) })

    expect(wrapper.get(LOCATIONS_CHECK).attributes('data-check')).toBe('ok')
  })

  it('shows no line when the locations are not shared with the viewer', () => {
    const wrapper = mountWith(null)

    expect(wrapper.find(LOCATIONS_LINE).exists()).toBe(false)
  })
})
