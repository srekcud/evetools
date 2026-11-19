import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject, JobMatch } from '@/stores/group-industry/types'

const PROJECT_ID = 'project-1013'
const jobLabels = en.groupIndustry.jobs

function componentJob(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'job-hydrogen-fuel-block',
    typeId: 4246,
    typeName: 'Hydrogen Fuel Block',
    requiredQuantity: 120,
    fulfilledQuantity: 0,
    remainingQuantity: 120,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'component',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 3,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 120,
    containerQuantity: null,
    containerStockState: 'known',
    ...overrides,
  }
}

function mountJobsTable(jobs: BomItem[], role: 'owner' | 'member' = 'member'): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Fuel batch', status: 'in_progress', myRole: role, solo: false, items: [] } as unknown as GroupProject
  projectStore.bomItems = jobs
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(JobsTable, { global: { plugins: [i18n] } })
}

function statusOfTheLine(wrapper: VueWrapper): string | undefined {
  return wrapper.find('[data-testid="job-status"]').attributes('data-status')
}

const deliveredMatch: JobMatch = {
  id: 'match-delivered',
  bomLineId: 'job-hydrogen-fuel-block',
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
}

describe('JobsTable component line covered by the project container', () => {
  it('shows a line fully covered by the container as covered, without the to launch status, the handle button or the quantity input', () => {
    const wrapper = mountJobsTable([componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true, fulfillmentPercent: 100 })])

    expect(statusOfTheLine(wrapper)).toBe('covered')
    expect(wrapper.text()).toContain(jobLabels.coveredByContainer)
    expect(wrapper.text()).not.toContain('To Launch')
    expect(wrapper.text()).not.toContain(en.groupIndustry.reservation.reserve)
    expect(wrapper.find('input[type="number"]').exists()).toBe(false)
  })

  it('shows a partly covered line as to launch with only the remaining quantity', () => {
    const wrapper = mountJobsTable([componentJob({ containerQuantity: 50, fulfilledQuantity: 50, remainingQuantity: 70, fulfillmentPercent: 41.7 })])

    expect(statusOfTheLine(wrapper)).toBe('to_launch')
    expect(wrapper.text()).toContain('Container 50 / 120, 70 left to launch')
    expect(wrapper.text()).not.toContain('Active')
  })

  it('keeps the current state of a line whose container stock is unknown and never treats it as covered', () => {
    const wrapper = mountJobsTable([componentJob({ containerQuantity: null, containerStockState: 'unknown' })])

    expect(statusOfTheLine(wrapper)).toBe('to_launch')
    expect(wrapper.text()).not.toContain(jobLabels.coveredByContainer)
  })

  it('keeps a line delivered by a linked job as delivered even if the container holds its product', () => {
    const wrapper = mountJobsTable([componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true, matchedRuns: 3, jobMatches: [deliveredMatch] })])

    expect(statusOfTheLine(wrapper)).toBe('delivered')
  })

  it('leaves a final product line untouched by the container', () => {
    const wrapper = mountJobsTable([componentJob({ id: 'job-ferox', jobGroup: 'final', containerStockState: 'none', containerQuantity: null })])

    expect(statusOfTheLine(wrapper)).toBe('to_launch')
  })

  it('counts the covered lines apart from the lines to launch in the header', () => {
    const wrapper = mountJobsTable([
      componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true }),
      componentJob({ id: 'job-nitrogen', typeId: 4051, typeName: 'Nitrogen Fuel Block', containerQuantity: 0 }),
    ])

    expect(wrapper.text()).toContain('1 to launch')
    expect(wrapper.text()).toContain('1 covered by container')
  })

  it('shows the unclaimed stock badge under a line covered by the container', () => {
    const wrapper = mountJobsTable([componentJob({ containerQuantity: 360, fulfilledQuantity: 120, remainingQuantity: 0, isFulfilled: true, unclaimedQuantity: 120, launchPrice: 2000, unclaimedValue: 240_000 })], 'owner')

    expect(wrapper.find('[data-testid="unclaimed-stock-badge"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="unclaimed-stock-assign"]').exists()).toBe(true)
  })
})
