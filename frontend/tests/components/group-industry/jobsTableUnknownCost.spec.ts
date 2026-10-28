import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem } from '@/stores/group-industry/types'

function jobLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'job',
    typeId: 0,
    typeName: '',
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    remainingQuantity: 0,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'final',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 0,
    isFulfilled: false,
    ...overrides,
  }
}

const SABRE_JOB = jobLine({ id: 'job-sabre', typeId: 22456, typeName: 'Sabre', requiredQuantity: 10, remainingQuantity: 10, runs: 10 })
const PURIFIER_JOB = jobLine({ id: 'job-purifier', typeId: 12038, typeName: 'Purifier', requiredQuantity: 5, remainingQuantity: 5, runs: 5 })
const SABRE_BLUEPRINT_COPY = jobLine({
  id: 'job-sabre-copy',
  typeId: 22457,
  typeName: 'Sabre Blueprint',
  requiredQuantity: 10,
  remainingQuantity: 10,
  runs: 10,
  jobGroup: 'blueprint',
  activityType: 'copy',
  meLevel: null,
  teLevel: null,
})

function mountJobsTable(jobs: BomItem[]): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().bomItems = jobs
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(JobsTable, { global: { plugins: [i18n] } })
}

function totalEstCostOfTheFooter(wrapper: VueWrapper): DOMWrapper<Element> {
  const label = `${en.groupIndustry.bom.totalEstCost}:`
  const item = wrapper.findAll('div.text-sm').find(candidate => candidate.text().startsWith(label))
  if (item === undefined) throw new Error('footer total not found')
  return item
}

function valueOf(footerItem: DOMWrapper<Element>): string {
  return footerItem.text().slice(`${en.groupIndustry.bom.totalEstCost}:`.length).trim()
}

describe('JobsTable footer total', () => {
  it('shows an unknown total, not 0, when no job has an estimated total', () => {
    const footerItem = totalEstCostOfTheFooter(mountJobsTable([
      SABRE_BLUEPRINT_COPY,
      SABRE_JOB,
      PURIFIER_JOB,
    ]))

    expect(valueOf(footerItem)).toBe('—')
    expect(footerItem.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
  })

  it('keeps every job cost cell unknown when no job has an estimated total', () => {
    const wrapper = mountJobsTable([SABRE_BLUEPRINT_COPY, SABRE_JOB, PURIFIER_JOB])

    const costCells = wrapper.findAll('tbody tr').filter(row => row.findAll('td').length === 7).map(row => row.findAll('td')[6]!.text())
    expect(costCells).toEqual(['—', '—', '—'])
  })

  it('adds the estimated totals of the jobs when every job has one', () => {
    const footerItem = totalEstCostOfTheFooter(mountJobsTable([
      { ...SABRE_JOB, estimatedTotal: 100 },
      { ...PURIFIER_JOB, estimatedTotal: 200 },
    ]))

    expect(valueOf(footerItem)).toBe('300')
  })

  it('shows an unknown total as soon as one job has no estimated total', () => {
    const footerItem = totalEstCostOfTheFooter(mountJobsTable([
      { ...SABRE_JOB, estimatedTotal: 100 },
      PURIFIER_JOB,
    ]))

    expect(valueOf(footerItem)).toBe('—')
    expect(footerItem.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
  })

  it('shows an unknown total when only the first of several jobs has no estimated total', () => {
    const footerItem = totalEstCostOfTheFooter(mountJobsTable([
      SABRE_JOB,
      { ...PURIFIER_JOB, estimatedTotal: 240 },
      { ...SABRE_BLUEPRINT_COPY, estimatedTotal: 35 },
    ]))

    expect(valueOf(footerItem)).toBe('—')
  })

  it('shows a known total of 0 when every job costs 0', () => {
    const footerItem = totalEstCostOfTheFooter(mountJobsTable([
      { ...SABRE_JOB, estimatedTotal: 0 },
      { ...PURIFIER_JOB, estimatedTotal: 0 },
    ]))

    expect(valueOf(footerItem)).toBe('0')
    expect(footerItem.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(false)
  })
})
