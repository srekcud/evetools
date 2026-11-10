import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobContainersTable from '@/components/group-industry/JobContainersTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, JobLocation, JobLocations, JobMatch } from '@/stores/group-industry/types'

const ROW = '[data-testid="job-container-row"]'
const CHECK = '[data-testid="job-container-check"]'
const REASON = '[data-testid="job-container-reason"]'
const PROJECT_CONTAINER = 'ISHTAR-319'

function side(overrides: Partial<JobLocation>): JobLocation {
  return {
    locationId: 1_049_000_000_001,
    kind: 'container',
    name: PROJECT_CONTAINER,
    divisionName: 'Corporation Hangar 2',
    status: 'inside',
    unknownReason: null,
    ...overrides,
  }
}

function jobMatch(id: string, characterName: string, locations: JobLocations | null): JobMatch {
  return {
    id,
    bomLineId: 'job-ishtar',
    esiJobId: Number(id.replace(/\D/g, '')),
    cost: 1_250_000,
    status: 'delivered',
    endDate: '2026-11-05T22:00:00+00:00',
    runs: 3,
    characterName,
    matchedAt: '2026-11-06T08:00:00+00:00',
    facilityId: null,
    facilityName: null,
    manual: false,
    locations,
  }
}

function jobLine(jobMatches: JobMatch[]): BomItem {
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
    jobMatches,
    matchedRuns: 3,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 0,
  }
}

function mountWith(jobMatches: JobMatch[]) {
  const projectStore = useGroupProjectStore()
  projectStore.bomItems = [jobLine(jobMatches)]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(JobContainersTable, { global: { plugins: [i18n] } })
}

const texts = en.groupIndustry.jobLocations

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('JobContainersTable', () => {
  it('lists the pilot, the blueprint container and the output container of each linked job', () => {
    const wrapper = mountWith([
      jobMatch('match-1', 'Aria Solberg', {
        blueprint: side({ name: 'Perso box', divisionName: null, status: 'outside' }),
        output: side({ name: PROJECT_CONTAINER }),
      }),
    ])

    const row = wrapper.get(ROW).text()
    expect(row).toContain('Ishtar')
    expect(row).toContain('Aria Solberg')
    expect(row).toContain('Perso box')
    expect(row).toContain(PROJECT_CONTAINER)
  })

  it('checks the job when both containers are the one of the project', () => {
    const wrapper = mountWith([jobMatch('match-1', 'Aria Solberg', { blueprint: side({}), output: side({ name: 'Minerals' }) })])

    expect(wrapper.get(CHECK).attributes('data-check')).toBe('ok')
    expect(wrapper.get(CHECK).text()).toContain(texts.check.ok)
  })

  it('does not warn when the blueprint comes from outside the project container', () => {
    const wrapper = mountWith([jobMatch('match-1', 'Aria Solberg', { blueprint: side({ name: 'Perso box', status: 'outside' }), output: side({}) })])

    expect(wrapper.get(CHECK).attributes('data-check')).toBe('ok')
    expect(wrapper.get(CHECK).text()).toContain(texts.check.ok)
    expect(wrapper.get(ROW).text()).toContain('Perso box')
  })

  it('shows the blueprint container in neutral colour when it is outside and in grey when it is unknown', () => {
    const outside = mountWith([jobMatch('match-1', 'Aria Solberg', { blueprint: side({ name: 'Perso box', status: 'outside' }), output: side({}) })])
    const unknown = mountWith([
      jobMatch('match-1', 'Aria Solberg', { blueprint: side({ name: null, status: 'unknown', unknownReason: 'no_director' }), output: side({}) }),
    ])

    const blueprintCell = (wrapper: typeof outside) => wrapper.findAll(`${ROW} td`)[2]
    expect(blueprintCell(outside).classes()).not.toContain('text-amber-400')
    expect(blueprintCell(unknown).classes()).toContain('text-slate-500')
  })

  it('asks to move the products delivered outside the project container', () => {
    const wrapper = mountWith([
      jobMatch('match-1', 'Aria Solberg', { blueprint: side({}), output: side({ kind: 'hangar', name: 'Jita IV - Moon 4', divisionName: null, status: 'outside' }) }),
    ])

    expect(wrapper.get(CHECK).attributes('data-check')).toBe('output-outside')
    expect(wrapper.get(CHECK).text()).toContain(texts.check.outputOutside)
  })

  it('says the check is impossible with the reason when a location is unknown', () => {
    const wrapper = mountWith([
      jobMatch('match-1', 'Aria Solberg', {
        blueprint: side({}),
        output: side({ locationId: null, kind: null, name: null, divisionName: null, status: 'unknown', unknownReason: 'location_missing' }),
      }),
    ])

    expect(wrapper.get(CHECK).attributes('data-check')).toBe('unknown')
    expect(wrapper.get(CHECK).text()).toContain(texts.check.unknown)
    expect(wrapper.get(REASON).text()).toContain(texts.reasons.location_missing)
  })

  it('keeps the check impossible when the blueprint is outside and the output is unknown', () => {
    const wrapper = mountWith([
      jobMatch('match-1', 'Aria Solberg', {
        blueprint: side({ name: 'Perso box', status: 'outside' }),
        output: side({ locationId: null, kind: null, name: null, divisionName: null, status: 'unknown', unknownReason: 'location_missing' }),
      }),
    ])

    expect(wrapper.get(CHECK).attributes('data-check')).toBe('unknown')
  })

  it('keeps the move request when the other side is unknown', () => {
    const wrapper = mountWith([
      jobMatch('match-1', 'Aria Solberg', {
        blueprint: side({ status: 'unknown', unknownReason: 'no_director' }),
        output: side({ kind: 'hangar', name: 'Jita IV - Moon 4', divisionName: null, status: 'outside' }),
      }),
    ])

    expect(wrapper.get(CHECK).attributes('data-check')).toBe('output-outside')
  })

  it('shows nothing for the links without locations and no table when none has any', () => {
    const none = mountWith([jobMatch('match-1', 'Aria Solberg', null)])
    expect(none.findAll(ROW)).toHaveLength(0)
    expect(none.find('table').exists()).toBe(false)

    const mixed = mountWith([
      jobMatch('match-1', 'Aria Solberg', null),
      jobMatch('match-2', 'Brann Holt', { blueprint: side({}), output: side({}) }),
    ])
    expect(mixed.findAll(ROW)).toHaveLength(1)
    expect(mixed.get(ROW).text()).toContain('Brann Holt')
  })
})
